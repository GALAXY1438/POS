/* TechShift POS — icon shim.
   Replaces Font Awesome <i class="fa..."> glyphs with Lucide outline SVGs,
   keeping the original <i> wrapper so existing layout CSS still applies. */
(function () {
    'use strict';

    var MAP = {
        'store': 'Store', 'store-alt': 'Store', 'plus': 'Plus', 'minus': 'Minus',
        'info-circle': 'Info', 'circle-info': 'Info',
        'print': 'Printer', 'eye': 'Eye', 'eye-slash': 'EyeOff',
        'check-circle': 'CircleCheck', 'circle-check': 'CircleCheck', 'check': 'Check', 'check-double': 'CheckCheck',
        'envelope': 'Mail', 'envelope-open-text': 'MailOpen', 'envelope-circle-check': 'MailCheck',
        'credit-card': 'CreditCard', 'paper-plane': 'Send', 'clock': 'Clock',
        'barcode': 'Barcode', 'save': 'Save', 'users': 'Users', 'users-cog': 'UsersRound',
        'key': 'Key', 'chart-bar': 'ChartColumn', 'chart-column': 'ChartColumn', 'chart-line': 'ChartLine',
        'pen': 'Pencil', 'edit': 'Pencil', 'magnifying-glass': 'Search', 'search': 'Search',
        'calendar-alt': 'CalendarDays', 'calendar': 'Calendar', 'calendar-day': 'CalendarDays',
        'calendar-days': 'CalendarDays', 'calendar-check': 'CalendarCheck', 'calendar-xmark': 'CalendarX',
        'calendar-times': 'CalendarX', 'calendar-plus': 'CalendarPlus', 'calendar-week': 'CalendarRange',
        'circle-dot': 'CircleDot', 'box-open': 'PackageOpen', 'receipt': 'Receipt', 'layer-group': 'Layers',
        'user': 'User', 'user-circle': 'CircleUser', 'circle-user': 'CircleUser',
        'user-plus': 'UserPlus', 'user-edit': 'UserPen', 'user-cog': 'UserCog', 'user-tie': 'UserRound',
        'trash': 'Trash2', 'trash-can': 'Trash2', 'spinner': 'Loader',
        'mobile-screen': 'Smartphone', 'mobile-alt': 'Smartphone',
        'inbox': 'Inbox', 'arrow-left': 'ArrowLeft', 'arrow-right': 'ArrowRight', 'arrow-down': 'ArrowDown',
        'money-bill-wave': 'Banknote', 'id-badge': 'IdCard', 'bars': 'Menu',
        'xmark': 'X', 'times': 'X', 'times-circle': 'CircleX', 'circle-xmark': 'CircleX',
        'star': 'Star', 'lock': 'Lock', 'file-lines': 'FileText', 'file-alt': 'FileText',
        'file-invoice': 'FileText', 'file-code': 'FileCode',
        'exclamation-triangle': 'TriangleAlert', 'triangle-exclamation': 'TriangleAlert',
        'exclamation-circle': 'CircleAlert', 'circle-exclamation': 'CircleAlert',
        'bell': 'Bell', 'bell-slash': 'BellOff',
        'arrow-right-from-bracket': 'LogOut', 'sign-out-alt': 'LogOut', 'sign-in-alt': 'LogIn',
        'tv': 'Tv', 'table-cells-large': 'LayoutGrid', 'sliders': 'SlidersHorizontal',
        'sliders-h': 'SlidersHorizontal', 'shopping-cart': 'ShoppingCart', 'cart-shopping': 'ShoppingCart',
        'shopping-bag': 'ShoppingBag', 'map-marker-alt': 'MapPin', 'location-dot': 'MapPin',
        'image': 'Image', 'coins': 'Coins', 'circle-half-stroke': 'SunMoon',
        'chevron-right': 'ChevronRight', 'chevron-left': 'ChevronLeft',
        'chevron-up': 'ChevronUp', 'chevron-down': 'ChevronDown',
        'tags': 'Tags', 'tag': 'Tag', 'hard-hat': 'HardHat', 'download': 'Download', 'upload': 'Upload',
        'comments': 'MessagesSquare', 'comment-sms': 'MessageSquareText', 'sms': 'MessageSquareText',
        'cash-register': 'Store', 'boxes': 'Boxes', 'boxes-stacked': 'Boxes', 'box': 'Box',
        'rotate-left': 'RotateCcw', 'rotate-right': 'RotateCw', 'rotate': 'RotateCw',
        'phone': 'Phone', 'moon': 'Moon', 'sun': 'Sun', 'dollar-sign': 'DollarSign', 'peso-sign': 'PhilippinePeso',
        'building': 'Building2', 'arrow-trend-down': 'TrendingDown', 'arrow-trend-up': 'TrendingUp',
        'youtube': 'Youtube', 'vial': 'FlaskConical', 'flask': 'FlaskConical', 'truck': 'Truck',
        'shield': 'ShieldCheck', 'reply': 'Reply', 'redo': 'Redo2', 'history': 'History',
        'clock-rotate-left': 'History', 'hard-drive': 'HardDrive', 'hand-holding-dollar': 'HandCoins',
        'gauge': 'Gauge', 'tachometer-alt': 'Gauge', 'folder-open': 'FolderOpen', 'folder': 'Folder',
        'filter': 'Filter', 'expand': 'Expand', 'delete-left': 'Delete', 'cog': 'Settings', 'gear': 'Settings',
        'clipboard-list': 'ClipboardList', 'chart-pie': 'ChartPie', 'bolt': 'Zap',
        'wallet': 'Wallet', 'toggle-on': 'ToggleRight', 'server': 'Server', 'rocket': 'Rocket',
        'plus-circle': 'CirclePlus', 'percent': 'Percent', 'list-check': 'ListChecks', 'list': 'List',
        'lightbulb': 'Lightbulb', 'keyboard': 'Keyboard', 'home': 'House', 'globe': 'Globe',
        'fire': 'Flame', 'external-link-alt': 'ExternalLink', 'exchange-alt': 'ArrowLeftRight',
        'database': 'Database', 'copyright': 'Copyright', 'calculator': 'Calculator', 'at': 'AtSign'
    };

    var MODIFIERS = { 'fa': 1, 'fas': 1, 'far': 1, 'fab': 1, 'fat': 1, 'fa-solid': 1, 'fa-regular': 1, 'fa-brands': 1, 'fa-light': 1 };
    var SIZES = { 'fa-2x': 2, 'fa-3x': 3, 'fa-4x': 4, 'fa-5x': 5, 'fa-lg': 1.33, 'fa-sm': 0.875, 'fa-xs': 0.75 };

    function pascalize(name) {
        var out = '';
        name.split('-').forEach(function (p) { if (p) out += p.charAt(0).toUpperCase() + p.slice(1); });
        return out;
    }

    function resolve(el) {
        var cls = el.classList;
        for (var i = 0; i < cls.length; i++) {
            if (cls[i].indexOf('fa-') !== 0) continue;
            if (SIZES[cls[i]]) continue;
            var lucide = window.lucide;
            var key = MAP[cls[i].substr(3)];
            if (key && lucide.icons[key]) return key;
            var guess = pascalize(cls[i].substr(3));
            if (lucide.icons[guess]) return guess;
        }
        return null;
    }

    function render(el) {
        var lucide = window.lucide;
        if (!lucide || !lucide.createElement) return;
        var key = resolve(el);
        if (!key) { el.style.visibility = 'hidden'; return; }

        var svg;
        try { svg = lucide.createElement(lucide.icons[key]); } catch (e) { return; }
        svg.setAttribute('width', '1em');
        svg.setAttribute('height', '1em');
        svg.setAttribute('aria-hidden', 'true');
        svg.style.verticalAlign = '-0.125em';

        var spin = el.classList.contains('fa-spin') || el.classList.contains('fa-pulse');
        if (spin) { svg.style.animation = 'icon-spin 1s linear infinite'; }

        var scale = 1;
        el.classList.forEach(function (c) { if (SIZES[c]) scale = SIZES[c]; });
        if (scale !== 1) el.style.fontSize = scale + 'em';

        // Strip Font Awesome classes, keep layout helpers (me-2, ms-1, ...)
        Array.prototype.slice.call(el.classList).forEach(function (c) {
            if (c.indexOf('fa-') === 0 || MODIFIERS[c]) el.classList.remove(c);
        });
        el.classList.add('lucide-icon');
        el.innerHTML = '';
        el.appendChild(svg);
    }

    function scan(root) {
        if (!root || root.nodeType !== 1) return;
        if (root.matches && root.matches('i')) { render(root); return; }
        var nodes = root.querySelectorAll ? root.querySelectorAll('i') : [];
        Array.prototype.forEach.call(nodes, function (el) {
            if (el.firstElementChild && el.firstElementChild.tagName &&
                el.firstElementChild.tagName.toLowerCase() === 'svg') return;
            render(el);
        });
    }

    function start() {
        if (!window.lucide) return;
        scan(document.body);
        if (window.MutationObserver) {
            new MutationObserver(function (records) {
                records.forEach(function (r) {
                    if (r.type === 'attributes') {
                        var el = r.target;
                        if (el.tagName === 'I' && el.className.indexOf('fa-') !== -1) render(el);
                        return;
                    }
                    Array.prototype.forEach.call(r.addedNodes, function (n) {
                        if (n.nodeType === 1) scan(n);
                    });
                });
            }).observe(document.body, { childList: true, subtree: true, attributes: true, attributeFilter: ['class'] });
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }
})();
