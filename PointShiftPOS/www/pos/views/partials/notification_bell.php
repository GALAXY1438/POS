<?php
/**
 * Notification bell + live panel. Included by the admin and staff layouts.
 *
 * Expects $_SESSION, $db and the NotificationService autoloader (config.php).
 */
$notifRole    = $_SESSION['role'] ?? 'staff';
$notifIsStaff = $notifRole === ROLE_STAFF;
$notifStoreId = $notifRole === ROLE_SUPER_ADMIN ? 0 : (int)($_SESSION['store_id'] ?? 1);

$notifItems    = [];
$notifUnread   = 0;
$notifMessages = 0;

try {
    NotificationService::sync($notifStoreId, false);
    $notifItems  = NotificationService::latest($notifStoreId, 12);
    $notifUnread = NotificationService::unreadCount($notifStoreId);
} catch (Throwable $e) {
    // Notifications are non-critical: never break the page.
}

try {
    $ndb = $db ?? Database::getInstance()->getConnection();
    $mq  = $ndb->prepare('SELECT COUNT(*) FROM messages WHERE recipient_id = ? AND is_read = 0');
    $mq->execute([(int)($_SESSION['user_id'] ?? 0)]);
    $notifMessages = (int)$mq->fetchColumn();
} catch (Throwable $e) {
    $notifMessages = 0;
}

$notifTotal      = $notifUnread + $notifMessages;
$notifSettings   = $notifIsStaff ? '/pos/staff/low_stock_alerts.php' : '/pos/settings.php?tab=sms';
$notifMessagesHref = $notifIsStaff ? '/pos/staff/email.php' : '/pos/messages.php';

$notifLink = static function (?string $link) use ($notifIsStaff): string {
    if (!$notifIsStaff || !$link) {
        return (string)$link;
    }
    if (str_contains($link, 'stock_filter=out_of_stock')) {
        return '/pos/staff/low_stock_alerts.php';
    }
    if (str_contains($link, 'inventory.php')) {
        return '/pos/staff/low_stock_alerts.php';
    }
    return $link;
};

$notifIcon = static function (string $type): array {
    return match ($type) {
        'out_of_stock' => ['fa-box-open', 'danger'],
        'low_stock'    => ['fa-arrow-trend-down', 'warning'],
        'expired'      => ['fa-calendar-xmark', 'danger'],
        'expiring'     => ['fa-calendar-day', 'warning'],
        default        => ['fa-circle-info', 'info'],
    };
};
?>
<style>
.notif-center { position: relative; }
.notif-bell-btn {
    position: relative; width: 38px; height: 38px; border-radius: 50%;
    background: transparent; border: none; display: flex; align-items: center; justify-content: center;
    transition: background .18s; color: var(--text-secondary);
}
.notif-bell-btn:hover, .notif-bell-btn[aria-expanded="true"] { background: var(--bg-body); color: var(--text-primary); }
.notif-bell-btn i { font-size: 1rem; }
.notif-badge {
    position: absolute; top: 0; right: 0; min-width: 17px; height: 17px; padding: 0 4px;
    background: var(--danger); color: #fff; border: 2px solid var(--bg-surface);
    border-radius: 99px; font-size: .58rem; font-weight: 700;
    display: flex; align-items: center; justify-content: center;
}
.notif-menu {
    width: 380px; max-width: calc(100vw - 24px); padding: 0; border: 1px solid var(--border-color);
    border-radius: var(--radius-lg); overflow: hidden; box-shadow: 0 18px 50px rgba(0,0,0,.18);
}
.notif-head {
    display: flex; align-items: center; justify-content: space-between; gap: 10px;
    padding: 12px 14px; border-bottom: 1px solid var(--border-color); background: var(--bg-surface);
}
.notif-head-title { display: flex; flex-direction: column; gap: 1px; }
.notif-head-title strong { font-size: .86rem; color: var(--text-primary); }
.notif-head-title span { font-size: .68rem; color: var(--text-muted); }
.notif-mark-all {
    border: none; background: transparent; color: var(--text-secondary);
    font-size: .7rem; font-weight: 600; padding: 4px 6px; border-radius: 6px; white-space: nowrap;
}
.notif-mark-all:hover { background: var(--bg-body); color: var(--text-primary); }
.notif-list { max-height: 400px; overflow-y: auto; background: var(--bg-surface); }
.notif-item {
    display: flex; align-items: flex-start; gap: 10px; padding: 11px 14px;
    border-bottom: 1px solid var(--border-light); text-decoration: none; color: inherit;
    position: relative; transition: background .12s;
}
.notif-item:last-child { border-bottom: none; }
.notif-item:hover { background: var(--bg-body); }
.notif-item.unread { background: rgba(74,222,128,.045); }
.notif-item.unread::before {
    content: ''; position: absolute; left: 0; top: 0; bottom: 0; width: 3px;
    background: var(--accent-dim, #22c55e);
}
.notif-ic {
    width: 34px; height: 34px; border-radius: 9px; flex-shrink: 0;
    display: flex; align-items: center; justify-content: center; font-size: .82rem;
}
.notif-ic.danger  { background: rgba(239,68,68,.11);  color: #dc2626; }
.notif-ic.warning { background: rgba(245,158,11,.13); color: #b45309; }
.notif-ic.info    { background: rgba(59,130,246,.11); color: #2563eb; }
.notif-body { flex: 1; min-width: 0; }
.notif-title {
    font-size: .79rem; font-weight: 600; color: var(--text-primary);
    line-height: 1.3; margin-bottom: 2px;
    overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
}
.notif-msg {
    font-size: .71rem; color: var(--text-secondary); line-height: 1.4;
    display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden;
}
.notif-meta { display: flex; align-items: center; gap: 6px; margin-top: 4px; }
.notif-time { font-size: .64rem; color: var(--text-muted); }
.notif-kind {
    font-size: .58rem; font-weight: 700; text-transform: uppercase; letter-spacing: .4px;
    color: var(--text-muted);
}
.notif-read-btn {
    flex-shrink: 0; width: 22px; height: 22px; border-radius: 50%;
    border: 1px solid var(--border-color); background: var(--bg-surface); color: var(--text-muted);
    display: flex; align-items: center; justify-content: center; font-size: .6rem;
    opacity: 0; transition: opacity .12s, background .12s;
}
.notif-item:hover .notif-read-btn { opacity: 1; }
.notif-read-btn:hover { background: var(--bg-body); color: var(--text-primary); }
.notif-empty { padding: 34px 18px; text-align: center; color: var(--text-muted); }
.notif-empty i { display: block; font-size: 1.7rem; opacity: .28; margin-bottom: 8px; }
.notif-empty p { margin: 0; font-size: .78rem; }
.notif-empty small { font-size: .68rem; opacity: .8; }
.notif-foot {
    display: flex; align-items: center; justify-content: space-between;
    padding: 9px 14px; border-top: 1px solid var(--border-color); background: var(--bg-body);
}
.notif-foot a { font-size: .7rem; font-weight: 600; color: var(--text-secondary); text-decoration: none; }
.notif-foot a:hover { color: var(--text-primary); }
.notif-live { display: inline-flex; align-items: center; gap: 5px; font-size: .64rem; color: var(--text-muted); }
.notif-live .dot {
    width: 6px; height: 6px; border-radius: 50%; background: #22c55e;
    box-shadow: 0 0 0 0 rgba(34,197,94,.5); animation: notifPulse 2.2s infinite;
}
@keyframes notifPulse {
    0% { box-shadow: 0 0 0 0 rgba(34,197,94,.45); }
    70% { box-shadow: 0 0 0 6px rgba(34,197,94,0); }
    100% { box-shadow: 0 0 0 0 rgba(34,197,94,0); }
}
@media (max-width: 575px) {
    .notif-menu { width: calc(100vw - 20px); }
}
</style>

<div class="notification-bell dropdown notif-center" id="notifCenter">
    <button class="notif-bell-btn" type="button" id="notifBellBtn"
            data-bs-toggle="dropdown" data-bs-auto-close="outside"
            aria-expanded="false" title="Notifications">
        <i class="fas fa-bell"></i>
        <span class="notif-badge" id="notifBadge" <?php echo $notifTotal > 0 ? '' : 'style="display:none;"'; ?>>
            <?php echo $notifTotal > 99 ? '99+' : $notifTotal; ?>
        </span>
    </button>

    <div class="dropdown-menu dropdown-menu-end notif-menu" aria-labelledby="notifBellBtn">
        <div class="notif-head">
            <div class="notif-head-title">
                <strong>Notifications</strong>
                <span id="notifHeadSub"><?php echo $notifTotal > 0 ? $notifTotal . ' need attention' : 'You are all caught up'; ?></span>
            </div>
            <button type="button" class="notif-mark-all" id="notifMarkAll" <?php echo $notifTotal > 0 ? '' : 'disabled style="opacity:.45;"'; ?>>
                <i class="fas fa-check-double me-1"></i>Mark all read
            </button>
        </div>

        <div class="notif-list" id="notifList">
            <?php if (empty($notifItems)): ?>
                <div class="notif-empty">
                    <i class="fas fa-bell-slash"></i>
                    <p>No notifications</p>
                    <small>Stock and expiry alerts will appear here.</small>
                </div>
            <?php else: ?>
                <?php foreach ($notifItems as $n):
                    [$nic, $nsev] = $notifIcon((string)$n['type']);
                ?>
                <a class="notif-item <?php echo (int)$n['is_read'] === 0 ? 'unread' : ''; ?>"
                   href="<?php echo htmlspecialchars($notifLink($n['link'] ?? '')); ?>"
                   data-notif-id="<?php echo (int)$n['id']; ?>">
                    <span class="notif-ic <?php echo $nsev; ?>"><i class="fas <?php echo $nic; ?>"></i></span>
                    <span class="notif-body">
                        <span class="notif-title"><?php echo htmlspecialchars($n['title']); ?></span>
                        <span class="notif-msg"><?php echo htmlspecialchars($n['message']); ?></span>
                        <span class="notif-meta">
                            <span class="notif-time"><?php echo htmlspecialchars($n['time_ago']); ?></span>
                            <span class="notif-kind"><?php echo htmlspecialchars(str_replace('_', ' ', $n['type'])); ?></span>
                        </span>
                    </span>
                    <?php if ((int)$n['is_read'] === 0): ?>
                    <span class="notif-read-btn" data-notif-read="<?php echo (int)$n['id']; ?>" title="Mark as read">
                        <i class="fas fa-check"></i>
                    </span>
                    <?php endif; ?>
                </a>
                <?php endforeach; ?>
            <?php endif; ?>

            <?php if ($notifMessages > 0): ?>
            <a class="notif-item unread" href="<?php echo $notifMessagesHref; ?>">
                <span class="notif-ic info"><i class="fas fa-envelope"></i></span>
                <span class="notif-body">
                    <span class="notif-title">Unread messages</span>
                    <span class="notif-msg">You have <?php echo $notifMessages; ?> unread message<?php echo $notifMessages === 1 ? '' : 's'; ?>.</span>
                    <span class="notif-meta"><span class="notif-time">Open messages to read</span></span>
                </span>
            </a>
            <?php endif; ?>
        </div>

        <div class="notif-foot">
            <a href="<?php echo $notifSettings; ?>"><i class="fas fa-sliders me-1"></i>Alert settings</a>
            <span class="notif-live"><span class="dot"></span>Live</span>
        </div>
    </div>
</div>

<script>
window.POS_NOTIFICATIONS = <?php echo json_encode([
    'api'          => '/pos/api/notifications.php',
    'items'        => $notifItems,
    'unread'       => $notifUnread,
    'messages'     => $notifMessages,
    'isStaff'      => $notifIsStaff,
    'settingsUrl'  => $notifSettings,
    'messagesUrl'  => $notifMessagesHref,
], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

(function () {
    var cfg = window.POS_NOTIFICATIONS;
    if (!cfg) return;

    var badge   = document.getElementById('notifBadge');
    var list    = document.getElementById('notifList');
    var headSub = document.getElementById('notifHeadSub');
    var markAll = document.getElementById('notifMarkAll');
    var bellBtn = document.getElementById('notifBellBtn');
    var menu    = document.getElementById('notifCenter');

    var icons = {
        out_of_stock: ['fa-box-open', 'danger'],
        low_stock:    ['fa-arrow-trend-down', 'warning'],
        expired:      ['fa-calendar-xmark', 'danger'],
        expiring:     ['fa-calendar-day', 'warning'],
        system:       ['fa-circle-info', 'info']
    };

    function esc(v) { return String(v == null ? '' : v); }

    function el(tag, cls, text) {
        var n = document.createElement(tag);
        if (cls) n.className = cls;
        if (text != null) n.textContent = text;
        return n;
    }

    function linkFor(link) {
        if (!cfg.isStaff || !link) return link || '#';
        if (String(link).indexOf('stock_filter=out_of_stock') !== -1) return '/pos/staff/low_stock_alerts.php';
        if (String(link).indexOf('inventory.php') !== -1) return '/pos/staff/low_stock_alerts.php';
        return link;
    }

    function render(data) {
        var total = (data.unread || 0) + (data.messages || 0);
        if (badge) {
            badge.textContent = total > 99 ? '99+' : String(total);
            badge.style.display = total > 0 ? 'flex' : 'none';
        }
        if (headSub) {
            headSub.textContent = total > 0 ? total + ' need attention' : 'You are all caught up';
        }
        if (markAll) {
            markAll.disabled = (data.unread || 0) === 0;
            markAll.style.opacity = (data.unread || 0) === 0 ? '.45' : '';
        }

        if (!list) return;
        list.innerHTML = '';

        var items = data.items || [];
        if (!items.length && !(data.messages > 0)) {
            var empty = el('div', 'notif-empty');
            var ic = el('i', 'fas fa-bell-slash');
            empty.appendChild(ic);
            empty.appendChild(el('p', '', 'No notifications'));
            empty.appendChild(el('small', '', 'Stock and expiry alerts will appear here.'));
            list.appendChild(empty);
            return;
        }

        items.forEach(function (n) {
            var a = el('a', 'notif-item' + (Number(n.is_read) === 0 ? ' unread' : ''));
            a.href = linkFor(n.link);
            a.setAttribute('data-notif-id', n.id);

            var map = icons[n.type] || icons.system;
            var icBox = el('span', 'notif-ic ' + map[1]);
            var i = el('i', 'fas ' + map[0]);
            icBox.appendChild(i);

            var body = el('span', 'notif-body');
            body.appendChild(el('span', 'notif-title', esc(n.title)));
            body.appendChild(el('span', 'notif-msg', esc(n.message)));

            var meta = el('span', 'notif-meta');
            meta.appendChild(el('span', 'notif-time', esc(n.time_ago)));
            meta.appendChild(el('span', 'notif-kind', String(n.type || '').replace(/_/g, ' ')));
            body.appendChild(meta);

            a.appendChild(icBox);
            a.appendChild(body);

            if (Number(n.is_read) === 0) {
                var btn = el('span', 'notif-read-btn');
                btn.setAttribute('data-notif-read', n.id);
                btn.title = 'Mark as read';
                var bi = el('i', 'fas fa-check');
                btn.appendChild(bi);
                a.appendChild(btn);
            }
            list.appendChild(a);
        });

        if (data.messages > 0) {
            var ma = el('a', 'notif-item unread');
            ma.href = cfg.messagesUrl || '#';
            var mic = el('span', 'notif-ic info');
            mic.appendChild(el('i', 'fas fa-envelope'));
            var mbody = el('span', 'notif-body');
            mbody.appendChild(el('span', 'notif-title', 'Unread messages'));
            mbody.appendChild(el('span', 'notif-msg', 'You have ' + data.messages + ' unread message' + (data.messages === 1 ? '' : 's') + '.'));
            var mmeta = el('span', 'notif-meta');
            mmeta.appendChild(el('span', 'notif-time', 'Open messages to read'));
            mbody.appendChild(mmeta);
            ma.appendChild(mic);
            ma.appendChild(mbody);
            list.appendChild(ma);
        }
    }

    function request(action, id) {
        var url = cfg.api + '?action=' + encodeURIComponent(action) + '&_=' + Date.now();
        var opts = { credentials: 'same-origin', headers: { 'Accept': 'application/json' } };
        if (action === 'read' || action === 'mark_all') {
            url = cfg.api;
            opts.method = 'POST';
            opts.headers['Content-Type'] = 'application/x-www-form-urlencoded';
            opts.body = 'action=' + encodeURIComponent(action) + '&id=' + encodeURIComponent(id || 0);
        }
        return fetch(url, opts).then(function (r) {
            if (!r.ok) throw new Error('HTTP ' + r.status);
            return r.json();
        });
    }

    function refresh() {
        return request('poll').then(function (data) {
            if (data && data.ok) render(data);
        }).catch(function () { /* offline / server restart */ });
    }

    if (list) {
        list.addEventListener('click', function (e) {
            var readBtn = e.target.closest('[data-notif-read]');
            if (readBtn) {
                e.preventDefault();
                e.stopPropagation();
                request('read', readBtn.getAttribute('data-notif-read')).then(refresh);
                return;
            }
            var item = e.target.closest('.notif-item[data-notif-id]');
            if (item && item.classList.contains('unread')) {
                request('read', item.getAttribute('data-notif-id')).catch(function () {});
            }
        });
    }

    if (markAll) {
        markAll.addEventListener('click', function (e) {
            e.preventDefault();
            request('mark_all').then(refresh);
        });
    }

    if (bellBtn) {
        bellBtn.addEventListener('show.bs.dropdown', refresh);
    }
    if (menu) {
        menu.addEventListener('hidden.bs.dropdown', function () { refresh(); });
    }

    document.addEventListener('visibilitychange', function () {
        if (!document.hidden) refresh();
    });

    setInterval(refresh, 25000);
    setTimeout(refresh, 1500);
})();
</script>
