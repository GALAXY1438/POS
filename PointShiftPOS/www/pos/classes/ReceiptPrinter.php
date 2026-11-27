<?php
/**
 * ReceiptPrinter — builds ESC/POS receipt data and sends it to a Windows
 * thermal printer through the spooler (RAW datatype), so the output does not
 * depend on browser print dialogs or driver HTML support.
 */
class ReceiptPrinter
{
    private const ESC = "\x1B";
    private const GS  = "\x1D";

    // ── Public API ──────────────────────────────────────────────────────────

    /** Load store settings as setting_key => setting_value (store-specific rows win). */
    public static function settings(int $storeId = 0): array
    {
        $db = Database::getInstance()->getConnection();
        $map = [];
        $st = $db->prepare("SELECT setting_key, setting_value FROM store_settings
                            ORDER BY (store_id IS NOT NULL) ASC, store_id ASC");
        $st->execute();
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $map[$row['setting_key']] = $row['setting_value'];
        }
        return $map;
    }

    /** Render a completed order as ESC/POS bytes. */
    public static function renderOrder(array $order, array $items, array $settings, string $cashierName = ''): string
    {
        $width  = self::paperWidth($settings);
        $sym    = trim((string)($settings['currency_symbol'] ?? '')) ?: 'KSh';
        if (strtoupper($sym) === 'KSH') $sym = 'KSh';
        $pay    = strtolower((string)($order['payment_method'] ?? 'cash'));
        $payLbl = $pay === 'mpesa' ? 'M-PESA SALE'
                : ($pay === 'cash' ? 'CASH SALE'
                : (in_array($pay, ['stripe', 'card'], true) ? 'CARD SALE' : strtoupper($pay) . ' SALE'));

        $out  = self::ESC . '@';                      // init
        $out .= self::header($settings, $width);
        $out .= self::left() . str_repeat('-', $width) . "\n";
        $out .= self::left() . self::c($payLbl, $width) . "\n";
        $out .= str_repeat('-', $width) . "\n";

        // Order meta
        $out .= self::left();
        $out .= self::lr('Order #: ' . ($order['order_number'] ?? ''), '', $width) . "\n";
        $ts = !empty($order['created_at']) ? strtotime($order['created_at']) : time();
        $out .= self::lr('Date: ' . date('d-M-Y H:i', $ts), '', $width) . "\n";
        if (($settings['receipt_show_cashier'] ?? '1') === '1' && $cashierName !== '') {
            $out .= self::lr('Cashier: ' . $cashierName, '', $width) . "\n";
        }
        $out .= self::lr('Payment: ' . strtoupper($pay), '', $width) . "\n";
        if (!empty($order['mpesa_ref'])) {
            $out .= self::lr('M-PESA Ref: ' . $order['mpesa_ref'], '', $width) . "\n";
        }
        if (!empty($order['status'])) {
            $out .= self::lr('Status: ' . strtoupper((string)$order['status']), '', $width) . "\n";
        }
        if (!empty($order['customer_name'])) {
            $out .= self::lr('Customer: ' . $order['customer_name'], '', $width) . "\n";
        }
        if ($pay === 'mpesa' && !empty($order['customer_phone'])) {
            $out .= self::lr('Mobile: ' . self::maskPhone((string)$order['customer_phone']), '', $width) . "\n";
        }

        // Items
        $out .= str_repeat('-', $width) . "\n";
        $out .= self::lr('ITEM', 'AMOUNT', $width) . "\n";
        $out .= str_repeat('-', $width) . "\n";

        $sumItems = 0.0;
        foreach ($items as $it) {
            $name = self::clean((string)($it['product_name'] ?? $it['name'] ?? 'Item'));
            $qty  = (float)($it['quantity'] ?? 1);
            $unit = (float)($it['unit_price'] ?? 0);
            $line = (float)($it['total_price'] ?? $qty * $unit);
            $sumItems += $line;

            foreach (self::wrap($name, $width) as $ln) {
                $out .= $ln . "\n";
            }
            $qtyTxt = (fmod($qty, 1.0) == 0.0 ? (string)(int)$qty : rtrim(rtrim(number_format($qty, 3, '.', ''), '0'), '.')) . ' x ' . self::money($unit);
            $out .= self::lr('  ' . $qtyTxt, self::money($line), $width) . "\n";
        }

        // Totals
        $discount = (float)($order['discount_amount'] ?? 0);
        $tax      = (float)($order['tax_amount'] ?? 0);
        $total    = (float)($order['total_amount'] ?? $sumItems);
        $subtotal = isset($order['subtotal']) ? (float)$order['subtotal'] : $sumItems;
        $paid     = (float)($order['amount_received'] ?? $total);

        $out .= str_repeat('-', $width) . "\n";
        $out .= self::lr('Subtotal', self::money($subtotal), $width) . "\n";
        if ($discount > 0) {
            $pct = (float)($order['discount_percent'] ?? 0);
            $lbl = 'Discount' . ($pct > 0 ? ' (' . rtrim(rtrim(number_format($pct, 2), '0'), '.') . '%)' : '');
            $out .= self::lr($lbl, '-' . self::money($discount), $width) . "\n";
        }
        if ($tax > 0) {
            $taxName = trim((string)($settings['tax_name'] ?? 'VAT')) ?: 'VAT';
            $out .= self::lr($taxName . ' incl.', self::money($tax), $width) . "\n";
        }
        $out .= str_repeat('=', $width) . "\n";
        $out .= self::bold(true) . self::lr('TOTAL', $sym . ' ' . self::money($total), $width) . self::bold(false) . "\n";
        if ($pay === 'cash') {
            $out .= self::lr('Paid', self::money($paid), $width) . "\n";
            $out .= self::lr('Change', self::money(max(0, $paid - $total)), $width) . "\n";
        }

        // Footer
        if (!empty($order['notes'])) {
            foreach (self::wrap('Note: ' . $order['notes'], $width) as $ln) {
                $out .= $ln . "\n";
            }
        }
        $out .= str_repeat('-', $width) . "\n";
        $footer = trim((string)($settings['receipt_footer'] ?? ''));
        if ($footer === '') $footer = 'Thank you for your business!';
        foreach (self::wrap($footer, $width) as $ln) {
            $out .= self::c($ln, $width) . "\n";
        }
        $out .= self::c('Powered by TechShift POS', $width) . "\n";
        $out .= self::c('Printed: ' . date('d-M-Y H:i:s'), $width) . "\n";

        $out .= self::ESC . 'd' . "\x03";              // feed 3 lines
        $out .= self::GS . 'V' . "\x42" . "\x03";      // partial cut
        return $out;
    }

    /** Render a configuration test receipt. */
    public static function renderTest(array $settings): string
    {
        $width = self::paperWidth($settings);
        $out  = self::ESC . '@';
        $out .= self::header($settings, $width);
        $out .= self::left() . str_repeat('-', $width) . "\n";
        $out .= self::c('*** TEST RECEIPT ***', $width) . "\n";
        $out .= str_repeat('-', $width) . "\n";
        $out .= 'Printer   : ' . (self::printerName($settings) ?: 'Windows default') . "\n";
        $out .= 'Paper     : ' . ($width === 32 ? '58 mm / 32 columns' : '80 mm / 48 columns') . "\n";
        $out .= 'Date/Time : ' . date('d-M-Y H:i:s') . "\n";
        $out .= 'Currency  : ' . (strtoupper(trim((string)($settings['currency_symbol'] ?? ''))) === 'KSH' ? 'KSh' : (($settings['currency_symbol'] ?? '') ?: 'KSh')) . "\n";
        $out .= str_repeat('-', $width) . "\n";
        $out .= "12345678901234567890123456789012345678901234567\n";
        $out .= self::c('If these lines are crisp, straight', $width) . "\n";
        $out .= self::c('and fully visible, the printer is ready.', $width) . "\n";
        $out .= self::bold(true) . self::c('TechShift POS', $width) . self::bold(false) . "\n";
        $out .= self::ESC . 'd' . "\x03";
        $out .= self::GS . 'V' . "\x42" . "\x03";
        return $out;
    }

    /**
     * Send bytes to the printer. Returns [success, printer, message].
     */
    public static function print(string $bytes, ?string $printer = null): array
    {
        if ($bytes === '') {
            return ['success' => false, 'printer' => '', 'message' => 'Nothing to print.'];
        }
        $printer = $printer !== null ? trim($printer) : '';
        $printer = str_replace('"', '', $printer);

        $tmp = tempnam(sys_get_temp_dir(), 'psrct');
        if ($tmp === false) {
            return ['success' => false, 'printer' => '', 'message' => 'Cannot create a temporary file.'];
        }
        file_put_contents($tmp, $bytes);

        $root   = dirname(__DIR__, 3); // package root
        $script = $root . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'print' . DIRECTORY_SEPARATOR . 'rawprint.ps1';
        $ps     = (getenv('SystemRoot') ?: 'C:\Windows')
                . DIRECTORY_SEPARATOR . 'System32' . DIRECTORY_SEPARATOR . 'WindowsPowerShell'
                . DIRECTORY_SEPARATOR . 'v1.0' . DIRECTORY_SEPARATOR . 'powershell.exe';

        $cmd = '"' . $ps . '" -NoProfile -NonInteractive -ExecutionPolicy Bypass -File "' . $script . '"'
             . ' -InputFile "' . $tmp . '"';
        if ($printer !== '') {
            $cmd .= ' -Printer "' . $printer . '"';
        }

        $lines = [];
        $code  = 1;
        @exec($cmd . ' 2>&1', $lines, $code);
        @unlink($tmp);

        $output = trim(implode("\n", $lines));
        if ($code === 0 && strpos($output, 'OK|') === 0) {
            $used = substr($output, 3);
            return ['success' => true, 'printer' => $used, 'message' => 'Receipt sent to ' . $used . '.'];
        }
        return [
            'success' => false,
            'printer' => $printer,
            'message' => $output !== '' ? $output : 'The printer did not accept the job.',
        ];
    }

    /** Installed printers: [['name'=>..., 'default'=>bool], ...] */
    public static function listPrinters(bool $force = false): array
    {
        static $cache = null;
        if ($cache !== null) return $cache;

        // The PowerShell query takes over a second; cache it on disk so only
        // the first settings load (or a forced refresh) pays that cost.
        $dataRoot  = getenv('TECHSHIFT_DATA_ROOT');
        $cacheBase = is_string($dataRoot) && trim($dataRoot) !== ''
            ? rtrim($dataRoot, "\\/")
            : dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'data';
        $cacheFile = $cacheBase . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'printers.json';
        if (!$force) {
            $raw = @file_get_contents($cacheFile);
            if (is_string($raw) && $raw !== '') {
                $saved = json_decode($raw, true);
                if (is_array($saved) && (time() - (int)($saved['at'] ?? 0)) < 600 && isset($saved['printers']) && is_array($saved['printers'])) {
                    return $cache = $saved['printers'];
                }
            }
        }

        $root   = dirname(__DIR__, 3);
        $script = $root . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'print' . DIRECTORY_SEPARATOR . 'listprinters.ps1';
        $ps     = (getenv('SystemRoot') ?: 'C:\Windows')
                . DIRECTORY_SEPARATOR . 'System32' . DIRECTORY_SEPARATOR . 'WindowsPowerShell'
                . DIRECTORY_SEPARATOR . 'v1.0' . DIRECTORY_SEPARATOR . 'powershell.exe';
        $lines  = [];
        $code   = 1;
        @exec('"' . $ps . '" -NoProfile -NonInteractive -ExecutionPolicy Bypass -File "' . $script . '" 2>&1', $lines, $code);

        $cache = [];
        foreach ($lines as $ln) {
            $parts = explode('|', trim($ln));
            if (count($parts) === 2) {
                $cache[] = ['name' => $parts[0], 'default' => $parts[1] === '1'];
            }
        }

        $dir = dirname($cacheFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        @file_put_contents($cacheFile, json_encode(['at' => time(), 'printers' => $cache], JSON_PRETTY_PRINT), LOCK_EX);

        return $cache;
    }

    public static function defaultPrinter(): string
    {
        foreach (self::listPrinters() as $p) {
            if ($p['default']) return $p['name'];
        }
        return '';
    }

    // ── Formatting helpers ──────────────────────────────────────────────────

    private static function header(array $s, int $width): string
    {
        $out  = self::center() . self::GS . '!' . "\x11" . self::bold(true);
        $name = self::clean((string)($s['store_name'] ?? 'TechShift POS'));
        if ($name === '') $name = 'TechShift POS';
        $out .= $name . "\n";
        $out .= self::bold(false) . self::GS . '!' . "\x00" . self::left();

        $lines = array_filter([
            $s['store_branch']  ?? '',
            $s['store_address'] ?? '',
            !empty($s['store_phone']) ? 'Tel: ' . $s['store_phone'] : '',
            $s['store_email']   ?? '',
            $s['receipt_header'] ?? '',
        ], fn($v) => trim((string)$v) !== '');
        foreach ($lines as $ln) {
            foreach (self::wrap(self::clean((string)$ln), $width) as $w) {
                $out .= self::c($w, $width) . "\n";
            }
        }
        $out .= self::left();
        return $out;
    }

    private static function paperWidth(array $settings): int
    {
        $w = (int)($settings['receipt_paper_width'] ?? 48);
        return $w === 32 ? 32 : 48;
    }

    private static function printerName(array $settings): string
    {
        return trim((string)($settings['receipt_printer'] ?? ''));
    }

    private static function clean(string $s): string
    {
        $s = trim(preg_replace('/\s+/u', ' ', $s));
        if ($s === '') return '';
        $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
        return $converted !== false ? $converted : preg_replace('/[^\x20-\x7E]/', '', $s);
    }

    /** Wrap to width, splitting long words (no mid-word cutting of words that fit). */
    private static function wrap(string $text, int $width): array
    {
        $text = self::clean($text);
        if ($text === '') return [''];
        $lines = [];
        $cur   = '';
        foreach (explode(' ', $text) as $word) {
            while (strlen($word) > $width) {
                if ($cur !== '') { $lines[] = $cur; $cur = ''; }
                $lines[] = substr($word, 0, $width);
                $word = substr($word, $width);
            }
            if ($cur === '') {
                $cur = $word;
            } elseif (strlen($cur) + 1 + strlen($word) <= $width) {
                $cur .= ' ' . $word;
            } else {
                $lines[] = $cur;
                $cur = $word;
            }
        }
        if ($cur !== '') $lines[] = $cur;
        return $lines;
    }

    /** Center text within width. */
    private static function c(string $text, int $width): string
    {
        $len = strlen($text);
        if ($len >= $width) return $text;
        return str_repeat(' ', intdiv($width - $len, 2)) . $text;
    }

    /** Left label + right value on one line of the given width. */
    private static function lr(string $left, string $right, int $width): string
    {
        $left  = self::clean($left);
        $right = self::clean($right);
        $rlen  = strlen($right);
        $maxL  = $width - $rlen - 1;
        if ($maxL < 1) {
            return substr($left, 0, $width);
        }
        if (strlen($left) > $maxL) {
            $left = substr($left, 0, $maxL);
        }
        $pad = $width - strlen($left) - $rlen;
        return $left . str_repeat(' ', max(1, $pad)) . $right;
    }

    private static function money(float $n): string
    {
        return number_format($n, 2, '.', ',');
    }

    private static function maskPhone(string $phone): string
    {
        $digits = preg_replace('/\D/', '', $phone);
        if (strlen($digits) < 6) return $digits;
        return substr($digits, 0, 3) . str_repeat('*', strlen($digits) - 5) . substr($digits, -2);
    }

    private static function center(): string { return self::ESC . 'a' . "\x01"; }
    private static function left(): string   { return self::ESC . 'a' . "\x00"; }
    private static function bold(bool $on): string { return self::ESC . 'E' . ($on ? "\x01" : "\x00"); }
}
