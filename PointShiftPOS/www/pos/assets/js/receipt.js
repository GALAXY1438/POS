/**
 * TechShift POS — Professional Thermal Receipt Builder v2
 *
 * Config object fields:
 *   storeName, storeBranch, storeAddress, storePhone, storeEmail
 *   receiptHeader, receiptFooter, taxName, taxRate, sym
 *   orderNumber, date, cashierName, paymentMethod, status
 *   amountReceived, mpesaRef, mpesaPhone, mpesaName, notes
 *   items: [{name, qty, unitPrice, total, discount}]
 *   subtotal, discount, discountPercent, tax, total
 */

// ── Helpers ───────────────────────────────────────────────────────────────────
function _esc(s) {
    return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}
function _fmt(sym, n) {
    return sym + parseFloat(n||0).toFixed(2);
}
function _hashPhone(phone) {
    // Show first 3 and last 2 digits: 072*****89
    var s = String(phone||'').replace(/\D/g,'');
    if (s.length < 6) return s;
    return s.substring(0,3) + '*'.repeat(s.length-5) + s.slice(-2);
}
// Generate a Code128-style barcode using SVG
function _barcodeDiv(text) {
    // Encode text as a barcode representation using narrow/wide bars
    var seed = 0;
    for (var i=0; i<text.length; i++) seed += text.charCodeAt(i) * (i+1);
    
    var total = 60; // number of bars
    var width = 200; // total SVG width
    var barWidth = width / total;
    var bars = '';
    var x = 0;
    
    for (var b=0; b<total; b++) {
        var w  = ((seed * (b+7) * 31337) % 3 === 0) ? barWidth * 2 : barWidth;
        var clr = b % 2 === 0 ? '#111' : '#fff';
        bars += '<rect x="'+x+'" y="0" width="'+w+'" height="40" fill="'+clr+'"/>';
        x += w;
    }
    
    return '<div style="text-align:center;margin:6px 0 2px;">'
         + '<svg viewBox="0 0 '+width+' 40" style="width:180px;height:35px;border:1px solid #ddd;padding:4px;background:#fff;">'
         + bars
         + '</svg>'
         + '<div style="font-size:8px;letter-spacing:2px;color:#555;margin-top:2px;">'+_esc(text)+'</div>'
         + '</div>';
}

// Generate QR code using simple SVG pattern (dummy for visual representation)
function _qrcodeDiv(text, size) {
    size = size || 100;
    // Create a simple pixelated pattern based on the text
    let seed = 0;
    for (let i = 0; i < text.length; i++) {
        seed += text.charCodeAt(i) * (i + 1);
    }
    
    const gridSize = 21; // QR codes typically use 21x21, 25x25, etc.
    const pixelSize = size / gridSize;
    let rects = '';
    
    // Generate pattern - this is a simplified visual QR code
    // Add positioning markers (corners)
    const markerSize = 7;
    const markers = [
        {x: 0, y: 0}, // Top-left
        {x: gridSize - markerSize, y: 0}, // Top-right
        {x: 0, y: gridSize - markerSize}, // Bottom-left
    ];
    
    // Draw positioning markers (those square things in corners)
    markers.forEach(marker => {
        // Outer square (7x7)
        rects += `<rect x="${marker.x * pixelSize}" y="${marker.y * pixelSize}" width="${markerSize * pixelSize}" height="${markerSize * pixelSize}" fill="#000"/>`;
        // Inner white (5x5)
        rects += `<rect x="${(marker.x + 1) * pixelSize}" y="${(marker.y + 1) * pixelSize}" width="${(markerSize - 2) * pixelSize}" height="${(markerSize - 2) * pixelSize}" fill="#fff"/>`;
        // Center black (3x3)
        rects += `<rect x="${(marker.x + 2) * pixelSize}" y="${(marker.y + 2) * pixelSize}" width="${(markerSize - 4) * pixelSize}" height="${(markerSize - 4) * pixelSize}" fill="#000"/>`;
    });
    
    // Fill the rest with pseudo-random pattern based on text
    for (let y = 0; y < gridSize; y++) {
        for (let x = 0; x < gridSize; x++) {
            // Skip positioning markers
            const inMarker = markers.some(m => 
                x >= m.x && x < m.x + markerSize && 
                y >= m.y && y < m.y + markerSize
            );
            if (inMarker) continue;
            
            // Generate pseudo-random black/white based on seed and position
            const val = (seed * (x + 1) * (y + 1) * 31337) % 2;
            if (val === 0) {
                rects += `<rect x="${x * pixelSize}" y="${y * pixelSize}" width="${pixelSize}" height="${pixelSize}" fill="#000"/>`;
            }
        }
    }
    
    return '<div style="text-align:center;margin:8px 0;">'
         + '<svg viewBox="0 0 '+size+' '+size+'" style="width:'+size+'px;height:'+size+'px;border:3px solid #eee;padding:6px;background:#fff;">'
         + rects
         + '</svg>'
         + '<div style="font-size:8px;color:#aaa;margin-top:4px;">Scan to verify receipt</div>'
         + '</div>';
}

// ── Main receipt HTML builder ─────────────────────────────────────────────────
function buildReceiptHtml(c) {
    var sym    = c.sym || 'KSH';
    var fmt    = function(n) { return _fmt(sym, n); };
    var now    = c.date ? (c.date instanceof Date ? c.date : new Date(c.date.replace(' ','T'))) : new Date();
    var dateStr = now.toLocaleDateString('en-KE', {day:'2-digit',month:'short',year:'numeric'});
    var timeStr = now.toLocaleTimeString('en-KE', {hour:'2-digit',minute:'2-digit',second:'2-digit'});
    var isCash  = (c.paymentMethod||'cash').toLowerCase() === 'cash';
    var isMpesa = (c.paymentMethod||'').toLowerCase() === 'mpesa';
    var payLabel = (c.paymentMethod||'CASH').toUpperCase().replace('_',' ');

    // ── ITEMS ──────────────────────────────────────────────────────────────
    var items     = c.items || [];
    var totalQty  = items.reduce(function(s,i){ return s + parseInt(i.qty||i.quantity||1); }, 0);

    var itemRows = items.map(function(item) {
        var name     = _esc(item.name || item.product_name || 'Item');
        var qty      = parseInt(item.qty || item.quantity || 1);
        var price    = parseFloat(item.unitPrice || item.unit_price || 0);
        var total    = parseFloat(item.total || item.total_price || (price * qty));
        return '<tr>'
            + '<td style="padding:3px 0;width:44%;">' + name + '</td>'
            + '<td style="padding:3px 0;text-align:center;width:10%;">' + qty + '</td>'
            + '<td style="padding:3px 0;text-align:right;width:21%;">' + fmt(price) + '</td>'
            + '<td style="padding:3px 0;text-align:right;width:25%;font-weight:600;">' + fmt(total) + '</td>'
            + '</tr>';
    }).join('');

    // ── DISCOUNT BREAKDOWN ─────────────────────────────────────────────────
    var discountLines = '';
    var totalDiscount = parseFloat(c.discount||0);
    if (totalDiscount > 0) {
        // Show per-item discounts if available, else show total
        var perItemDisc = items.filter(function(i){ return parseFloat(i.discount||0) > 0; });
        if (perItemDisc.length > 0) {
            discountLines += '<div class="rct-section-label">DISCOUNT BREAKDOWN</div>';
            perItemDisc.forEach(function(item) {
                var nm   = _esc((item.name||'Item').substring(0,20));
                var disc = parseFloat(item.discount||0);
                discountLines += '<div class="rct-disc-row">'
                    + '<span>'+nm+'</span>'
                    + '<span class="rct-disc-val">-'+fmt(disc)+'</span>'
                    + '</div>';
            });
        }
        discountLines += '<div class="rct-disc-row rct-disc-total">'
            + '<span>'+(c.discountLabel||'Total Discount')+(c.discountPercent>0?' ('+c.discountPercent+'%)':'')+'</span>'
            + '<span>-'+fmt(totalDiscount)+'</span>'
            + '</div>';
    }

    // ── MPESA DETAILS ──────────────────────────────────────────────────────
    var mpesaBlock = '';
    if (isMpesa) {
        mpesaBlock = '<div class="rct-dashed" style="margin:8px 0;"></div>'
            + '<div class="rct-section-label">M-PESA PAYMENT DETAILS</div>'
            + '<div class="rct-mpesa-block">';
        if (c.mpesaName)  mpesaBlock += '<div class="rct-mpesa-row"><span>Name</span><strong>'+_esc(c.mpesaName)+'</strong></div>';
        if (c.mpesaPhone) mpesaBlock += '<div class="rct-mpesa-row"><span>Mobile</span><span>'+_hashPhone(c.mpesaPhone)+'</span></div>';
        if (c.mpesaRef)   mpesaBlock += '<div class="rct-mpesa-row"><span>Code</span><strong>'+_esc(c.mpesaRef)+'</strong></div>';
        mpesaBlock += '<div class="rct-mpesa-row"><span>Amount</span><strong>'+fmt(c.total)+'</strong></div>';
        mpesaBlock += '</div>';
    }

    // ── BUILD HTML ─────────────────────────────────────────────────────────
    var html = '<div class="rct-wrap">';

    // 1. Store header
    html += '<div class="rct-header">'
        + '<div class="rct-store-name">'+_esc(c.storeName||'TechShift POS')+'</div>'
        + (c.storeBranch  ? '<div class="rct-store-sub">'+_esc(c.storeBranch)+'</div>'        : '')
        + (c.storeAddress ? '<div class="rct-store-sub">'+_esc(c.storeAddress)+'</div>'       : '')
        + (c.storePhone   ? '<div class="rct-store-sub">Tel: '+_esc(c.storePhone)+'</div>'    : '')
        + (c.storeEmail   ? '<div class="rct-store-sub">'+_esc(c.storeEmail)+'</div>'         : '')
        + (c.receiptHeader ? '<div class="rct-store-tagline">'+_esc(c.receiptHeader)+'</div>' : '')
        + '<div class="rct-dots">· · · · · · · · · · · · · · · · · ·</div>'

        // CASH SALE / MPESA SALE label
        + '<div class="rct-sale-type rct-sale-'+(isMpesa?'mpesa':'cash')+'">'
        + (isMpesa ? 'M-PESA SALE' : 'CASH SALE')
        + '</div>'

        + '<div class="rct-dots">· · · · · · · · · · · · · · · · · ·</div>'
        + '</div>';

    // 2. Order meta
    html += '<div class="rct-meta">'
        + '<div class="rct-meta-row"><span>Order #</span><strong>'+_esc(c.orderNumber||'—')+'</strong></div>'
        + '<div class="rct-meta-row"><span>Date</span><span>'+dateStr+'</span></div>'
        + '<div class="rct-meta-row"><span>Time</span><span>'+timeStr+'</span></div>'
        + '<div class="rct-meta-row"><span>Served by</span><strong>'+_esc(c.cashierName||'—')+'</strong></div>'
        + '<div class="rct-meta-row"><span>Payment</span>'
        +   '<span class="rct-pay-badge rct-pay-'+(isMpesa?'mpesa':'cash')+'">'+payLabel+'</span>'
        + '</div>'
        + (c.status ? '<div class="rct-meta-row"><span>Status</span>'
        +   '<span style="font-weight:700;color:'+(c.status==='completed'?'#16a34a':c.status==='cancelled'?'#dc2626':'#d97706')+';">'
        +   c.status.toUpperCase()+'</span></div>' : '')
        + (c.customerName ? '<div class="rct-meta-row"><span>Customer</span><strong>'+_esc(c.customerName)+'</strong></div>' : '')
        + '</div>';

    // 3. Items
    html += '<div class="rct-dashed"></div>'
        + '<table class="rct-items">'
        + '<thead><tr>'
        + '<th style="text-align:left;">Item</th>'
        + '<th style="text-align:center;">Qty</th>'
        + '<th style="text-align:right;">Price</th>'
        + '<th style="text-align:right;">Total</th>'
        + '</tr></thead>'
        + '<tbody>'+itemRows+'</tbody>'
        + '</table>';

    // 4. Totals
    var tax      = parseFloat(c.tax||0);
    var subtotal = parseFloat(c.subtotal||0);
    var total    = parseFloat(c.total||0);
    var amtRecv  = parseFloat(c.amountReceived||total);
    var change   = Math.max(0, amtRecv - total);

    html += '<div class="rct-dashed"></div>'
        + '<div class="rct-totals">'
        + '<div class="rct-tot-row"><span>Items</span><span>'+totalQty+'</span></div>'
        + '<div class="rct-tot-row"><span>Subtotal</span><span>'+fmt(subtotal)+'</span></div>'
        + (totalDiscount>0 ? '<div class="rct-tot-row rct-discount-line"><span>'+(c.discountLabel||'Discount')+(c.discountPercent>0?' ('+c.discountPercent+'%)':'')+'</span><span>-'+fmt(totalDiscount)+'</span></div>' : '')
        + (tax>0 ? '<div class="rct-tot-row rct-tax-line"><span>'+(c.taxName||'VAT')+' incl.</span><span>'+fmt(tax)+'</span></div>' : '')
        + '<div class="rct-dashed" style="margin:5px 0;"></div>'
        + '<div class="rct-tot-row rct-grand"><span>TOTAL</span><span>'+fmt(total)+'</span></div>'
        + (isCash
            ? '<div class="rct-dashed" style="margin:5px 0;"></div>'
            + '<div class="rct-tot-row"><span>Paid</span><span>'+fmt(amtRecv)+'</span></div>'
            + '<div class="rct-tot-row rct-change"><span>Change</span><span>'+fmt(change)+'</span></div>'
            : '')
        + '</div>'; // /totals

    // 5. M-Pesa details block
    html += mpesaBlock;

    // 6. Discount breakdown
    if (discountLines) {
        html += '<div class="rct-dashed" style="margin:8px 0;"></div>' + discountLines;
    }

    // 7. Notes
    if (c.notes) {
        html += '<div class="rct-notes"><span>Note:</span> '+_esc(c.notes)+'</div>';
    }

    // 8. Footer (Removed barcode and QR code)
    html += '<div class="rct-dashed" style="margin:10px 0 4px;"></div>'
        + '<div class="rct-footer">'
        + '<div class="rct-footer-msg">'+_esc(c.receiptFooter||'Thank you for your business!')+'</div>'
        + '<div class="rct-footer-sub">Powered by TechShift POS</div>'
        + '<div class="rct-footer-sub">Printed: '+dateStr+' '+timeStr+'</div>'
        + '</div>'
        + '<div class="rct-dashed"></div>'
        + '<div style="text-align:center;margin-top:6px;letter-spacing:3px;font-size:9px;color:#ccc;">★ ★ ★</div>';

    html += '</div>'; // /rct-wrap
    return html;
}

// ── Styles ────────────────────────────────────────────────────────────────────
function receiptStyles() {
    return `
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            background: #f5f5f5;
            display: flex; justify-content: center; align-items: flex-start;
            padding: 20px 12px; min-height: 100vh;
            font-family: 'Courier New', monospace;
        }
        .rct-wrap {
            width: 302px;
            background: #fff;
            padding: 14px 12px;
            font-family: 'Courier New', monospace;
            font-size: 10px; color: #000;
            box-shadow: 0 4px 16px rgba(0,0,0,.12);
            border-radius: 0;
        }

        /* Header */
        .rct-header { text-align: center; margin-bottom: 6px; }
        .rct-store-name {
            font-size: 15px; font-weight: 700; letter-spacing: .5px;
            margin-bottom: 2px; text-transform: uppercase; line-height: 1.2;
        }
        .rct-store-sub { font-size: 9px; color: #333; line-height: 1.5; }
        .rct-store-tagline { font-size: 9px; font-style: italic; color: #555; margin-top: 2px; }
        .rct-dots { font-size: 8px; color: #ccc; margin: 4px 0 3px; letter-spacing: 1px; }

        /* Sale type badge */
        .rct-sale-type {
            display: inline-block; padding: 3px 14px;
            font-size: 11px; font-weight: 700; letter-spacing: 2px;
            text-transform: uppercase; margin: 4px 0;
        }
        .rct-sale-cash {
            background: #111; color: #fff;
            border-radius: 2px;
        }
        .rct-sale-mpesa {
            background: #16a34a; color: #fff;
            border-radius: 2px;
        }

        /* Meta rows */
        .rct-meta { margin: 8px 0; }
        .rct-meta-row {
            display: flex; justify-content: space-between; align-items: baseline;
            padding: 2.5px 0; font-size: 10.5px;
            border-bottom: 1px dotted #ebebeb;
        }
        .rct-meta-row:last-child { border-bottom: none; }
        .rct-meta-row > span:first-child { color: #666; flex-shrink: 0; margin-right: 8px; }
        .rct-meta-row strong { font-weight: 700; }
        .rct-pay-badge {
            padding: 1px 7px; border-radius: 3px;
            font-size: 9.5px; font-weight: 700; letter-spacing: .5px;
        }
        .rct-pay-cash  { background: #111; color: #fff; }
        .rct-pay-mpesa { background: #16a34a; color: #fff; }

        /* Dashed separator */
        .rct-dashed { border: none; border-top: 1px dashed #ccc; margin: 8px 0; }

        /* Items table */
        .rct-items { width: 100%; border-collapse: collapse; font-size: 10px; }
        .rct-items thead tr { border-bottom: 1px solid #000; }
        .rct-items thead th {
            padding: 3px 2px; font-size: 8.5px; font-weight: 700;
            text-transform: uppercase; letter-spacing: .3px;
        }
        .rct-items tbody tr { border-bottom: 1px dotted #ddd; }
        .rct-items tbody tr:last-child { border-bottom: none; }
        .rct-items tbody td { padding: 3px 2px; vertical-align: top; line-height: 1.3; }

        /* Totals */
        .rct-totals { font-size: 10.5px; }
        .rct-tot-row { display: flex; justify-content: space-between; padding: 2px 0; }
        .rct-tot-row > span:first-child { color: #666; }
        .rct-discount-line span { color: #16a34a !important; font-weight: 600; }
        .rct-tax-line span { color: #888 !important; font-size: 9.5px; }
        .rct-grand {
            font-size: 14px; font-weight: 700; padding: 5px 0 3px;
            letter-spacing: .3px;
        }
        .rct-grand > span:first-child { color: #111 !important; }
        .rct-change > span { font-weight: 600; color: #16a34a !important; }

        /* M-Pesa block */
        .rct-section-label {
            font-size: 8.5px; font-weight: 700; letter-spacing: 1.5px;
            text-transform: uppercase; color: #888; margin-bottom: 4px;
        }
        .rct-mpesa-block {
            background: #f0fdf4; border: 1px solid #bbf7d0;
            border-radius: 3px; padding: 6px 8px; margin-top: 2px;
        }
        .rct-mpesa-row {
            display: flex; justify-content: space-between;
            padding: 2px 0; font-size: 10.5px;
            border-bottom: 1px dotted #d1fae5;
        }
        .rct-mpesa-row:last-child { border-bottom: none; }
        .rct-mpesa-row > span:first-child { color: #166534; }
        .rct-mpesa-row strong { font-weight: 700; color: #15803d; }

        /* Discount breakdown */
        .rct-disc-row {
            display: flex; justify-content: space-between; padding: 2px 0;
            font-size: 10.5px; border-bottom: 1px dotted #eee;
        }
        .rct-disc-row:last-child { border-bottom: none; }
        .rct-disc-row > span:first-child { color: #555; }
        .rct-disc-val { color: #16a34a; font-weight: 600; }
        .rct-disc-total { font-weight: 700; }
        .rct-disc-total > span:first-child { color: #111; }

        /* Notes */
        .rct-notes {
            margin-top: 8px; padding: 5px 7px;
            background: #fafafa; border-left: 2px solid #ccc;
            font-size: 10px; color: #555;
        }

        /* Footer */
        .rct-footer { text-align: center; margin-top: 8px; }
        .rct-footer-msg {
            font-size: 11px; font-weight: 600; letter-spacing: .3px; margin-bottom: 3px;
        }
        .rct-footer-sub { font-size: 9px; color: #aaa; line-height: 1.7; }

        @media print {
            @page { size: 80mm auto; margin: 0; }
            * { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            body { background: none; padding: 0; justify-content: flex-start; margin: 0; }
            .rct-wrap { box-shadow: none; padding: 4px 6px; width: 100%; max-width: 80mm; }
            .rct-sale-cash, .rct-sale-mpesa,
            .rct-pay-cash, .rct-pay-mpesa {
                background: #fff !important; color: #000 !important;
                border: 1.5px solid #000 !important;
            }
            .rct-mpesa-block { background: #fff !important; border: 1px solid #000 !important; }
            .no-print { display: none !important; }
        }
    `;
}

// ── Open dedicated print window ───────────────────────────────────────────────
function openReceiptWindow(config) {
    var html = buildReceiptHtml(config);
    var w = window.open('', '_blank', 'width=460,height=820,scrollbars=yes');
    if (!w) { alert('Pop-up blocked. Please allow pop-ups for this site.'); return; }

    w.document.write('<!DOCTYPE html><html><head>');
    w.document.write('<meta charset="UTF-8">');
    w.document.write('<title>Receipt \u2014 ' + _esc(config.orderNumber||'') + '</title>');
    w.document.write('<style>' + receiptStyles() + '</style>');
    w.document.write('</head><body>');
    w.document.write(html);
    var oid = parseInt(config.orderId || 0, 10);
    w.document.write('<div class="no-print" style="text-align:center;padding:20px 0 12px;font-family:Courier New,monospace;">');
    if (oid) {
        w.document.write('<button onclick="serverPrint()" style="background:#111;color:#fff;border:none;border-radius:6px;padding:11px 30px;font-size:12px;font-weight:700;cursor:pointer;letter-spacing:.5px;margin-right:8px;">Print Receipt</button>');
        w.document.write('<script>function serverPrint(){fetch("/pos/api/print_receipt.php",{method:"POST",headers:{"Content-Type":"application/x-www-form-urlencoded"},body:"order_id=' + oid + '"}).then(function(r){return r.json();}).then(function(d){if(!d.success)alert("Print failed: "+d.message);}).catch(function(e){alert("Print failed: "+e.message);});}<\/script>');
    } else {
        w.document.write('<button onclick="window.print()" style="background:#111;color:#fff;border:none;border-radius:6px;padding:11px 30px;font-size:12px;font-weight:700;cursor:pointer;letter-spacing:.5px;margin-right:8px;">Print Receipt</button>');
    }
    w.document.write('<button onclick="window.close()" style="background:#fff;color:#666;border:1px solid #ccc;border-radius:6px;padding:11px 20px;font-size:12px;cursor:pointer;">Close</button>');
    w.document.write('</div></body></html>');
    w.document.close();

    w.onload = function() { setTimeout(function() { w.focus(); if (!oid) w.print(); }, 400); };
}

// ── Server-side thermal printing (ESC/POS through Windows spooler) ───────────
function serverPrintReceipt(orderId) {
    if (!orderId) return Promise.resolve({ success: false, message: 'Missing order id' });
    return fetch('/pos/api/print_receipt.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'order_id=' + encodeURIComponent(orderId)
    }).then(function (r) { return r.json(); });
}
