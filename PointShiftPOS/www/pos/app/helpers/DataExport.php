<?php
/**
 * Shared data export / import for Transactions and Inventory.
 *
 * Datasets: 'transactions' | 'inventory'
 * Formats : xlsx | csv | report (printable HTML used as the PDF report)
 */

class DataExport
{
    /* ── Column catalog ─────────────────────────────────────────────────── */

    public static function columns(string $dataset): array
    {
        if ($dataset === 'transactions') {
            return [
                'order_number'    => ['label' => 'Order #',         'text' => true],
                'created_at'      => ['label' => 'Date & time'],
                'cashier'         => ['label' => 'Cashier'],
                'customer_name'   => ['label' => 'Customer'],
                'customer_phone'  => ['label' => 'Customer phone',  'text' => true],
                'payment_method'  => ['label' => 'Payment method'],
                'status'          => ['label' => 'Status'],
                'subtotal'        => ['label' => 'Subtotal',        'money' => true],
                'discount_amount' => ['label' => 'Discount',        'money' => true],
                'tax_amount'      => ['label' => 'Tax',             'money' => true],
                'total_amount'    => ['label' => 'Total',           'money' => true],
                'amount_received' => ['label' => 'Received',        'money' => true],
                'mpesa_ref'       => ['label' => 'M-Pesa ref',      'text' => true],
                'item_count'      => ['label' => 'Items'],
                'store'           => ['label' => 'Store'],
                'notes'           => ['label' => 'Notes'],
            ];
        }

        // inventory
        return [
            'id'                  => ['label' => 'ID',            'text' => true],
            'name'                => ['label' => 'Product name'],
            'sku'                 => ['label' => 'SKU',           'text' => true],
            'barcode'             => ['label' => 'Barcode',       'text' => true],
            'brand'               => ['label' => 'Brand'],
            'category_name'       => ['label' => 'Category'],
            'price'               => ['label' => 'Selling price', 'money' => true],
            'buying_price'        => ['label' => 'Buying price',  'money' => true],
            'discount'            => ['label' => 'Discount %'],
            'stock_quantity'      => ['label' => 'Stock'],
            'low_stock_threshold' => ['label' => 'Low stock at'],
            'unit'                => ['label' => 'Unit'],
            'expiry'              => ['label' => 'Expiry'],
            'status'              => ['label' => 'Status'],
            'store_name'          => ['label' => 'Store'],
            'supplier_name'       => ['label' => 'Supplier'],
            'supplier_contact'    => ['label' => 'Supplier contact', 'text' => true],
            'created_at'          => ['label' => 'Added on'],
            'updated_at'          => ['label' => 'Updated on'],
        ];
    }

    public static function defaultColumns(string $dataset): array
    {
        $keys = array_keys(self::columns($dataset));
        if ($dataset === 'inventory') {
            // hide raw id / supplier dates by default
            $keys = array_values(array_diff($keys, ['id', 'supplier_contact']));
        }
        return $keys;
    }

    /** Header row for the CSV import template (matches the CSV importer). */
    public static function importHeaders(string $dataset): array
    {
        if ($dataset === 'transactions') {
            return ['order_number', 'created_at', 'payment_method', 'status', 'subtotal', 'discount_amount', 'tax_amount', 'total_amount', 'amount_received', 'customer_name', 'customer_phone', 'notes'];
        }
        return ['name', 'sku', 'barcode', 'brand', 'category', 'price', 'buying_price', 'stock_quantity', 'low_stock_threshold', 'unit', 'supplier_name', 'supplier_contact', 'expiry'];
    }

    /* ── Row fetching (respects the page's filters) ─────────────────────── */

    public static function rows(string $dataset, array $f, int $storeScope = 0): array
    {
        $db = Database::getInstance()->getConnection();
        return $dataset === 'transactions'
            ? self::transactionRows($db, $f, $storeScope)
            : self::inventoryRows($db, $f, $storeScope);
    }

    private static function transactionRows(PDO $db, array $f, int $storeScope): array
    {
        $where  = ['1=1'];
        $params = [];

        $storeId = (int)($f['store_id'] ?? 0);
        if ($storeScope > 0)      { $where[] = 'o.store_id = ?'; $params[] = $storeScope; }
        elseif ($storeId > 0)     { $where[] = 'o.store_id = ?'; $params[] = $storeId; }

        $search = trim((string)($f['search'] ?? ''));
        if ($search !== '') {
            $where[] = '(o.order_number LIKE ? OR u.first_name LIKE ? OR u.last_name LIKE ? OR u.username LIKE ?)';
            $like = "%{$search}%";
            array_push($params, $like, $like, $like, $like);
        }
        if (!empty($f['date_from']))  { $where[] = 'DATE(o.created_at) >= ?'; $params[] = $f['date_from']; }
        if (!empty($f['date_to']))    { $where[] = 'DATE(o.created_at) <= ?'; $params[] = $f['date_to']; }
        if (!empty($f['status']))     { $where[] = 'o.status = ?';            $params[] = $f['status']; }
        if (!empty($f['cashier']))    { $where[] = 'o.user_id = ?';           $params[] = (int)$f['cashier']; }
        if (!empty($f['pay_method'])) { $where[] = 'o.payment_method = ?';    $params[] = $f['pay_method']; }

        $sql = "SELECT o.order_number, o.created_at,
                       TRIM(COALESCE(u.first_name,'') || ' ' || COALESCE(u.last_name,'')) AS cashier,
                       u.username AS cashier_username,
                       o.customer_name, o.customer_phone,
                       o.payment_method, o.status,
                       o.subtotal, o.discount_amount, o.tax_amount,
                       o.total_amount, o.amount_received,
                       COALESCE(NULLIF(o.mpesa_ref, ''), o.mpesa_receipt) AS mpesa_ref,
                       COUNT(oi.id) AS item_count,
                       s.store_name AS store,
                       o.notes
                FROM orders o
                LEFT JOIN users u       ON o.user_id = u.id
                LEFT JOIN order_items oi ON o.id = oi.order_id
                LEFT JOIN stores s      ON o.store_id = s.id
                WHERE " . implode(' AND ', $where) . "
                GROUP BY o.id, u.id, s.store_name
                ORDER BY o.created_at DESC";

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return array_map(function ($r) {
            $cashier = trim((string)$r['cashier']);
            $r['cashier'] = $cashier !== '' ? $cashier : (string)($r['cashier_username'] ?? '');
            unset($r['cashier_username']);
            return $r;
        }, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    private static function inventoryRows(PDO $db, array $f, int $storeScope): array
    {
        $sql    = "SELECT p.*, c.name AS category_name, s.store_name AS store_name
                   FROM products p
                   LEFT JOIN categories c ON p.category_id = c.id
                   LEFT JOIN stores s     ON p.store_id = s.id
                   WHERE p.status = 'active'";
        $params = [];

        $storeId = $storeScope > 0 ? $storeScope : (int)($f['store_id'] ?? 0);
        if ($storeId > 0) { $sql .= ' AND p.store_id = ?'; $params[] = $storeId; }

        $search = trim((string)($f['search'] ?? ''));
        if ($search !== '') {
            $sql .= ' AND (p.name LIKE ? OR p.barcode LIKE ? OR p.sku LIKE ? OR p.brand LIKE ?)';
            $like = "%{$search}%";
            array_push($params, $like, $like, $like, $like);
        }
        if (!empty($f['category_id'])) { $sql .= ' AND p.category_id = ?'; $params[] = (int)$f['category_id']; }

        switch ($f['stock_filter'] ?? '') {
            case 'low_stock':    $sql .= ' AND p.stock_quantity <= p.low_stock_threshold AND p.stock_quantity > 0'; break;
            case 'out_of_stock': $sql .= ' AND p.stock_quantity = 0'; break;
            case 'in_stock':     $sql .= ' AND p.stock_quantity > p.low_stock_threshold'; break;
        }

        $sql .= ' ORDER BY p.name ASC';
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /* ── Value formatting ───────────────────────────────────────────────── */

    private static function value(array $row, string $key, array $def): string
    {
        $v = $row[$key] ?? '';
        if ($v === null) return '';

        if (!empty($def['money'])) return number_format((float)$v, 2, '.', '');

        if (in_array($key, ['created_at', 'updated_at'], true) && $v !== '') {
            $ts = strtotime((string)$v);
            return $ts ? date('Y-m-d H:i', $ts) : (string)$v;
        }
        return (string)$v;
    }

    /* ── Excel (.xlsx) ──────────────────────────────────────────────────── */

    public static function xlsx(string $dataset, array $cols, array $rows): string
    {
        $defs   = self::columns($dataset);
        $header = [];
        foreach ($cols as $k) $header[] = $defs[$k]['label'] ?? $k;

        $sheet  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
        $sheet .= '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';
        $sheet .= '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>';
        $sheet .= '<sheetData>';

        $sheet .= '<row r="1">';
        foreach ($header as $i => $label) {
            $ref = self::colLetter($i + 1) . '1';
            $sheet .= '<c r="' . $ref . '" s="1" t="inlineStr"><is><t>' . self::xml($label) . '</t></is></c>';
        }
        $sheet .= '</row>';

        $r = 2;
        foreach ($rows as $row) {
            $sheet .= '<row r="' . $r . '">';
            $i = 0;
            foreach ($cols as $key) {
                $def = $defs[$key] ?? [];
                $val = self::value($row, $key, $def);
                $ref = self::colLetter($i + 1) . $r;
                if ($val !== '' && empty($def['text']) && !isset($def['money']) && preg_match('/^-?\d+(\.\d+)?$/', $val) && strlen($val) < 15) {
                    $sheet .= '<c r="' . $ref . '"><v>' . $val . '</v></c>';
                } elseif ($val !== '' && !empty($def['money']) && is_numeric($val)) {
                    $sheet .= '<c r="' . $ref . '"><v>' . $val . '</v></c>';
                } else {
                    $sheet .= '<c r="' . $ref . '" t="inlineStr"><is><t>' . self::xml(mb_substr($val, 0, 30000)) . '</t></is></c>';
                }
                $i++;
            }
            $sheet .= '</row>';
            $r++;
        }
        $sheet .= '</sheetData></worksheet>';

        $workbook = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
            . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="' . self::xml(ucfirst($dataset)) . '" sheetId="1" r:id="rId1"/></sheets>'
            . '</workbook>';

        $workbookRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '</Relationships>';

        $styles = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font>'
            . '<font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
            . '<fills count="3"><fill><patternFill patternType="none"/></fill>'
            . '<fill><patternFill patternType="gray125"/></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FFEDEDED"/><bgColor indexed="64"/></patternFill></fill></fills>'
            . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            . '<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/></cellXfs>'
            . '</styleSheet>';

        $contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '</Types>';

        $rootRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>';

        $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
        $zip = new ZipArchive();
        if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Could not create Excel file.');
        }
        $zip->addFromString('[Content_Types].xml', $contentTypes);
        $zip->addFromString('_rels/.rels', $rootRels);
        $zip->addFromString('xl/workbook.xml', $workbook);
        $zip->addFromString('xl/_rels/workbook.xml.rels', $workbookRels);
        $zip->addFromString('xl/styles.xml', $styles);
        $zip->addFromString('xl/worksheets/sheet1.xml', $sheet);
        $zip->close();

        $data = file_get_contents($tmp);
        @unlink($tmp);
        return $data;
    }

    /* ── CSV ────────────────────────────────────────────────────────────── */

    public static function csv(string $dataset, array $cols, array $rows): string
    {
        $defs = self::columns($dataset);
        $fh = fopen('php://temp', 'r+');
        $header = [];
        foreach ($cols as $k) $header[] = $defs[$k]['label'] ?? $k;
        fputcsv($fh, $header);
        foreach ($rows as $row) {
            $line = [];
            foreach ($cols as $k) $line[] = self::value($row, $k, $defs[$k] ?? []);
            fputcsv($fh, $line);
        }
        rewind($fh);
        $out = stream_get_contents($fh);
        fclose($fh);
        return "\xEF\xBB\xBF" . $out; // BOM so Excel opens UTF-8 correctly
    }

    /* ── Printable report (used as PDF: Print → Save as PDF) ────────────── */

    public static function reportHtml(string $dataset, array $cols, array $rows, array $meta, array $totals = []): string
    {
        $defs  = self::columns($dataset);
        $title = $dataset === 'transactions' ? 'Transactions report' : 'Inventory report';
        $store = htmlspecialchars($meta['store'] ?? 'All stores');
        $range = htmlspecialchars($meta['range'] ?? '');
        $sym   = htmlspecialchars($meta['currency'] ?? '');

        $head = '';
        foreach ($cols as $k) $head .= '<th>' . htmlspecialchars($defs[$k]['label'] ?? $k) . '</th>';

        $body = '';
        foreach ($rows as $row) {
            $body .= '<tr>';
            foreach ($cols as $k) {
                $def = $defs[$k] ?? [];
                $val = self::value($row, $k, $def);
                if (!empty($def['money']) && $val !== '') $val = $sym . ' ' . number_format((float)$val);
                $cls = (!empty($def['money']) || in_array($k, ['item_count', 'stock_quantity', 'id'], true)) ? ' class="num"' : '';
                $body .= '<td' . $cls . '>' . htmlspecialchars($val) . '</td>';
            }
            $body .= '</tr>';
        }

        $summary = '';
        if (!empty($totals)) {
            $summary = '<div class="summary">';
            foreach ($totals as $label => $val) {
                $summary .= '<div class="sum-item"><span>' . htmlspecialchars($label) . '</span><strong>' . htmlspecialchars($val) . '</strong></div>';
            }
            $summary .= '</div>';
        }

        $watermark = htmlspecialchars($meta['store'] ?? 'All stores');

        return '<!DOCTYPE html><html><head><meta charset="utf-8"><title>' . $title . '</title><style>
            * { box-sizing: border-box; }
            html, body { background: #fff; }
            body { font-family: "Segoe UI", Arial, sans-serif; color: #111; margin: 24px; font-size: 11px; position: relative; }

            /* Store-name watermark across every page */
            .wm { position: fixed; inset: 0; display: flex; align-items: center; justify-content: center;
                  z-index: 0; overflow: hidden; pointer-events: none; }
            .wm span { transform: rotate(-28deg); font-size: 92px; font-weight: 800; letter-spacing: .1em;
                       text-transform: uppercase; color: rgba(17, 17, 17, .055); white-space: nowrap;
                       text-align: center; line-height: 1.1; }
            h1, .meta, .bar, .summary, table, .foot, .letterhead { position: relative; z-index: 1; }

            .letterhead { border-bottom: 2.5px solid #111; padding-bottom: 10px; margin-bottom: 12px; }
            .le-store { font-size: 20px; font-weight: 800; text-transform: uppercase; letter-spacing: .05em; margin-bottom: 2px; }
            h1 { font-size: 15px; font-weight: 600; margin: 0 0 4px; color: #333; letter-spacing: .02em; }
            .meta { color: #555; margin-bottom: 4px; }
            .bar { display: flex; gap: 10px; align-items: center; margin: 16px 0; }
            .bar button { font: inherit; padding: 7px 16px; cursor: pointer; border: 1px solid #111; background: #111; color: #fff; border-radius: 4px; }
            .bar span { color: #777; }
            table { width: 100%; border-collapse: collapse; }
            th { background: #f0f0f0; text-align: left; font-size: 10px; text-transform: uppercase; letter-spacing: .03em; border-bottom: 1.5px solid #333; padding: 6px 6px; }
            td { border-bottom: 1px solid #ddd; padding: 5px 6px; vertical-align: top; }
            tr:nth-child(even) td { background: #fafafa; }
            .num { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
            .summary { display: flex; gap: 24px; margin: 14px 0; padding: 10px 12px; background: #f7f7f7; border: 1px solid #e3e3e3; border-radius: 4px; }
            .summary .sum-item span { display: block; color: #777; font-size: 9px; text-transform: uppercase; letter-spacing: .04em; }
            .summary .sum-item strong { font-size: 13px; }
            .foot { margin-top: 16px; color: #999; font-size: 9px; }
            @page { size: A4 landscape; margin: 12mm; }
            @media print {
                body { margin: 0; }
                .bar { display: none; }
                .wm span { color: rgba(17, 17, 17, .07) !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
                th { background: #eee !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
                tr:nth-child(even) td { background: #fafafa !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            }
        </style></head><body>
            <div class="wm" aria-hidden="true"><span>' . $watermark . '</span></div>
            <div class="letterhead">
                <div class="le-store">' . $store . '</div>
                <h1>' . $title . '</h1>
                <div class="meta">' . ($range !== '' ? $range . ' &nbsp;·&nbsp; ' : '') . 'Generated ' . date('Y-m-d H:i') . '</div>
            </div>
            <div class="bar"><button onclick="window.print()">Print / Save as PDF</button><span>' . count($rows) . ' row(s)</span></div>
            ' . $summary . '
            <table><thead><tr>' . $head . '</tr></thead><tbody>' . $body . '</tbody></table>
            <div class="foot">' . htmlspecialchars(SITE_NAME) . ' · Official report</div>
        </body></html>';
    }

    /* ── Small helpers ──────────────────────────────────────────────────── */

    public static function colLetter(int $n): string
    {
        $s = '';
        while ($n > 0) {
            $n--;
            $s = chr(65 + ($n % 26)) . $s;
            $n = intdiv($n, 26);
        }
        return $s;
    }

    private static function xml(string $s): string
    {
        $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $s);
        return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
