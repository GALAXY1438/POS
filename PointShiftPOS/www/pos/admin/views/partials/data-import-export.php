<?php
/**
 * Shared Import / Export modals.
 * Expects: $expDataset, $expLabel, $expRowCount, $expFilters,
 *          $expCanImport, $expImportStoreId, $expImportStoreName
 */
$expCols    = DataExport::columns($expDataset);
$expDefault = DataExport::defaultColumns($expDataset);
$expKey     = 'exp_cols_' . $expDataset;
?>
<!-- Export Modal -->
<div class="modal fade" id="exportModal" tabindex="-1">
<div class="modal-dialog modal-lg">
<form method="GET" action="/pos/export_report.php" class="modal-content" id="exportForm">
    <input type="hidden" name="dataset" value="<?php echo htmlspecialchars($expDataset); ?>">
    <?php foreach ($expFilters as $fk => $fv): if ($fv === '' || $fv === null) continue; ?>
        <input type="hidden" name="<?php echo htmlspecialchars($fk); ?>" value="<?php echo htmlspecialchars((string)$fv); ?>">
    <?php endforeach; ?>

    <div class="modal-header">
        <div>
            <h5 class="modal-title">Export <?php echo htmlspecialchars($expLabel); ?></h5>
            <p style="font-size:12px;color:var(--text-muted);margin:4px 0 0 0;">
                <?php echo number_format($expRowCount); ?> row(s) match the current filters — all of them will be exported.
            </p>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
    </div>

    <div class="modal-body" style="max-height:65vh;overflow-y:auto;">
        <div class="d-flex align-items-center justify-content-between mb-2">
            <h6 style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.6px;margin:0;">Columns</h6>
            <div class="d-flex gap-2">
                <button type="button" class="btn btn-outline-secondary btn-sm" onclick="expSelectAll(true)">Select all</button>
                <button type="button" class="btn btn-outline-secondary btn-sm" onclick="expSelectAll(false)">Clear</button>
            </div>
        </div>
        <div id="expColGrid" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:4px 14px;
             border:1px solid var(--border);border-radius:8px;padding:10px 14px;margin-bottom:16px;">
            <?php foreach ($expCols as $key => $def): ?>
            <label style="display:flex;align-items:center;gap:7px;font-size:13px;padding:4px 0;cursor:pointer;">
                <input type="checkbox" name="cols[]" value="<?php echo htmlspecialchars($key); ?>"
                       data-col="<?php echo htmlspecialchars($key); ?>"
                       <?php echo in_array($key, $expDefault, true) ? 'checked' : ''; ?>>
                <?php echo htmlspecialchars($def['label']); ?>
            </label>
            <?php endforeach; ?>
        </div>

        <h6 style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.6px;margin:0 0 8px;">Format</h6>
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:8px;">
            <label class="exp-fmt">
                <input type="radio" name="format" value="xlsx" checked>
                <span><i class="fas fa-file-excel me-1"></i>Excel <small>(.xlsx)</small></span>
            </label>
            <label class="exp-fmt">
                <input type="radio" name="format" value="report">
                <span><i class="fas fa-file-pdf me-1"></i>PDF <small>(print report)</small></span>
            </label>
            <label class="exp-fmt">
                <input type="radio" name="format" value="csv">
                <span><i class="fas fa-file-csv me-1"></i>CSV</span>
            </label>
        </div>
        <p style="font-size:12px;color:var(--text-muted);margin:10px 0 0 0;">
            <i class="fas fa-circle-info me-1"></i>Excel downloads a spreadsheet. PDF opens a clean report — use the
            <strong>Print / Save as PDF</strong> button on it and choose “Save as PDF” in the print dialog.
        </p>
    </div>

    <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-download me-1"></i>Export</button>
    </div>
</form>
</div>
</div>

<?php if (!empty($expCanImport)): ?>
<!-- Import Modal -->
<div class="modal fade" id="importModal" tabindex="-1">
<div class="modal-dialog">
<form method="POST" action="/pos/import_data.php" enctype="multipart/form-data" class="modal-content">
    <input type="hidden" name="dataset" value="<?php echo htmlspecialchars($expDataset); ?>">
    <input type="hidden" name="store_id" value="<?php echo (int)$expImportStoreId; ?>">
    <div class="modal-header">
        <div>
            <h5 class="modal-title">Import <?php echo htmlspecialchars($expLabel); ?></h5>
            <p style="font-size:12px;color:var(--text-muted);margin:4px 0 0 0;">
                Importing into <strong><?php echo htmlspecialchars($expImportStoreName); ?></strong>
            </p>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
    </div>
    <div class="modal-body">
        <label class="form-label" style="font-size:13px;font-weight:600;">CSV file</label>
        <input type="file" name="import_file" class="form-control form-control-sm" accept=".csv,.txt" required>
        <p style="font-size:12px;color:var(--text-muted);margin:8px 0 0 0;">
            <i class="fas fa-circle-info me-1"></i>Use the template so the column names match.
            <a href="/pos/export_report.php?dataset=<?php echo htmlspecialchars($expDataset); ?>&template=1" class="ms-1">
                <i class="fas fa-download me-1"></i>Download template
            </a>
        </p>
        <?php if ($expDataset === 'transactions'): ?>
        <p style="font-size:12px;color:var(--text-muted);margin:6px 0 0 0;">
            Rows with an order number that already exists are skipped. New rows are marked “Imported from CSV”.
        </p>
        <?php else: ?>
        <p style="font-size:12px;color:var(--text-muted);margin:6px 0 0 0;">
            Matching products (same SKU, barcode or name) are updated; new ones are added. Stock changes appear in Inventory reports.
        </p>
        <?php endif; ?>
    </div>
    <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-upload me-1"></i>Import</button>
    </div>
</form>
</div>
</div>
<?php endif; ?>

<style>
.exp-fmt { display:flex; align-items:center; gap:8px; border:1.5px solid var(--border); border-radius:8px; padding:10px 12px; cursor:pointer; font-size:13px; }
.exp-fmt:has(input:checked) { border-color:var(--c-black); background:var(--surface-2); }
.exp-fmt input { margin:0; }
</style>

<script>
(function () {
    var KEY = <?php echo json_encode($expKey); ?>;
    var grid = document.getElementById('expColGrid');
    if (!grid) return;

    function boxes() { return grid.querySelectorAll('input[type="checkbox"]'); }

    // Restore remembered selection
    try {
        var saved = JSON.parse(localStorage.getItem(KEY) || 'null');
        if (Array.isArray(saved) && saved.length) {
            boxes().forEach(function (b) { b.checked = saved.indexOf(b.value) !== -1; });
        }
    } catch (e) {}

    window.expSelectAll = function (on) {
        boxes().forEach(function (b) { b.checked = on; });
    };

    document.getElementById('exportForm').addEventListener('submit', function (e) {
        var picked = [];
        boxes().forEach(function (b) { if (b.checked) picked.push(b.value); });
        if (!picked.length) {
            e.preventDefault();
            alert('Pick at least one column to export.');
            return;
        }
        try { localStorage.setItem(KEY, JSON.stringify(picked)); } catch (err) {}
    });
})();
</script>
