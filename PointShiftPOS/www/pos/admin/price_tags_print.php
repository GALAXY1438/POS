<?php
/**
 * Printable price-tag sheet — 80×50 mm labels with real Code 128 barcodes.
 * Usage: price_tags_print.php?items=12:3,45:1&size=80x50&auto=1
 */
require_once __DIR__ . '/../config.php';
User::requireLogin();
User::requireAdmin();

$db  = Database::getInstance()->getConnection();
$sym = getCurrencySymbol();

$settings = [];
foreach ($db->query("SELECT setting_key, setting_value FROM store_settings")->fetchAll() as $r) {
    $settings[$r['setting_key']] = $r['setting_value'];
}
$storeName = trim((string)($settings['store_name'] ?? SITE_NAME));

$sizes = [
    '80x50' => ['w' => 80, 'h' => 50, 'label' => '80 × 50 mm (standard)'],
    '80x30' => ['w' => 80, 'h' => 30, 'label' => '80 × 30 mm (compact)'],
    '58x40' => ['w' => 58, 'h' => 40, 'label' => '58 × 40 mm (small roll)'],
];
$size = $_GET['size'] ?? '80x50';
if (!isset($sizes[$size])) $size = '80x50';
$auto = !empty($_GET['auto']);

// items=12:3,45:1  →  [product_id => copies]
$qtyMap = [];
foreach (explode(',', (string)($_GET['items'] ?? '')) as $part) {
    if (!preg_match('/^\s*(\d+)\s*(?::\s*(\d+)\s*)?$/', $part, $m)) continue;
    $id  = (int)$m[1];
    $qty = isset($m[2]) && $m[2] !== '' ? (int)$m[2] : 1;
    if ($id <= 0) continue;
    $qty = max(1, min(999, $qty));
    $qtyMap[$id] = ($qtyMap[$id] ?? 0) + $qty;
}

// Safety cap: keep a stray huge request from locking the printer queue.
$totalTags = array_sum($qtyMap);
if ($totalTags > 400) {
    $scale = 400 / $totalTags;
    foreach ($qtyMap as $id => $q) $qtyMap[$id] = max(1, (int)floor($q * $scale));
    $totalTags = array_sum($qtyMap);
}

$products = [];
if ($qtyMap) {
    $ids = array_keys($qtyMap);
    $in  = implode(',', array_fill(0, count($ids), '?'));
    $st  = $db->prepare("SELECT p.id, p.name, p.price, p.barcode, p.sku, c.name AS category_name
        FROM products p LEFT JOIN categories c ON p.category_id = c.id
        WHERE p.id IN ($in)");
    $st->execute($ids);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $products[(int)$row['id']] = $row;
    }
}

/**
 * Code 128B barcode as SVG (module widths, quiet zones, no dependencies).
 */
function code128bSvg(string $text, int $height = 48): string
{
    static $p = [
        '212222','222122','222221','121223','121322','131222','122213','122312','132212','221213',
        '221312','231212','112232','122132','122231','113222','123122','123221','223211','221132',
        '221231','213212','223112','312131','311222','321122','321221','312212','322112','322211',
        '212123','212321','232121','111323','131123','131321','112313','132113','132311','211313',
        '231113','231311','112133','112331','132131','113123','113321','133121','313121','211331',
        '231131','213113','213311','213131','311123','311321','331121','312113','312311','332111',
        '314111','221411','431111','111224','111422','121124','121421','141122','141221','112214',
        '112412','122114','122411','142112','142211','241211','221114','413111','241112','134111',
        '111242','121142','121241','114212','124112','124211','411212','421112','421211','212141',
        '214121','412121','111143','111341','131141','114113','114311','411113','411311','113141',
        '114131','311141','411131','211412','211214','211232','2331112',
    ];

    $text = preg_replace('/[^\x20-\x7E]/', '', $text);
    if ($text === '') return '';

    $values = [104];               // START B
    $sum    = 104;
    $chars  = str_split($text);
    foreach ($chars as $i => $ch) {
        $v = ord($ch) - 32;
        $values[] = $v;
        $sum += $v * ($i + 1);
    }
    $values[] = $sum % 103;        // checksum
    $values[] = 106;               // STOP

    $x = 10;                       // quiet zone
    $rects = '';
    foreach ($values as $v) {
        $pat = $p[$v];
        $bar = true;
        foreach (str_split($pat) as $w) {
            $w = (int)$w;
            if ($bar) {
                $rects .= '<rect x="' . $x . '" y="0" width="' . $w . '" height="' . $height . '"/>';
            }
            $x += $w;
            $bar = !$bar;
        }
    }
    $width = $x + 10;

    return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $width . ' ' . $height
        . '" preserveAspectRatio="none" shape-rendering="crispEdges" fill="#000">' . $rects . '</svg>';
}

$symLabel = $sym !== '' ? $sym : 'KSh';
$tags = [];
foreach ($qtyMap as $id => $qty) {
    if (!isset($products[$id])) continue;
    $prod = $products[$id];
    $code = trim((string)($prod['barcode'] ?? ''));
    if ($code === '') $code = trim((string)($prod['sku'] ?? ''));
    if ($code === '') $code = 'PRD-' . $prod['id'];
    $tags[] = [
        'name'     => $prod['name'],
        'price'    => (float)$prod['price'],
        'category' => (string)($prod['category_name'] ?? ''),
        'code'     => $code,
        'svg'      => code128bSvg($code),
        'qty'      => $qty,
    ];
}

$esc = fn($s) => htmlspecialchars((string)$s);
$sizeQ = $sizes[$size];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Price Tags — <?php echo $esc($storeName); ?></title>
<style>
@page { size: <?php echo $sizeQ['w']; ?>mm <?php echo $sizeQ['h']; ?>mm; margin: 0; }
* { box-sizing: border-box; }
html, body { margin: 0; padding: 0; }
body { font-family: "Segoe UI", Arial, Helvetica, sans-serif; background: #e9e9e9; color: #111; }

.toolbar { position: sticky; top: 0; z-index: 20; display: flex; justify-content: flex-end; align-items: center;
    gap: 8px; background: #f7f7f7; border-bottom: 1px solid #ccc; padding: 8px 14px; font-size: 9pt; }
.toolbar .count { margin-right: auto; color: #444; }
.toolbar select { font-family: inherit; font-size: 9pt; padding: 6px 8px; border: 1px solid #999; border-radius: 3px; background: #fff; }
.toolbar button { font-family: inherit; font-size: 9pt; font-weight: 600; padding: 7px 16px; cursor: pointer;
    border: 1px solid #333; background: #fff; color: #111; border-radius: 3px; }
.toolbar button.primary { background: #0A0A0A; border-color: #0A0A0A; color: #fff; }
.print-hint { background: #FAFAFA; border-bottom: 1px solid #E5E5E5; color: #525252;
    font-size: 8.4pt; padding: 6px 14px; }

.sheet { display: flex; flex-direction: column; align-items: center; gap: 6mm; padding: 8mm 0; }

.tag {
    width: <?php echo $sizeQ['w']; ?>mm;
    height: <?php echo $sizeQ['h']; ?>mm;
    background: #fff;
    padding: 2.4mm 3.4mm 2mm;
    overflow: hidden;
    display: flex;
    flex-direction: column;
    page-break-after: always;
    page-break-inside: avoid;
    border: 1px dashed #aaa;
    box-shadow: 0 1px 5px rgba(0,0,0,.15);
}
.tag:last-child { page-break-after: auto; }

.t-store { font-size: 6.6pt; font-weight: 800; letter-spacing: 1.1px; text-transform: uppercase;
    text-align: center; border-bottom: .35mm solid #000; padding-bottom: .9mm; line-height: 1.1;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.t-name { font-size: 9.2pt; font-weight: 700; line-height: 1.12; margin-top: 1.1mm;
    height: 7.6mm; overflow: hidden; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; }
.t-cat { font-size: 5.4pt; color: #444; text-transform: uppercase; letter-spacing: .35px;
    margin-top: .6mm; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.t-price { font-size: 19.5pt; font-weight: 900; letter-spacing: -.4px; text-align: center;
    line-height: 1; margin: .8mm 0 .6mm; font-variant-numeric: tabular-nums; }
.t-code { margin-top: auto; }
.t-code svg { display: block; width: 100%; height: 10.5mm; }
.t-digits { font-family: "Courier New", monospace; font-size: 6.2pt; letter-spacing: 1.4px;
    text-align: center; margin-top: .3mm; }

/* Compact 80×30 */
body.s-80x30 .tag { padding: 1.6mm 3mm 1.2mm; }
body.s-80x30 .t-store { font-size: 6pt; padding-bottom: .6mm; }
body.s-80x30 .t-name { font-size: 7.4pt; height: 5.4mm; margin-top: .7mm; -webkit-line-clamp: 1; }
body.s-80x30 .t-cat { display: none; }
body.s-80x30 .t-price { font-size: 14pt; margin: .4mm 0; }
body.s-80x30 .t-code svg { height: 7.2mm; }
body.s-80x30 .t-digits { font-size: 5.4pt; letter-spacing: 1px; }

/* Small 58×40 */
body.s-58x40 .tag { padding: 2mm 2.6mm 1.6mm; }
body.s-58x40 .t-store { font-size: 5.8pt; letter-spacing: .9px; }
body.s-58x40 .t-name { font-size: 7.6pt; height: 6mm; }
body.s-58x40 .t-cat { font-size: 4.8pt; }
body.s-58x40 .t-price { font-size: 15pt; }
body.s-58x40 .t-code svg { height: 8mm; }
body.s-58x40 .t-digits { font-size: 5.2pt; letter-spacing: .8px; }

.empty { margin: 40px auto; max-width: 420px; text-align: center; color: #555; font-size: 10pt;
    background: #fff; border: 1px solid #ccc; border-radius: 4px; padding: 24px; }

@media print {
    body { background: #fff; }
    .toolbar { display: none !important; }
    .print-hint { display: none !important; }
    .sheet { display: block; padding: 0; gap: 0; }
    .tag { border: none; box-shadow: none; margin: 0; }
}
</style>
</head>
<body class="s-<?php echo $esc($size); ?>">

<div class="toolbar">
    <span class="count"><?php echo $totalTags; ?> <?php echo $totalTags == 1 ? 'label' : 'labels'; ?> · <?php echo $esc($sizeQ['label']); ?></span>
    <form method="GET" style="margin:0;display:flex;gap:6px;align-items:center;">
        <input type="hidden" name="items" value="<?php echo $esc((string)($_GET['items'] ?? '')); ?>">
        <select name="size" onchange="this.form.submit()">
            <?php foreach ($sizes as $k => $sz): ?>
            <option value="<?php echo $k; ?>" <?php echo $k === $size ? 'selected' : ''; ?>><?php echo $esc($sz['label']); ?></option>
            <?php endforeach; ?>
        </select>
        <noscript><button type="submit">Apply</button></noscript>
    </form>
    <button type="button" onclick="window.close()">Close</button>
    <button type="button" class="primary" onclick="window.print()">Print labels</button>
</div>
<div class="print-hint">In the print dialog: select your label / thermal printer, set Margins to None, Scale 100%, and switch off “Headers and footers”.</div>

<?php if (empty($tags)): ?>
<div class="empty">
    <strong>No products to print.</strong><br>
    Go back to Print Price Tags, choose products and quantities, then press Print again.
</div>
<?php else: ?>
<div class="sheet">
    <?php foreach ($tags as $t): ?>
    <?php for ($i = 0; $i < $t['qty']; $i++): ?>
    <div class="tag">
        <div class="t-store"><?php echo $esc($storeName); ?></div>
        <div class="t-name"><?php echo $esc($t['name']); ?></div>
        <?php if ($t['category'] !== ''): ?>
        <div class="t-cat"><?php echo $esc($t['category']); ?></div>
        <?php endif; ?>
        <div class="t-price"><?php echo $esc($symLabel . number_format($t['price'], 2)); ?></div>
        <div class="t-code">
            <?php echo $t['svg']; ?>
            <div class="t-digits"><?php echo $esc($t['code']); ?></div>
        </div>
    </div>
    <?php endfor; ?>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if ($auto && !empty($tags)): ?>
<script>window.addEventListener('load', function () { setTimeout(function () { window.print(); }, 350); });</script>
<?php endif; ?>
</body>
</html>
