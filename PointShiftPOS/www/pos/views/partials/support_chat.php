<?php
/* TechShift assistant — floating help widget. Included by the main layouts. */
$__chatName  = trim((($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? '')));
$__chatRole  = (string)($_SESSION['role'] ?? '');
$__chatEmail = '';
try {
    $__st = Database::getInstance()->getConnection()->prepare('SELECT email FROM users WHERE id = ?');
    $__st->execute([(int)($_SESSION['user_id'] ?? 0)]);
    $__row = $__st->fetch();
    if ($__row) {
        $__chatEmail = (string)($__row['email'] ?? '');
    }
} catch (Throwable $e) {
}
?>
<style>
#tsChat, #tsChat * { font-family: var(--font) !important; box-sizing: border-box; }
#tsChat { position: fixed; right: 20px; bottom: 20px; z-index: 1045; }
.ts-chat-launcher {
    width: 52px; height: 52px; border-radius: 50%;
    background: var(--primary); color: var(--primary-contrast);
    border: 1px solid var(--primary); cursor: pointer;
    display: flex; align-items: center; justify-content: center;
    box-shadow: 0 6px 18px rgba(0, 0, 0, .18);
    transition: transform .15s ease, background-color .15s ease;
    position: relative;
}
.ts-chat-launcher:hover { transform: translateY(-2px); background: var(--primary-hover); }
.ts-chat-launcher i, .ts-chat-launcher svg { width: 22px !important; height: 22px !important; }
.ts-chat-launcher .ts-chat-ping {
    position: absolute; top: 2px; right: 2px; width: 10px; height: 10px;
    border-radius: 50%; background: var(--danger); border: 2px solid var(--surface);
}
#tsChat.is-open .ts-chat-launcher { display: none; }
.ts-chat-panel {
    position: absolute; right: 0; bottom: 0;
    width: 360px; max-width: calc(100vw - 32px);
    height: 520px; max-height: calc(100vh - 40px);
    background: var(--surface); border: 1px solid var(--border);
    border-radius: 16px; box-shadow: 0 18px 48px rgba(0, 0, 0, .22);
    display: none; flex-direction: column; overflow: hidden;
}
#tsChat.is-open .ts-chat-panel { display: flex; }
.ts-chat-head {
    display: flex; align-items: center; gap: 10px;
    padding: 12px 14px; border-bottom: 1px solid var(--border);
    background: var(--surface); flex-shrink: 0;
}
.ts-chat-avatar {
    width: 34px; height: 34px; border-radius: 10px;
    background: var(--primary); color: var(--primary-contrast);
    display: flex; align-items: center; justify-content: center;
    font-size: 13px; font-weight: 600; flex-shrink: 0;
}
.ts-chat-title { display: flex; flex-direction: column; line-height: 1.25; min-width: 0; flex: 1; }
.ts-chat-title strong { font-size: 13px; font-weight: 600; color: var(--text); }
.ts-chat-title span { font-size: 11px; color: var(--text-subtle); }
.ts-chat-close {
    width: 30px; height: 30px; border-radius: 8px; border: none;
    background: transparent; color: var(--text-subtle); cursor: pointer;
    display: flex; align-items: center; justify-content: center;
}
.ts-chat-close:hover { background: var(--surface-2); color: var(--text); }
.ts-chat-close i, .ts-chat-close svg { width: 16px; height: 16px; }
.ts-chat-log {
    flex: 1; overflow-y: auto; padding: 14px;
    display: flex; flex-direction: column; gap: 10px;
    background: var(--bg);
}
.ts-msg { display: flex; }
.ts-msg.ts-bot { justify-content: flex-start; }
.ts-msg.ts-user { justify-content: flex-end; }
.ts-bubble {
    max-width: 86%; padding: 9px 12px; border-radius: 12px;
    font-size: 12.5px; line-height: 1.5; white-space: pre-line;
    word-break: break-word;
}
.ts-bot .ts-bubble {
    background: var(--surface); color: var(--text);
    border: 1px solid var(--border); border-bottom-left-radius: 4px;
}
.ts-user .ts-bubble {
    background: var(--primary); color: var(--primary-contrast);
    border-bottom-right-radius: 4px;
}
.ts-chat-link {
    display: inline-flex; align-items: center; gap: 6px;
    margin-top: 8px; padding: 6px 10px; border-radius: 8px;
    background: var(--surface-2); border: 1px solid var(--border);
    color: var(--text) !important; font-size: 12px; font-weight: 600;
    cursor: pointer; text-decoration: none !important;
}
.ts-chat-link:hover { border-color: var(--border-strong); }
.ts-chat-chips {
    display: flex; flex-wrap: wrap; gap: 6px; padding: 10px 14px;
    border-top: 1px solid var(--border); background: var(--surface);
    flex-shrink: 0;
}
.ts-chat-chips[hidden] { display: none !important; }
.ts-chat-chips button {
    font-size: 11.5px; padding: 5px 10px; border-radius: 99px;
    border: 1px solid var(--border); background: var(--surface);
    color: var(--text-muted); cursor: pointer;
}
.ts-chat-chips button:hover { border-color: var(--text); color: var(--text); }
.ts-chat-ticket {
    border-top: 1px solid var(--border); padding: 12px 14px;
    display: flex; flex-direction: column; gap: 8px; background: var(--surface);
    overflow-y: auto; flex-shrink: 0;
}
.ts-chat-ticket[hidden] { display: none !important; }
.ts-chat-ticket label {
    display: flex; flex-direction: column; gap: 4px;
    font-size: 11px; font-weight: 600; color: var(--text-muted);
}
.ts-chat-ticket input, .ts-chat-ticket textarea {
    width: 100%; border: 1px solid var(--border); border-radius: 8px;
    background: var(--surface-2); color: var(--text);
    font-size: 12px; padding: 7px 9px; outline: none; resize: vertical;
}
.ts-chat-ticket input:focus, .ts-chat-ticket textarea:focus { border-color: var(--text); }
.ts-ticket-actions { display: flex; gap: 8px; justify-content: flex-end; }
.ts-ticket-actions button {
    font-size: 12px; font-weight: 600; padding: 7px 12px; border-radius: 8px;
    cursor: pointer; border: 1px solid var(--border); background: var(--surface); color: var(--text-muted);
}
.ts-ticket-actions button[type=submit] {
    background: var(--primary); border-color: var(--primary); color: var(--primary-contrast);
}
.ts-ticket-actions button[type=submit]:disabled { opacity: .6; cursor: default; }
.ts-chat-compose {
    display: flex; gap: 8px; padding: 10px 12px;
    border-top: 1px solid var(--border); background: var(--surface); flex-shrink: 0;
}
.ts-chat-compose[hidden] { display: none !important; }
.ts-chat-compose input {
    flex: 1; height: 36px; border: 1px solid var(--border); border-radius: 9px;
    background: var(--surface-2); color: var(--text); font-size: 12.5px; padding: 0 10px; outline: none;
}
.ts-chat-compose input:focus { border-color: var(--text); }
.ts-chat-compose button {
    width: 36px; height: 36px; border-radius: 9px; border: 1px solid var(--primary);
    background: var(--primary); color: var(--primary-contrast); cursor: pointer;
    display: flex; align-items: center; justify-content: center;
}
.ts-chat-compose button i, .ts-chat-compose button svg { width: 16px; height: 16px; }
.ts-typing .ts-bubble { display: inline-flex; gap: 4px; align-items: center; }
.ts-typing span {
    width: 5px; height: 5px; border-radius: 50%; background: var(--text-subtle);
    animation: tsBlink 1s infinite ease-in-out;
}
.ts-typing span:nth-child(2) { animation-delay: .15s; }
.ts-typing span:nth-child(3) { animation-delay: .3s; }
@keyframes tsBlink { 0%, 80%, 100% { opacity: .25; } 40% { opacity: 1; } }
@media (max-width: 575px) {
    #tsChat { right: 14px; bottom: 14px; }
    .ts-chat-panel { width: calc(100vw - 28px); height: calc(100vh - 90px); max-height: none; }
}
</style>

<div id="tsChat" data-name="<?php echo htmlspecialchars($__chatName); ?>" data-email="<?php echo htmlspecialchars($__chatEmail); ?>" data-role="<?php echo htmlspecialchars($__chatRole); ?>">
    <button type="button" class="ts-chat-launcher" id="tsChatLauncher" aria-label="Open TechShift assistant" title="Need help?">
        <i class="fas fa-comments"></i>
        <span class="ts-chat-ping"></span>
    </button>

    <section class="ts-chat-panel" id="tsChatPanel" aria-label="TechShift assistant">
        <header class="ts-chat-head">
            <div class="ts-chat-avatar">T</div>
            <div class="ts-chat-title">
                <strong>TechShift Assistant</strong>
                <span id="tsChatSub">System help and support</span>
            </div>
            <button type="button" class="ts-chat-close" id="tsChatClose" aria-label="Close assistant">
                <i class="fas fa-xmark"></i>
            </button>
        </header>

        <div class="ts-chat-log" id="tsChatLog" role="log" aria-live="polite"></div>

        <div class="ts-chat-chips" id="tsChatChips">
            <button type="button" data-q="How do I make a sale?">Make a sale</button>
            <button type="button" data-q="How do I add a product?">Add a product</button>
            <button type="button" data-q="Where are the reports?">Reports</button>
            <button type="button" data-ticket="1">Raise a ticket</button>
        </div>

        <form class="ts-chat-ticket" id="tsChatTicket" hidden>
            <label>Reply email
                <input type="email" id="tsTicketEmail" maxlength="160" placeholder="you@example.com" value="<?php echo htmlspecialchars($__chatEmail); ?>" required>
            </label>
            <label>Subject
                <input type="text" id="tsTicketSubject" maxlength="180" placeholder="Short summary" required>
            </label>
            <label>Details
                <textarea id="tsTicketMessage" rows="3" maxlength="4000" placeholder="What is happening?" required></textarea>
            </label>
            <div class="ts-ticket-actions">
                <button type="button" id="tsTicketCancel">Cancel</button>
                <button type="submit" id="tsTicketSend">Send ticket</button>
            </div>
        </form>

        <form class="ts-chat-compose" id="tsChatCompose">
            <input type="text" id="tsChatInput" maxlength="300" placeholder="Ask about the system..." autocomplete="off" aria-label="Your message">
            <button type="submit" aria-label="Send message"><i class="fas fa-paper-plane"></i></button>
        </form>
    </section>
</div>

<script>
(function () {
    'use strict';

    var root      = document.getElementById('tsChat');
    if (!root) return;
    var launcher  = document.getElementById('tsChatLauncher');
    var closeBtn  = document.getElementById('tsChatClose');
    var logEl     = document.getElementById('tsChatLog');
    var chipsEl   = document.getElementById('tsChatChips');
    var composeEl = document.getElementById('tsChatCompose');
    var inputEl   = document.getElementById('tsChatInput');
    var ticketEl  = document.getElementById('tsChatTicket');
    var ticketEmail = document.getElementById('tsTicketEmail');
    var ticketSubject = document.getElementById('tsTicketSubject');
    var ticketMessage = document.getElementById('tsTicketMessage');
    var ticketSend = document.getElementById('tsTicketSend');
    var ticketCancel = document.getElementById('tsTicketCancel');
    var greeted = false;
    var name = root.getAttribute('data-name') || '';
    var ROLE = root.getAttribute('data-role') || '';
    var subEl = document.getElementById('tsChatSub');

    var NAV = [];
    document.querySelectorAll('.sidebar .nav-link').forEach(function (a) {
        var label = (a.textContent || '').replace(/\s+/g, ' ').trim();
        if (!label) return;
        var href = a.getAttribute('href');
        if (!href || href.indexOf('#') === 0 || href.indexOf('logout') !== -1) return;
        NAV.push({ label: label, href: a.href });
    });

    var KB = [
        { k: ['make a sale', 'sale', 'sell', 'checkout', 'point of sale', 'pos', 'customer buying'],
          a: 'To make a sale, open Point of sale, add products to the cart, pick the payment method and confirm. Stock and receipts update automatically.',
          links: ['Point of sale'] },
        { k: ['add product', 'new product', 'add item', 'create product', 'manage product', 'edit product'],
          a: 'Open Inventory and choose Add product. Enter the name, price, stock and barcode if you have one, then save.',
          links: ['Inventory', 'Products'] },
        { k: ['low stock', 'out of stock', 'reorder', 'stock alert', 'stock monitoring'],
          a: 'Low stock and Stock monitoring show items that fell below their reorder level. Update quantities from Inventory.',
          links: ['Low stock', 'Stock monitoring', 'Inventory'] },
        { k: ['report', 'reports', 'profit', 'sales report', 'summary', 'export'],
          a: 'Reports let you filter sales by date, cashier and payment method, then print or export the results.',
          links: ['Reports'] },
        { k: ['transaction', 'transactions', 'receipt', 'refund', 'void', 'history'],
          a: 'Transactions lists every sale. Open one to review it or print the receipt again.',
          links: ['Transactions'] },
        { k: ['user', 'users', 'staff', 'team', 'employee', 'cashier', 'permission', 'roles'],
          a: 'My team is where you create users, set their role and reset passwords.',
          links: ['My team', 'Users', 'Staff'] },
        { k: ['setting', 'settings', 'smtp', 'email', 'm-pesa', 'mpesa', 'payment', 'configure'],
          a: 'Settings holds store details, payments, M-Pesa and the SMTP email configuration.',
          links: ['Settings'] },
        { k: ['message', 'messages', 'inbox', 'notification', 'alert'],
          a: 'Messages and the bell icon show alerts from staff and the system.',
          links: ['Messages'] },
        { k: ['shift', 'shifts', 'cash drawer', 'open shift', 'close shift'],
          a: 'Shifts record who is on duty and the cash movements for each session.',
          links: ['Shifts'] },
        { k: ['barcode', 'scan', 'scanner'],
          a: 'Use the Barcode scanner page, or type the code straight into the search box on the Point of sale screen.',
          links: ['Barcode scanner'] },
        { k: ['price tag', 'price tags', 'label', 'tags'],
          a: 'Price tags prints shelf labels for the products you select.',
          links: ['Price tags', 'Tags'] },
        { k: ['signage', 'advert', 'advertisement', 'screen', 'display'],
          a: 'Digital signage manages the slideshows shown on customer screens.',
          links: ['Digital signage'] },
        { k: ['store', 'stores', 'branch', 'tenant'],
          a: 'Stores lets you manage branches. Super administrators manage tenants.',
          links: ['Stores', 'Tenants'] },
        { k: ['backup', 'restore', 'database', 'data'],
          a: 'A backup runs automatically when the system starts. Settings has the backup and restore tools.',
          links: ['Settings', 'System'] },
        { k: ['login', 'sign in', 'password', 'forgot'],
          a: 'Use Forgot password on the sign-in screen to reset it. An administrator can also reset a user password from My team.',
          links: ['My team'] }
    ];

    var ROLE_KB = [
        { roles: ['cashier'],
          k: ['remove item', 'cannot remove', 'remove product', 'delete item', 'approval', 'manager approval', 'cart item'],
          a: 'A product you add stays in the cart until a manager approves its removal. In the approval window, scan the manager barcode from Settings → Security or use the enrolled fingerprint. Once approved, remove or clear the cart as normal.',
          links: ['Point of sale', 'Settings'] },
        { roles: ['cashier', 'staff'],
          k: ['sale limit', 'cashier limit', 'exceed', 'maximum sale', 'high value sale', 'above limit', 'approval needed'],
          a: 'When a sale is above the cashier limit, the POS asks a manager to approve it before completing. The manager scans the approval barcode or confirms a fingerprint in the popup. Adjust the limit under Settings → Security → Point of sale controls.',
          links: ['Point of sale', 'Settings'] },
        { roles: ['cashier', 'staff'],
          k: ['fingerprint', 'biometric', 'windows hello', 'approve with fingerprint'],
          a: 'Fingerprint approval uses the device fingerprint reader (Windows Hello). An administrator enrolls it once under Settings → Security → Manager fingerprint. If the POS is opened on a plain network address, use the manager barcode instead.',
          links: ['Settings'] },
        { roles: ['cashier', 'staff'],
          k: ['approval barcode', 'manager barcode', 'barcode signup', 'create barcode', 'print barcode'],
          a: 'The manager approval barcode is created in Settings → Security → Approval barcode, then printed and kept with the manager. Scanning it at the POS approves a high-value sale or a cart removal.',
          links: ['Settings'] },
        { roles: ['staff'],
          k: ['stock count', 'adjust stock', 'stock take', 'damaged', 'expiry'],
          a: 'Update quantities from Inventory. Stock monitoring shows what is running low and the Low stock page lists items to reorder.',
          links: ['Inventory', 'Stock monitoring', 'Low stock'] },
        { roles: ['admin', 'super_admin'],
          k: ['security', 'security settings', 'two-factor', 'two factor', '2fa', 'one-time code', 'otp', 'fingerprint', 'approval barcode', 'barcode signup'],
          a: 'Open Settings → Security. There you can set the cashier sale limit, require approval to remove cart items, enroll the manager fingerprint, create and print the approval barcode, and turn on two-factor authentication for your account.',
          links: ['Settings'] },
        { roles: ['admin'],
          k: ['remove approval', 'cart removal', 'cashier limit', 'sale approval'],
          a: 'Settings → Security → Point of sale controls holds the cashier sale limit and the “Require approval to remove a cart item” switch. Approval works through the manager barcode or the enrolled fingerprint.',
          links: ['Settings'] },
        { roles: ['admin', 'super_admin'],
          k: ['two-factor not sending', 'no code', 'sms not working', 'email not sending', 'otp missing'],
          a: 'Two-factor email codes need the SMTP settings under Email; SMS codes need the provider and API key under SMS. Test those first, then enable two-factor under Settings → Security.',
          links: ['Settings'] },
        { roles: ['super_admin'],
          k: ['tenant', 'tenants', 'platform', 'all users', 'audit log', 'audit logs', 'system health'],
          a: 'Use Tenants to review every business on the platform, All Users for accounts, System for installation details and Audit Logs for activity.',
          links: ['Tenants', 'All Users', 'System', 'Audit Logs'] },
        { roles: ['customer'],
          k: ['my orders', 'order history', 'my account', 'my details', 'password'],
          a: 'Your account page shows your details and recent orders. Use Forgot password on the sign-in screen if you cannot get in.',
          links: ['Account', 'Dashboard'] }
    ];

    ROLE_KB.forEach(function (entry) {
        if (!entry.roles || entry.roles.indexOf(ROLE) !== -1) KB.push(entry);
    });

    var ROLE_UI = {
        cashier: {
            sub: 'Cashier help',
            hello: ' I can help with sales, scanning, approvals and shifts.',
            chips: [
                { q: 'How do I make a sale?', l: 'Make a sale' },
                { q: 'Why can I not remove an item?', l: 'Remove an item' },
                { q: 'What is the cashier sale limit?', l: 'Sale limit' },
                { ticket: 1, l: 'Raise a ticket' }
            ]
        },
        staff: {
            sub: 'Staff help',
            hello: ' I can help with inventory, stock, the POS and reports.',
            chips: [
                { q: 'How do I add a product?', l: 'Add a product' },
                { q: 'Why does removing an item need approval?', l: 'Cart approval' },
                { q: 'Where are the reports?', l: 'Reports' },
                { ticket: 1, l: 'Raise a ticket' }
            ]
        },
        admin: {
            sub: 'Manager help',
            hello: ' I can help with inventory, teams, reports and the new Security settings.',
            chips: [
                { q: 'Open security settings', l: 'Security settings' },
                { q: 'How do I enable two-factor authentication?', l: 'Enable 2FA' },
                { q: 'How do I create the approval barcode?', l: 'Approval barcode' },
                { ticket: 1, l: 'Raise a ticket' }
            ]
        },
        super_admin: {
            sub: 'Platform help',
            hello: ' I can help with tenants, users, system tools and audit logs.',
            chips: [
                { q: 'How do I manage tenants?', l: 'Tenants' },
                { q: 'Where are the audit logs?', l: 'Audit logs' },
                { q: 'System status', l: 'System' },
                { ticket: 1, l: 'Raise a ticket' }
            ]
        },
        customer: {
            sub: 'Account help',
            hello: ' I can help with your account and orders.',
            chips: [
                { q: 'Where are my orders?', l: 'My orders' },
                { q: 'How do I update my details?', l: 'My account' },
                { ticket: 1, l: 'Raise a ticket' }
            ]
        }
    };

    function applyRoleUi() {
        var ui = ROLE_UI[ROLE];
        if (!ui) return;
        if (subEl) subEl.textContent = ui.sub;
        chipsEl.innerHTML = ui.chips.map(function (c) {
            if (c.ticket) {
                return '<button type="button" data-ticket="1">' + c.l + '</button>';
            }
            return '<button type="button" data-q="' + c.q.replace(/"/g, '&quot;') + '">' + c.l + '</button>';
        }).join('');
    }
    applyRoleUi();

    function addBubble(text, isUser, link) {
        var wrap = document.createElement('div');
        wrap.className = 'ts-msg ' + (isUser ? 'ts-user' : 'ts-bot');
        var bubble = document.createElement('div');
        bubble.className = 'ts-bubble';
        bubble.textContent = text;
        wrap.appendChild(bubble);

        if (link) {
            var el;
            if (link.action === 'ticket') {
                el = document.createElement('button');
                el.type = 'button';
                el.className = 'ts-chat-link';
                el.style.marginLeft = '8px';
                el.textContent = 'Raise a ticket';
                el.addEventListener('click', startTicket);
            } else if (link.href) {
                el = document.createElement('a');
                el.className = 'ts-chat-link';
                el.href = link.href;
                el.textContent = 'Open ' + link.label;
            }
            if (el) bubble.appendChild(el);
        }

        logEl.appendChild(wrap);
        logEl.scrollTop = logEl.scrollHeight;
    }

    function showTyping() {
        var wrap = document.createElement('div');
        wrap.className = 'ts-msg ts-bot ts-typing';
        wrap.innerHTML = '<div class="ts-bubble"><span></span><span></span><span></span></div>';
        logEl.appendChild(wrap);
        logEl.scrollTop = logEl.scrollHeight;
        return wrap;
    }

    function pickLink(names) {
        for (var i = 0; i < names.length; i++) {
            var want = names[i].toLowerCase();
            for (var j = 0; j < NAV.length; j++) {
                var label = NAV[j].label.toLowerCase();
                if (label === want || label.indexOf(want) !== -1 || want.indexOf(label) !== -1) {
                    return { label: NAV[j].label, href: NAV[j].href };
                }
            }
        }
        return null;
    }

    function startTicket() {
        greeted = true;
        ticketEl.hidden = false;
        composeEl.hidden = true;
        chipsEl.hidden = true;
        if (!ticketEmail.value) ticketEmail.value = root.getAttribute('data-email') || '';
        ticketSubject.focus();
    }

    function stopTicket() {
        ticketEl.hidden = true;
        composeEl.hidden = false;
        chipsEl.hidden = false;
        inputEl.focus();
    }

    function answer(question) {
        var q = question.toLowerCase();
        if (/(ticket|support|contact|human|agent|complain)/.test(q)) {
            startTicket();
            addBubble('Tell me what happened and the best email to reply to, then press Send ticket. Your message goes straight to the support inbox.');
            return;
        }

        var best = null;
        var bestScore = 0;
        KB.forEach(function (entry) {
            var score = 0;
            entry.k.forEach(function (key) {
                if (q.indexOf(key) !== -1) {
                    score += key.length * 2;
                    return;
                }
                var parts = key.split(' ');
                if (parts.length > 1 && parts.every(function (p) { return q.indexOf(p) !== -1; })) {
                    score += key.length;
                }
            });
            if (score > bestScore) { bestScore = score; best = entry; }
        });

        var typing = showTyping();
        window.setTimeout(function () {
            typing.remove();
            if (best) {
                addBubble(best.a, false, pickLink(best.links || []));
                return;
            }
            var nav = pickLink([q.trim()]);
            if (nav) {
                addBubble('Opening ' + nav.label + '.', false, nav);
                return;
            }
            addBubble('I am still learning that one. Ask me about sales, inventory, reports, users or settings, or send a support ticket.', false, { action: 'ticket' });
        }, 260);
    }

    function ask(text) {
        text = (text || '').trim();
        if (!text) return;
        addBubble(text, true);
        answer(text);
    }

    function openChat() {
        root.classList.add('is-open');
        if (!greeted) {
            greeted = true;
            var hello = name ? ('Hi ' + name + '.') : 'Hi there.';
            var ui = ROLE_UI[ROLE];
            var tail = ui ? ui.hello : ' I can help you move around the system or raise a support ticket.';
            addBubble(hello + tail + ' What do you need?');
        }
        window.setTimeout(function () { inputEl.focus(); }, 60);
    }

    function closeChat() {
        root.classList.remove('is-open');
        launcher.focus();
    }

    launcher.addEventListener('click', openChat);
    closeBtn.addEventListener('click', closeChat);
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && root.classList.contains('is-open')) closeChat();
    });

    chipsEl.addEventListener('click', function (e) {
        var btn = e.target.closest('button');
        if (!btn) return;
        if (btn.getAttribute('data-ticket')) { startTicket(); return; }
        var q = btn.getAttribute('data-q');
        if (q) ask(q);
    });

    composeEl.addEventListener('submit', function (e) {
        e.preventDefault();
        var text = inputEl.value;
        inputEl.value = '';
        ask(text);
    });

    ticketCancel.addEventListener('click', stopTicket);

    ticketEl.addEventListener('submit', function (e) {
        e.preventDefault();
        var email = ticketEmail.value.trim();
        var subject = ticketSubject.value.trim();
        var message = ticketMessage.value.trim();
        if (!email || !subject || !message) {
            addBubble('Please fill in your email, a subject and the details.');
            return;
        }

        ticketSend.disabled = true;
        ticketSend.textContent = 'Sending...';
        var typing = showTyping();

        fetch('/pos/support_ticket.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ email: email, subject: subject, message: message, page: location.pathname })
        }).then(function (r) { return r.json(); }).then(function (data) {
            typing.remove();
            if (data && data.success) {
                addBubble('Ticket ' + data.ticket + ' created. ' + (data.email_sent
                    ? 'A copy was emailed to support.'
                    : 'The email service is not configured yet, so the ticket was saved for follow-up.'));
                ticketSubject.value = '';
                ticketMessage.value = '';
                stopTicket();
            } else {
                addBubble((data && data.message) || 'Sorry, the ticket could not be sent. Please try again.');
            }
        }).catch(function () {
            typing.remove();
            addBubble('Sorry, the ticket could not be sent. Check your connection and try again.');
        }).finally(function () {
            ticketSend.disabled = false;
            ticketSend.textContent = 'Send ticket';
        });
    });
})();
</script>
