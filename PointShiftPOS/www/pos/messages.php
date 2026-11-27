<?php
require_once 'config.php';
requireLogin();
requireAdmin();

$page_title = "Messages";
$user_id    = $_SESSION['user_id'];

/* ── AJAX: SMS balance check ─────────────────────────────── */
if (isset($_GET['check_sms_balance'])) {
    header('Content-Type: application/json');
    $bal = Sms::getBalance(null, 10);
    echo json_encode($bal);
    exit;
}

/* ── Handle reply ───────────────────────────────────────── */
$flash      = '';
$flash_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    // ── SMS send ─────────────────────────────────────────────
    if ($_POST['action'] === 'send_sms') {
        $sms_phone   = trim($_POST['sms_phone']   ?? '');
        $sms_message = trim($_POST['sms_message'] ?? '');

        if (!$sms_phone || !$sms_message) {
            $flash = 'Phone number and message are required.';
            $flash_type = 'warning';
        } else {
            $phones   = array_filter(array_map('trim', explode(',', $sms_phone)));
            $phoneStr = implode(',', $phones);
            $result   = Sms::send($phoneStr, $sms_message);
            $flash    = $result['success']
                ? 'SMS sent to ' . count($phones) . ' number' . (count($phones) !== 1 ? 's' : '') . '.'
                : 'SMS failed: ' . $result['message'];
            $flash_type = $result['success'] ? 'success' : 'danger';
        }
    }

    // ── Internal message reply ────────────────────────────────
    if ($_POST['action'] === 'send') {
        $message_text = trim($_POST['message']);
        $recipient_id = (int)$_POST['recipient_id'];
        $subject      = trim($_POST['subject'] ?? '');
        if ($subject === '') $subject = 'No subject';
        $parent_id    = !empty($_POST['parent_id']) ? (int)$_POST['parent_id'] : null;

        $stmt = $conn->prepare("INSERT INTO messages (sender_id, recipient_id, subject, message, parent_message_id) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param("iissi", $user_id, $recipient_id, $subject, $message_text, $parent_id);

        if ($stmt->execute()) {
            $flash      = "Reply sent.";
            $flash_type = "success";
        } else {
            $flash      = "Error sending reply: " . $conn->error;
            $flash_type = "danger";
        }
    }
}

/* ── Fetch conversations ────────────────────────────────── */
$conversations_query = "
    SELECT m.*,
           sender.first_name  AS sender_first_name,
           sender.last_name   AS sender_last_name,
           sender.role        AS sender_role,
           sender.email       AS sender_email,
           (SELECT COUNT(*) FROM messages WHERE parent_message_id = m.id OR id = m.id) AS reply_count,
           (SELECT COUNT(*) FROM messages WHERE parent_message_id = m.id AND recipient_id = ? AND is_read = 0) AS unread_replies
    FROM messages m
    JOIN users sender ON m.sender_id = sender.id
    WHERE (m.sender_id != ? OR m.recipient_id = ?)
      AND m.parent_message_id IS NULL
    ORDER BY m.created_at DESC
";
$stmt = $conn->prepare($conversations_query);
$stmt->bind_param("iii", $user_id, $user_id, $user_id);
$stmt->execute();
$conversations = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

/* ── Filter ─────────────────────────────────────────────── */
$filter               = $_GET['filter'] ?? 'all';
$filtered_conversations = $conversations;

if ($filter === 'unread') {
    $filtered_conversations = array_filter($conversations, fn($c) =>
        $c['unread_replies'] > 0 || ($c['recipient_id'] == $user_id && $c['is_read'] == 0));
} elseif ($filter === 'staff') {
    $filtered_conversations = array_filter($conversations, fn($c) => $c['sender_role'] === 'staff');
} elseif ($filter === 'cashier') {
    $filtered_conversations = array_filter($conversations, fn($c) => $c['sender_role'] === 'cashier');
}

/* ── Selected conversation / thread ────────────────────── */
$selected_conversation = null;
$conversation_thread   = [];

if (isset($_GET['conversation_id'])) {
    $conversation_id = (int)$_GET['conversation_id'];

    $stmt = $conn->prepare("
        SELECT m.*,
               sender.first_name AS sender_first_name,
               sender.last_name  AS sender_last_name,
               sender.role       AS sender_role,
               sender.email      AS sender_email
        FROM messages m
        JOIN users sender ON m.sender_id = sender.id
        WHERE m.id = ?
    ");
    $stmt->bind_param("i", $conversation_id);
    $stmt->execute();
    $selected_conversation = $stmt->get_result()->fetch_assoc();

    $stmt = $conn->prepare("
        SELECT m.*,
               sender.first_name AS sender_first_name,
               sender.last_name  AS sender_last_name,
               sender.role       AS sender_role
        FROM messages m
        JOIN users sender ON m.sender_id = sender.id
        WHERE m.parent_message_id = ?
        ORDER BY m.created_at ASC
    ");
    $stmt->bind_param("i", $conversation_id);
    $stmt->execute();
    $conversation_thread = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    /* mark as read */
    $stmt = $conn->prepare("UPDATE messages SET is_read = 1 WHERE (id = ? OR parent_message_id = ?) AND recipient_id = ?");
    $stmt->bind_param("iii", $conversation_id, $conversation_id, $user_id);
    $stmt->execute();
}

/* ── Stats ──────────────────────────────────────────────── */
$total_messages   = count($conversations);
$unread_count     = (int)$conn->query("SELECT COUNT(*) c FROM messages WHERE recipient_id = $user_id AND is_read = 0")->fetch_assoc()['c'];
$staff_messages   = count(array_filter($conversations, fn($c) => $c['sender_role'] === 'staff'));
$cashier_messages = count(array_filter($conversations, fn($c) => $c['sender_role'] === 'cashier'));

/* ── Helpers ────────────────────────────────────────────── */
function roleLabel(string $role): string {
    return match ($role) {
        'staff'   => 'Staff',
        'cashier' => 'Cashier',
        'admin'   => 'Admin',
        default   => ucfirst($role),
    };
}
function avatarInitials(string $first, string $last): string {
    return strtoupper(substr($first, 0, 1) . substr($last, 0, 1));
}
function timeAgo(string $datetime): string {
    $diff = time() - strtotime($datetime);
    if ($diff < 60)        return 'just now';
    if ($diff < 3600)      return floor($diff/60)  . 'm ago';
    if ($diff < 86400)     return floor($diff/3600) . 'h ago';
    if ($diff < 604800)    return floor($diff/86400) . 'd ago';
    return date('M d', strtotime($datetime));
}

ob_start();
?>

<style>
/* ── Page layout ───────────────────────────────────────── */
/* ── KPI tiles ─────────────────────────────────────────── */
.kpi-grid { display:grid; grid-template-columns:repeat(4,1fr); gap:10px; margin-bottom:20px; }
.kpi-card { background:var(--bg-surface); border:1px solid var(--border-color); border-radius:var(--radius-lg); padding:16px 18px; cursor:default; transition:box-shadow .15s, transform .15s; }
.kpi-card:hover { box-shadow:var(--shadow-sm); transform:translateY(-1px); }
.kpi-label { font-size:.62rem; font-weight:700; text-transform:uppercase; letter-spacing:.7px; color:var(--text-secondary); margin-bottom:6px; }
.kpi-value { font-size:1.35rem; font-weight:700; color:var(--text-primary); letter-spacing:-.5px; line-height:1.15; }
.kpi-sub   { font-size:.7rem; color:var(--text-muted); margin-top:3px; }

/* ── Main inbox card ───────────────────────────────────── */
.msg-card { background:var(--bg-surface); border:1px solid var(--border-color); border-radius:var(--radius-lg); overflow:hidden; }
.msg-card-head {
    display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:8px;
    padding:12px 16px; border-bottom:1px solid var(--border-color);
}
.msg-card-head-left { display:flex; align-items:center; gap:8px; }
.msg-card-icon { width:26px; height:26px; border-radius:6px; background:var(--bg-surface-2); border:1px solid var(--border-color); display:flex; align-items:center; justify-content:center; font-size:.72rem; color:var(--text-secondary); flex-shrink:0; }
.msg-card-title { font-size:.8rem; font-weight:600; color:var(--text-primary); }

/* ── Filter tabs ───────────────────────────────────────── */
.msg-filters { display:flex; gap:4px; flex-wrap:wrap; }
.msg-filter-btn {
    font-size:.72rem; font-weight:600; padding:4px 10px; border-radius:99px;
    border:1px solid var(--border-color); background:var(--bg-surface-2);
    color:var(--text-secondary); cursor:pointer; text-decoration:none;
    transition:background .12s, color .12s, border-color .12s;
    white-space:nowrap;
}
.msg-filter-btn:hover  { background:var(--bg-body); color:var(--text-primary); }
.msg-filter-btn.active { background:var(--text-primary); border-color:var(--text-primary); color:var(--text-on-primary); }

/* ── Split pane ────────────────────────────────────────── */
.msg-pane { display:grid; grid-template-columns:300px 1fr; height:calc(100vh - 310px); min-height:400px; }
@media(max-width:767px){ .msg-pane { grid-template-columns:1fr; height:auto; } }

/* ── Conversation list ─────────────────────────────────── */
.conv-list { border-right:1px solid var(--border-color); overflow-y:auto; background:var(--bg-surface); }
.conv-item {
    padding:12px 14px; border-bottom:1px solid var(--border-light);
    cursor:pointer; transition:background .15s;
    display:flex; gap:10px; align-items:flex-start; position:relative;
}
.conv-item:hover         { background:var(--bg-surface-2); }
.conv-item.active        { background:var(--bg-surface-2); border-left:3px solid var(--text-primary); }
.conv-item.unread        { }
.conv-unread-dot { width:7px; height:7px; border-radius:50%; background:var(--danger); flex-shrink:0; margin-top:5px; }
.conv-unread-dot.hidden  { visibility:hidden; }
.conv-avatar {
    width:34px; height:34px; border-radius:50%; border:1.5px solid var(--border-color);
    background:var(--bg-surface-2); flex-shrink:0;
    display:flex; align-items:center; justify-content:center;
    font-size:.7rem; font-weight:700; color:var(--text-secondary);
}
.conv-info { flex:1; min-width:0; }
.conv-top  { display:flex; justify-content:space-between; align-items:baseline; gap:4px; }
.conv-subject { font-size:.8rem; font-weight:600; color:var(--text-primary); overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.conv-time    { font-size:.65rem; color:var(--text-muted); flex-shrink:0; }
.conv-sender  { font-size:.7rem; color:var(--text-secondary); margin-top:1px; display:flex; align-items:center; gap:4px; }
.conv-preview { font-size:.72rem; color:var(--text-muted); margin-top:3px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.role-badge {
    font-size:.58rem; font-weight:700; text-transform:uppercase; letter-spacing:.4px;
    padding:1px 6px; border-radius:99px;
    border:1px solid var(--border-color); background:var(--bg-surface-2);
    color:var(--text-secondary);
}
.role-badge.staff   { }
.role-badge.cashier { }

/* ── Thread pane ───────────────────────────────────────── */
.thread-wrap { display:flex; flex-direction:column; overflow:hidden; }
.thread-top  { padding:12px 16px; border-bottom:1px solid var(--border-color); background:var(--bg-surface); flex-shrink:0; }
.thread-top h6   { font-size:.88rem; font-weight:700; color:var(--text-primary); margin:0 0 2px; }
.thread-top small{ font-size:.7rem; color:var(--text-secondary); }
.thread-body { flex:1; overflow-y:auto; padding:16px; background:var(--bg-body); display:flex; flex-direction:column; gap:12px; }
.bubble-row { display:flex; gap:8px; align-items:flex-end; }
.bubble-row.mine { flex-direction:row-reverse; }
.bubble-avatar {
    width:30px; height:30px; border-radius:50%;
    background:var(--bg-surface-2); border:1.5px solid var(--border-color);
    display:flex; align-items:center; justify-content:center;
    font-size:.65rem; font-weight:700; color:var(--text-secondary); flex-shrink:0;
}
.bubble {
    max-width:72%; padding:10px 14px; border-radius:14px;
    background:var(--bg-surface); border:1px solid var(--border-color);
    font-size:.8rem; color:var(--text-primary);
    box-shadow:0 1px 3px rgba(0,0,0,.04);
}
.bubble.mine {
    background:var(--text-primary); color:var(--text-on-primary);
    border-color:var(--text-primary);
}
.bubble-meta { font-size:.64rem; opacity:.6; margin-top:4px; }
.bubble.mine .bubble-meta { text-align:right; }

/* ── Empty states ──────────────────────────────────────── */
.msg-empty { display:flex; flex-direction:column; align-items:center; justify-content:center; height:100%; gap:10px; padding:32px; }
.msg-empty i        { font-size:2rem; color:var(--text-muted); }
.msg-empty p        { font-size:.82rem; color:var(--text-secondary); text-align:center; margin:0; }
.msg-empty p.sub    { font-size:.72rem; color:var(--text-muted); }

/* ── Reply bar ─────────────────────────────────────────── */
.reply-bar { padding:10px 14px; border-top:1px solid var(--border-color); background:var(--bg-surface); flex-shrink:0; }
.reply-bar textarea {
    background:var(--bg-surface-2); border:1px solid var(--border-color);
    color:var(--text-primary); border-radius:var(--radius); font-size:.82rem;
    resize:none; width:100%; padding:8px 12px;
    transition:border-color .15s;
}
.reply-bar textarea:focus { outline:none; border-color:var(--text-primary); background:var(--bg-surface); }
.reply-bar textarea::placeholder { color:var(--text-muted); }
.btn-send {
    background:var(--text-primary); color:var(--text-on-primary);
    border:none; border-radius:var(--radius); padding:8px 16px;
    font-size:.78rem; font-weight:600; cursor:pointer;
    transition:opacity .15s;
}
.btn-send:hover { opacity:.85; }

@media(max-width:991px){
    .kpi-grid { grid-template-columns:repeat(2,1fr); }
}
@media(max-width:575px){
    .kpi-grid { grid-template-columns:1fr 1fr; gap:8px; }
    .kpi-value { font-size:1.1rem; }
}
</style>

<!-- Page header -->
<div class="page-header-bar">
    <div>
        <h5>Messages</h5>
        <p>Customer and staff conversations</p>
    </div>
    <div style="font-size:.72rem;color:var(--text-subtle);font-weight:500;padding-top:4px;"><?php echo date('D, d M Y'); ?></div>
</div>

<?php if ($flash): ?>
<div class="alert alert-<?php echo $flash_type; ?> alert-dismissible fade show" role="alert" style="font-size:.82rem;border-radius:var(--radius);">
    <?php echo htmlspecialchars($flash); ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert" style="padding:.45rem .55rem;"></button>
</div>
<?php endif; ?>

<!-- ── SMS Compose Box ─────────────────────────────────── -->
<style>
.sms-compose {
    background: var(--bg-surface);
    border: 1px solid var(--border-color);
    border-radius: var(--radius-lg);
    overflow: hidden;
    margin-bottom: 16px;
}
.sms-compose-head {
    display: flex; align-items: center; justify-content: space-between;
    padding: 12px 16px;
    border-bottom: 1px solid var(--border-color);
    cursor: pointer;
    user-select: none;
    transition: background .12s;
}
.sms-compose-head:hover { background: var(--bg-surface-2); }
.sms-compose-head-left { display: flex; align-items: center; gap: 8px; }
.sms-compose-icon {
    width: 26px; height: 26px; border-radius: 6px;
    background: var(--surface-2);
    border: 1px solid var(--border);
    display: flex; align-items: center; justify-content: center;
    font-size: .72rem; color: var(--text-muted);
    flex-shrink: 0;
}
.sms-compose-title { font-size: .8rem; font-weight: 600; color: var(--text-primary); }
.sms-compose-sub   { font-size: .7rem; color: var(--text-secondary); }
.sms-compose-body  { padding: 16px; display: none; }
.sms-compose-body.open { display: block; }

/* Character counter */
.sms-counter {
    font-size: .68rem; color: var(--text-muted);
    text-align: right; margin-top: 3px;
}
.sms-counter.warn { color: var(--warning); }
.sms-counter.over { color: var(--danger); font-weight: 700; }

/* Phone tag input */
.sms-tags-wrap {
    display: flex; flex-wrap: wrap; gap: 4px; align-items: center;
    min-height: 36px; padding: 4px 10px;
    border: 1px solid var(--border-color); border-radius: var(--radius);
    background: var(--bg-surface-2); cursor: text;
    transition: border-color .15s;
}
.sms-tags-wrap:focus-within { border-color: var(--text-primary); background: var(--bg-surface); }
.sms-tag {
    display: inline-flex; align-items: center; gap: 4px;
    padding: 2px 8px; border-radius: 99px;
    background: var(--text-primary); color: var(--text-on-primary);
    font-size: .7rem; font-weight: 600;
}
.sms-tag button {
    background: none; border: none; color: inherit; cursor: pointer;
    padding: 0; font-size: .7rem; line-height: 1; opacity: .7;
}
.sms-tag button:hover { opacity: 1; }
.sms-tags-input {
    border: none; outline: none; background: transparent;
    color: var(--text-primary); font-size: .82rem;
    font-family: inherit; min-width: 120px; flex: 1;
}

/* Send button */
.sms-send-btn {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 8px 20px; border-radius: var(--radius);
    border: none; background: var(--text-primary); color: var(--text-on-primary);
    font-size: .82rem; font-weight: 600; cursor: pointer;
    transition: opacity .15s;
    font-family: inherit;
}
.sms-send-btn:hover { opacity: .88; }
.sms-send-btn:disabled { opacity: .4; cursor: not-allowed; }

/* Quick templates */
.sms-templates {
    display: flex; flex-wrap: wrap; gap: 5px; margin-bottom: 10px;
}
.sms-tpl {
    font-size: .68rem; font-weight: 600; padding: 3px 10px;
    border-radius: 99px; border: 1px solid var(--border-color);
    background: var(--bg-surface-2); color: var(--text-secondary);
    cursor: pointer; transition: all .12s;
}
.sms-tpl:hover { background: var(--bg-body); color: var(--text-primary); border-color: var(--c-gray-400); }

/* Balance pill */
.sms-bal-pill {
    display: inline-flex; align-items: center; gap: 4px;
    font-size: .68rem; padding: 2px 8px; border-radius: 99px;
    background: var(--bg-surface-2); border: 1px solid var(--border-color);
    color: var(--text-secondary); font-weight: 600;
}
</style>

<div class="sms-compose" id="smsComposeCard">
    <!-- Collapsible header -->
    <div class="sms-compose-head" onclick="toggleSmsCompose()">
        <div class="sms-compose-head-left">
            <div class="sms-compose-icon"><i class="fas fa-comment-sms"></i></div>
            <div>
                <div class="sms-compose-title">Send SMS</div>
                <div class="sms-compose-sub">Compose and send SMS via BlessedTexts</div>
            </div>
        </div>
        <div style="display:flex;align-items:center;gap:10px;">
            <span class="sms-bal-pill" id="smsBalPill" title="SMS balance">
                <i class="fas fa-coins"></i>
                SMS balance: <span id="smsBalVal">—</span>
            </span>
            <i class="fas fa-chevron-down" id="smsComposeChevron" style="font-size:.72rem;color:var(--text-muted);transition:transform .2s;"></i>
        </div>
    </div>

    <!-- Compose body -->
    <div class="sms-compose-body" id="smsComposeBody">
        <form method="POST" id="smsComposeForm" onsubmit="submitSmsForm(event)">
            <input type="hidden" name="action" value="send_sms">

            <div class="row g-3">
                <!-- Recipients -->
                <div class="col-12">
                    <label style="font-size:.72rem;font-weight:600;color:var(--text-secondary);text-transform:uppercase;letter-spacing:.5px;display:block;margin-bottom:5px;">
                        To (phone numbers)
                    </label>
                    <div class="sms-tags-wrap" id="smsTagsWrap" onclick="document.getElementById('smsTagInput').focus()">
                        <div id="smsTags"></div>
                        <input type="text" id="smsTagInput" class="sms-tags-input"
                               placeholder="e.g. 0787677676 - press Enter or comma to add"
                               onkeydown="handleTagKey(event)"
                               onblur="addTagFromInput()">
                    </div>
                    <input type="hidden" name="sms_phone" id="smsPhoneHidden">
                    <div style="font-size:.68rem;color:var(--text-subtle);margin-top:3px;">
                        Formats: <code>07xx</code>, <code>2547xx</code>, <code>+2547xx</code>. Separate multiple numbers with commas or Enter.
                    </div>
                </div>

                <!-- Quick templates -->
                <div class="col-12">
                    <label style="font-size:.72rem;font-weight:600;color:var(--text-secondary);text-transform:uppercase;letter-spacing:.5px;display:block;margin-bottom:5px;">
                        Quick templates
                    </label>
                    <div class="sms-templates">
                        <button type="button" class="sms-tpl" onclick="useTpl('Thank you for your purchase. We appreciate your business.')">
                            Thank you
                        </button>
                        <button type="button" class="sms-tpl" onclick="useTpl('Your order is ready for pickup. Please visit our store.')">
                            Order ready
                        </button>
                        <button type="button" class="sms-tpl" onclick="useTpl('We have a special offer for you. Visit us today for exclusive deals.')">
                            Special offer
                        </button>
                        <button type="button" class="sms-tpl" onclick="useTpl('Reminder: Your payment is due. Please contact us.')">
                            Payment reminder
                        </button>
                        <button type="button" class="sms-tpl" onclick="useTpl('Your account has been updated. Please contact us if you have any questions.')">
                            Account update
                        </button>
                    </div>
                </div>

                <!-- Message -->
                <div class="col-12">
                    <label style="font-size:.72rem;font-weight:600;color:var(--text-secondary);text-transform:uppercase;letter-spacing:.5px;display:block;margin-bottom:5px;">
                        Message
                    </label>
                    <textarea name="sms_message" id="smsMessage" rows="4"
                              class="form-control" style="resize:vertical;font-size:.85rem;"
                              placeholder="Type your message"
                              maxlength="480"
                              oninput="updateSmsCounter()"></textarea>
                    <div class="sms-counter" id="smsCounter">0 / 160 characters &nbsp;·&nbsp; 1 SMS</div>
                </div>

                <!-- Actions -->
                <div class="col-12 d-flex align-items-center justify-content-between flex-wrap gap-2">
                    <div style="font-size:.72rem;color:var(--text-muted);">
                        <i class="fas fa-info-circle me-1"></i>
                        Sent via <strong>BlessedTexts</strong> · Sender ID: <strong><?php
                            $senderIdRow = $conn->query("SELECT setting_value FROM store_settings WHERE setting_key='sms_sender_id' LIMIT 1");
                            echo htmlspecialchars($senderIdRow ? ($senderIdRow->fetch_assoc()['setting_value'] ?? 'BLESSEDTEXT') : 'BLESSEDTEXT');
                        ?></strong>
                    </div>
                    <div style="display:flex;gap:8px;align-items:center;">
                        <button type="button" class="btn btn-outline-secondary btn-sm" onclick="clearSmsForm()">
                            <i class="fas fa-rotate-left me-1"></i>Clear
                        </button>
                        <button type="submit" class="sms-send-btn" id="smsSendBtn" disabled>
                            <i class="fas fa-paper-plane"></i>Send SMS
                        </button>
                    </div>
                </div>

                <!-- Sending result -->
                <div class="col-12" id="smsResult" style="display:none;"></div>
            </div>
        </form>
    </div>
</div>

<script>
// ── SMS Compose ───────────────────────────────────────────
let smsTags = [];

function toggleSmsCompose() {
    const body     = document.getElementById('smsComposeBody');
    const chevron  = document.getElementById('smsComposeChevron');
    const isOpen   = body.classList.contains('open');
    body.classList.toggle('open', !isOpen);
    chevron.style.transform = isOpen ? 'rotate(0deg)' : 'rotate(180deg)';
    if (!isOpen) {
        document.getElementById('smsTagInput').focus();
        loadSmsBalance();
    }
    localStorage.setItem('sms_compose_open', !isOpen ? '1' : '0');
}

// Restore open state
(function() {
    if (localStorage.getItem('sms_compose_open') === '1') {
        document.getElementById('smsComposeBody').classList.add('open');
        document.getElementById('smsComposeChevron').style.transform = 'rotate(180deg)';
        loadSmsBalance();
    }
})();

// ── Tag input ─────────────────────────────────────────────
function handleTagKey(e) {
    if (e.key === 'Enter' || e.key === ',') {
        e.preventDefault();
        addTagFromInput();
    } else if (e.key === 'Backspace' && e.target.value === '' && smsTags.length > 0) {
        removeTag(smsTags.length - 1);
    }
}

function addTagFromInput() {
    const input = document.getElementById('smsTagInput');
    const val   = input.value.trim().replace(/,$/, '');
    if (!val) return;
    // Allow multiple comma-separated in one paste
    val.split(/[,;\s]+/).forEach(function(phone) {
        phone = phone.trim();
        if (phone && !smsTags.includes(phone)) {
            smsTags.push(phone);
        }
    });
    input.value = '';
    renderTags();
    updateSendBtn();
}

function removeTag(idx) {
    smsTags.splice(idx, 1);
    renderTags();
    updateSendBtn();
}

function renderTags() {
    const wrap = document.getElementById('smsTags');
    wrap.innerHTML = smsTags.map(function(t, i) {
        return '<span class="sms-tag">' + escHtml(t) +
               '<button type="button" onclick="removeTag(' + i + ')" title="Remove">×</button></span>';
    }).join('');
    document.getElementById('smsPhoneHidden').value = smsTags.join(',');
}

function escHtml(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}

// ── Character counter ─────────────────────────────────────
function updateSmsCounter() {
    const msg    = document.getElementById('smsMessage').value;
    const len    = msg.length;
    const smsCnt = len <= 160 ? 1 : Math.ceil(len / 153);
    const el     = document.getElementById('smsCounter');
    el.textContent = len + ' / 160 characters · ' + smsCnt + ' SMS';
    el.className = 'sms-counter' + (len > 160 ? ' over' : (len > 140 ? ' warn' : ''));
    updateSendBtn();
}

function updateSendBtn() {
    const hasPhone = smsTags.length > 0;
    const hasMsg   = document.getElementById('smsMessage').value.trim().length > 0;
    document.getElementById('smsSendBtn').disabled = !(hasPhone && hasMsg);
}

// ── Quick templates ───────────────────────────────────────
function useTpl(text) {
    document.getElementById('smsMessage').value = text;
    updateSmsCounter();
    document.getElementById('smsMessage').focus();
}

// ── Clear form ────────────────────────────────────────────
function clearSmsForm() {
    smsTags = [];
    renderTags();
    document.getElementById('smsMessage').value = '';
    document.getElementById('smsTagInput').value = '';
    updateSmsCounter();
    document.getElementById('smsResult').style.display = 'none';
}

// ── AJAX send ─────────────────────────────────────────────
function submitSmsForm(e) {
    e.preventDefault();
    addTagFromInput(); // capture any typed but not yet added number

    const phone   = smsTags.join(',');
    const message = document.getElementById('smsMessage').value.trim();
    const result  = document.getElementById('smsResult');

    if (!phone) {
        showSmsResult('warning', 'Add at least one phone number.');
        return;
    }
    if (!message) {
        showSmsResult('warning', 'Enter a message.');
        return;
    }

    const btn = document.getElementById('smsSendBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Sending';

    const fd = new FormData();
    fd.append('action',      'send_sms');
    fd.append('sms_phone',   phone);
    fd.append('sms_message', message);

    fetch(window.location.href, { method: 'POST', body: fd })
        .then(function(r) { return r.text(); })
        .then(function(html) {
            const doc    = new DOMParser().parseFromString(html, 'text/html');
            const alert  = doc.querySelector('.alert:not(.alert-secondary)') || doc.querySelector('.alert');
            if (alert) {
                showSmsResult(
                    alert.classList.contains('alert-success') ? 'success' : 'danger',
                    alert.textContent.trim()
                );
                if (alert.classList.contains('alert-success')) {
                    clearSmsForm();
                    loadSmsBalance();
                }
            } else {
                showSmsResult('success', 'SMS sent.');
                clearSmsForm();
            }
        })
        .catch(function(err) { showSmsResult('danger', 'Network error: ' + err.message); })
        .finally(function() {
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-paper-plane"></i> Send SMS';
            updateSendBtn();
        });
}

function showSmsResult(type, msg) {
    const el   = document.getElementById('smsResult');
    const icon = type === 'success' ? 'check-circle' : (type === 'warning' ? 'triangle-exclamation' : 'circle-xmark');
    el.style.display = 'block';
    el.innerHTML = '<div class="alert alert-' + type + ' py-2 mb-0" style="font-size:.82rem;border-radius:var(--radius);">'
        + '<i class="fas fa-' + icon + ' me-2"></i>' + msg + '</div>';
}

// ── Load SMS balance ──────────────────────────────────────
function loadSmsBalance() {
    fetch('?check_sms_balance=1')
        .then(function(r) { return r.json(); })
        .then(function(d) {
            const el = document.getElementById('smsBalVal');
            if (el) el.textContent = d.success ? Number(d.balance).toLocaleString() : '—';
        })
        .catch(function() {});
}

// ── Auto-scroll thread ────────────────────────────────────
(function () {
    var tb = document.getElementById('threadBody');
    if (tb) tb.scrollTop = tb.scrollHeight;
})();
</script>

<!-- KPI row -->
<div class="kpi-grid">
    <div class="kpi-card">
        <div class="kpi-label">Total messages</div>
        <div class="kpi-value"><?php echo $total_messages; ?></div>
        <div class="kpi-sub">all conversations</div>
    </div>
    <div class="kpi-card">
        <div class="kpi-label">Unread</div>
        <div class="kpi-value"><?php echo $unread_count; ?></div>
        <div class="kpi-sub">awaiting review</div>
    </div>
    <div class="kpi-card">
        <div class="kpi-label">From staff</div>
        <div class="kpi-value"><?php echo $staff_messages; ?></div>
        <div class="kpi-sub">staff messages</div>
    </div>
    <div class="kpi-card">
        <div class="kpi-label">From cashiers</div>
        <div class="kpi-value"><?php echo $cashier_messages; ?></div>
        <div class="kpi-sub">cashier messages</div>
    </div>
</div>

<!-- Main inbox card -->
<div class="msg-card">
    <!-- Card header -->
    <div class="msg-card-head">
        <div class="msg-card-head-left">
            <div class="msg-card-icon"><i class="fas fa-comments"></i></div>
            <span class="msg-card-title">Staff and cashier messages</span>
        </div>
        <div class="msg-filters">
            <?php
            $conv_id_qs = isset($_GET['conversation_id']) ? '&conversation_id=' . (int)$_GET['conversation_id'] : '';
            $tabs = [
                'all'     => 'All ('     . count($conversations) . ')',
                'unread'  => 'Unread ('  . $unread_count . ')',
                'staff'   => 'Staff ('   . $staff_messages . ')',
                'cashier' => 'Cashiers (' . $cashier_messages . ')',
            ];
            foreach ($tabs as $key => $label):
            ?>
            <a href="?filter=<?php echo $key . $conv_id_qs; ?>"
               class="msg-filter-btn <?php echo $filter === $key ? 'active' : ''; ?>">
                <?php echo $label; ?>
            </a>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Split pane -->
    <div class="msg-pane">

        <!-- Conversation list -->
        <div class="conv-list">
            <?php if (empty($filtered_conversations)): ?>
            <div class="msg-empty" style="height:300px;">
                <i class="fas fa-inbox"></i>
                <p>No messages yet</p>
                <p class="sub">Conversations from staff and customers will appear here.</p>
            </div>
            <?php else: ?>
            <?php foreach ($filtered_conversations as $conv):
                $is_active = isset($_GET['conversation_id']) && (int)$_GET['conversation_id'] === (int)$conv['id'];
                $is_unread = $conv['unread_replies'] > 0 || ($conv['recipient_id'] == $user_id && $conv['is_read'] == 0);
                $initials  = avatarInitials($conv['sender_first_name'], $conv['sender_last_name']);
            ?>
            <div class="conv-item <?php echo $is_active ? 'active' : ''; ?> <?php echo $is_unread ? 'unread' : ''; ?>"
                 onclick="window.location.href='messages.php?conversation_id=<?php echo (int)$conv['id']; ?>&filter=<?php echo urlencode($filter); ?>'">
                <div class="conv-unread-dot <?php echo $is_unread ? '' : 'hidden'; ?>"></div>
                <div class="conv-avatar"><?php echo $initials; ?></div>
                <div class="conv-info">
                    <div class="conv-top">
                        <span class="conv-subject"><?php echo htmlspecialchars($conv['subject']); ?></span>
                        <span class="conv-time"><?php echo timeAgo($conv['created_at']); ?></span>
                    </div>
                    <div class="conv-sender">
                        <?php echo htmlspecialchars($conv['sender_first_name'] . ' ' . $conv['sender_last_name']); ?>
                        <span class="role-badge <?php echo $conv['sender_role']; ?>"><?php echo roleLabel($conv['sender_role']); ?></span>
                    </div>
                    <div class="conv-preview"><?php echo htmlspecialchars(substr($conv['message'], 0, 90)); ?></div>
                </div>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
        </div><!-- /conv-list -->

        <!-- Thread pane -->
        <div class="thread-wrap">
            <?php if ($selected_conversation): ?>

            <!-- Thread header -->
            <div class="thread-top">
                <div class="d-flex align-items-center justify-content-between gap-2">
                    <div>
                        <h6><?php echo htmlspecialchars($selected_conversation['subject']); ?></h6>
                        <small>
                            <?php echo htmlspecialchars($selected_conversation['sender_first_name'] . ' ' . $selected_conversation['sender_last_name']); ?>
                            &mdash; <?php echo htmlspecialchars($selected_conversation['sender_email']); ?>
                            &nbsp;&middot;&nbsp;
                            <?php echo date('d M Y, g:i A', strtotime($selected_conversation['created_at'])); ?>
                        </small>
                    </div>
                    <span class="role-badge <?php echo $selected_conversation['sender_role']; ?>" style="font-size:.68rem;padding:3px 10px;">
                        <?php echo roleLabel($selected_conversation['sender_role']); ?>
                    </span>
                </div>
            </div>

            <!-- Messages -->
            <div class="thread-body" id="threadBody">
                <?php
                $all_msgs = array_merge([$selected_conversation], $conversation_thread);
                foreach ($all_msgs as $msg):
                    $is_mine  = (int)$msg['sender_id'] === $user_id;
                    $initials = avatarInitials($msg['sender_first_name'], $msg['sender_last_name']);
                ?>
                <div class="bubble-row <?php echo $is_mine ? 'mine' : ''; ?>">
                    <div class="bubble-avatar"><?php echo $initials; ?></div>
                    <div class="bubble <?php echo $is_mine ? 'mine' : ''; ?>">
                        <?php echo nl2br(htmlspecialchars($msg['message'])); ?>
                        <div class="bubble-meta">
                            <?php echo htmlspecialchars($msg['sender_first_name']); ?>
                            &middot; <?php echo date('M d, g:i A', strtotime($msg['created_at'])); ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <!-- Reply bar -->
            <div class="reply-bar">
                <form method="POST">
                    <input type="hidden" name="action"       value="send">
                    <input type="hidden" name="recipient_id" value="<?php echo (int)$selected_conversation['sender_id']; ?>">
                    <input type="hidden" name="parent_id"    value="<?php echo (int)$selected_conversation['id']; ?>">
                    <input type="hidden" name="subject"      value="Re: <?php echo htmlspecialchars($selected_conversation['subject']); ?>">
                    <div class="d-flex gap-2 align-items-flex-end">
                        <textarea name="message" rows="2" placeholder="Type your reply" required></textarea>
                        <button type="submit" class="btn-send" style="height:fit-content;">
                            <i class="fas fa-paper-plane me-1"></i>Send
                        </button>
                    </div>
                </form>
            </div>

            <?php else: ?>
            <!-- No conversation selected -->
            <div class="msg-empty">
                <i class="fas fa-comments"></i>
                <p>Select a conversation to view messages</p>
                <p class="sub">Messages from staff and customers will appear here.</p>
            </div>
            <?php endif; ?>
        </div><!-- /thread-wrap -->

    </div><!-- /msg-pane -->
</div><!-- /msg-card -->

<script>
// Auto-scroll thread to bottom on load
(function () {
    var tb = document.getElementById('threadBody');
    if (tb) tb.scrollTop = tb.scrollHeight;
})();
</script>


<?php
$content = ob_get_clean();
// Use admin layout for admin users
if ($_SESSION['role'] === 'admin' || $_SESSION['role'] === ROLE_SUPER_ADMIN) {
    include __DIR__ . '/admin/views/layouts/main.php';
} else {
    include 'layout.php';
}
?>
