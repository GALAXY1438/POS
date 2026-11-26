<?php
require_once '../config.php';
requireLogin();

$page_title = 'Email Admin';
$user_id = $_SESSION['user_id'];

// Handle sending new message
$message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'send') {
        $subject = trim($_POST['subject']);
        $message_text = trim($_POST['message']);
        $parent_id = !empty($_POST['parent_id']) ? $_POST['parent_id'] : NULL;
        
        // Get admin user ID (first admin in system)
        $admin_query = $conn->query("SELECT id FROM users WHERE role = 'admin' LIMIT 1");
        $admin = $admin_query->fetch_assoc();
        
        if ($admin) {
            $recipient_id = $admin['id'];
            
            $stmt = $conn->prepare("INSERT INTO messages (sender_id, recipient_id, subject, message, parent_message_id) VALUES (?, ?, ?, ?, ?)");
            $stmt->bind_param("iissi", $user_id, $recipient_id, $subject, $message_text, $parent_id);
            
            if ($stmt->execute()) {
                $message = "Message sent.";
                $message_type = "success";
            } else {
                $message = "Could not send message: " . $conn->error;
                $message_type = "danger";
            }
        } else {
            $message = "No admin account found.";
            $message_type = "danger";
        }
    }
    
    if ($_POST['action'] === 'mark_read') {
        $message_id = $_POST['message_id'];
        $stmt = $conn->prepare("UPDATE messages SET is_read = 1 WHERE id = ? AND recipient_id = ?");
        $stmt->bind_param("ii", $message_id, $user_id);
        $stmt->execute();
    }
}

// Get all conversations (parent messages only)
$conversations_query = "
    SELECT m.*, 
           u.first_name as sender_first_name, 
           u.last_name as sender_last_name,
           u.role as sender_role,
           (SELECT COUNT(*) FROM messages WHERE parent_message_id = m.id OR id = m.id) as reply_count,
           (SELECT COUNT(*) FROM messages WHERE parent_message_id = m.id AND recipient_id = ? AND is_read = 0) as unread_replies
    FROM messages m
    JOIN users u ON m.sender_id = u.id
    WHERE (m.sender_id = ? OR m.recipient_id = ?)
    AND m.parent_message_id IS NULL
    ORDER BY m.created_at DESC
";

$stmt = $conn->prepare($conversations_query);
$stmt->bind_param("iii", $user_id, $user_id, $user_id);
$stmt->execute();
$conversations = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Get selected conversation thread
$selected_conversation = null;
$conversation_thread = [];

if (isset($_GET['conversation_id'])) {
    $conversation_id = $_GET['conversation_id'];
    
    // Get parent message
    $stmt = $conn->prepare("
        SELECT m.*, 
               u.first_name as sender_first_name, 
               u.last_name as sender_last_name,
               u.role as sender_role
        FROM messages m
        JOIN users u ON m.sender_id = u.id
        WHERE m.id = ?
    ");
    $stmt->bind_param("i", $conversation_id);
    $stmt->execute();
    $selected_conversation = $stmt->get_result()->fetch_assoc();
    
    // Get all replies in thread
    $stmt = $conn->prepare("
        SELECT m.*, 
               u.first_name as sender_first_name, 
               u.last_name as sender_last_name,
               u.role as sender_role
        FROM messages m
        JOIN users u ON m.sender_id = u.id
        WHERE m.parent_message_id = ?
        ORDER BY m.created_at ASC
    ");
    $stmt->bind_param("i", $conversation_id);
    $stmt->execute();
    $conversation_thread = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    
    // Mark messages as read
    $stmt = $conn->prepare("UPDATE messages SET is_read = 1 WHERE (id = ? OR parent_message_id = ?) AND recipient_id = ?");
    $stmt->bind_param("iii", $conversation_id, $conversation_id, $user_id);
    $stmt->execute();
}

// Count unread messages
$unread_count = $conn->query("SELECT COUNT(*) as count FROM messages WHERE recipient_id = $user_id AND is_read = 0")->fetch_assoc()['count'];

ob_start();
?>

<style>
    .email-container {
        background: var(--bg-body);
        min-height: calc(100vh - var(--topbar-height, 56px));
    }
    
    .stats-row {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
        gap: 16px;
        padding: 20px;
        margin-bottom: 20px;
    }
    
    .stat-card {
        background: var(--bg-surface);
        border: 1px solid var(--border-color);
        border-radius: var(--radius-lg);
        padding: 20px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        transition: all 0.2s;
        box-shadow: 0 1px 3px rgba(0,0,0,0.05);
    }
    
    .stat-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(0,0,0,0.1);
    }
    
    .stat-card.clickable {
        cursor: pointer;
    }
    
    .stat-info h6 {
        font-size: 0.75rem;
        color: var(--text-muted);
        margin: 0 0 8px 0;
        font-weight: 600;
    }
    
    .stat-info h3 {
        font-size: 1.75rem;
        font-weight: 600;
        margin: 0;
        color: var(--text-primary);
    }
    
    .stat-icon {
        font-size: 2rem;
        opacity: 0.35;
        color: var(--text-subtle);
    }
    
    .stat-card.primary .stat-icon,
    .stat-card.warning .stat-icon { color: var(--text-subtle); }
    .stat-card.danger .stat-icon { color: var(--text-subtle); }
    
    .message-layout {
        display: grid;
        grid-template-columns: 380px 1fr;
        height: calc(100vh - 280px);
        background: var(--bg-surface);
        border: 1px solid var(--border-color);
        border-radius: var(--radius-lg);
        overflow: hidden;
        margin: 0 20px 20px 20px;
    }
    
    .conversation-sidebar {
        border-right: 1px solid var(--border-color);
        display: flex;
        flex-direction: column;
        background: var(--bg-surface-2);
    }
    
    .sidebar-header {
        padding: 16px 20px;
        border-bottom: 2px solid var(--border-color);
        background: var(--bg-surface);
    }
    
    .sidebar-header h5 {
        font-size: 0.95rem;
        font-weight: 700;
        margin: 0;
        color: var(--text-primary);
    }
    
    .conversation-list {
        flex: 1;
        overflow-y: auto;
    }
    
    .conversation-item {
        padding: 16px 20px;
        border-bottom: 1px solid var(--border-color);
        cursor: pointer;
        transition: all 0.15s;
        background: var(--bg-surface);
    }
    
    .conversation-item:hover {
        background: var(--bg-surface-hover);
    }
    
    .conversation-item.active {
        background: var(--primary);
        color: var(--primary-contrast);
        border-left: 4px solid var(--primary);
    }
    
    .conversation-item.active * {
        color: var(--primary-contrast) !important;
    }
    
    .conversation-item.unread {
        background: var(--bg-surface-2);
        border-left: 2px solid var(--text);
    }
    
    .conv-subject {
        font-size: 0.9rem;
        font-weight: 600;
        margin-bottom: 6px;
        display: flex;
        align-items: center;
        gap: 8px;
    }
    
    .conv-meta {
        font-size: 0.75rem;
        color: var(--text-muted);
        display: flex;
        align-items: center;
        gap: 8px;
        margin-bottom: 8px;
    }
    
    .conv-preview {
        font-size: 0.8rem;
        color: var(--text-secondary);
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }
    
    .conv-footer {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-top: 8px;
        font-size: 0.7rem;
        color: var(--text-muted);
    }
    
    .message-main {
        display: flex;
        flex-direction: column;
        background: var(--bg);
    }
    
    .thread-header {
        padding: 16px 24px;
        background: var(--bg-surface);
        border-bottom: 2px solid var(--border-color);
    }
    
    .thread-header h5 {
        font-size: 1.1rem;
        font-weight: 700;
        margin: 0 0 4px 0;
        color: var(--text-primary);
    }
    
    .thread-meta {
        font-size: 0.75rem;
        color: var(--text-muted);
    }
    
    .message-thread {
        flex: 1;
        overflow-y: auto;
        padding: 24px;
    }
    
    .message-bubble {
        padding: 16px 20px;
        border-radius: 16px;
        margin-bottom: 16px;
        max-width: 70%;
        box-shadow: 0 1px 2px rgba(0,0,0,0.1);
    }
    
    .message-bubble.sent {
        background: var(--primary);
        color: var(--primary-contrast);
        margin-left: auto;
        border-bottom-right-radius: 4px;
    }
    
    .message-bubble.received {
        background: var(--surface);
        color: var(--text-primary);
        border-bottom-left-radius: 4px;
        border: 1px solid var(--border);
    }
    
    .bubble-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 12px;
        font-size: 0.8rem;
    }
    
    .bubble-header strong {
        display: flex;
        align-items: center;
        gap: 6px;
    }
    
    .bubble-body {
        font-size: 0.9rem;
        line-height: 1.5;
        white-space: pre-wrap;
    }
    
    .reply-form {
        background: var(--surface);
        border-top: 1px solid var(--border);
        padding: 20px 24px;
    }
    
    .reply-input-group {
        display: flex;
        gap: 12px;
        align-items: flex-end;
    }
    
    .reply-input-group textarea {
        flex: 1;
        resize: none;
        border: 1.5px solid var(--border-color);
        border-radius: var(--radius);
        padding: 12px 16px;
        font-size: 0.9rem;
    }
    
    .reply-input-group textarea:focus {
        outline: none;
        border-color: var(--primary);
        box-shadow: 0 0 0 3px rgba(13, 110, 253, 0.1);
    }
    
    .reply-btn {
        background: var(--primary);
        color: var(--primary-contrast);
        border: none;
        padding: 10px 20px;
        border-radius: var(--radius);
        font-weight: 600;
        font-size: 0.875rem;
        cursor: pointer;
        transition: opacity 0.2s;
        white-space: nowrap;
    }
    
    .reply-btn:hover {
        opacity: 0.88;
    }
    
    .empty-state {
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        height: 100%;
        text-align: center;
        padding: 40px;
        color: var(--text-muted);
    }
    
    .empty-state i {
        font-size: 40px;
        color: var(--text-subtle);
        margin-bottom: 12px;
        opacity: 0.8;
    }
    
    .empty-state h5 {
        color: var(--text);
        font-size: 0.875rem;
        font-weight: 600;
        margin-bottom: 4px;
    }
    
    .empty-state p {
        color: var(--text-muted);
        font-size: 0.8125rem;
    }
    
    .badge-unread {
        background: var(--danger);
        color: var(--primary-contrast);
        font-size: 0.7rem;
        padding: 1px 7px;
        border-radius: 99px;
        font-weight: 600;
    }
    
    .role-badge {
        font-size: 0.65rem;
        padding: 1px 7px;
        border-radius: 99px;
        border: 1px solid var(--border);
        font-weight: 600;
        text-transform: capitalize;
        color: var(--text-muted);
    }
    
    .role-badge.admin {
        background: transparent;
        color: var(--text-muted);
    }
    
    .role-badge.cashier {
        background: transparent;
        color: var(--text-muted);
    }
    
    .new-msg-card {
        background: var(--primary);
        color: var(--primary-contrast);
        border: none;
        padding: 16px 20px;
        border-radius: var(--radius-lg);
        cursor: pointer;
        transition: opacity 0.2s;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        font-weight: 600;
        font-size: 0.875rem;
        box-shadow: none;
    }
    
    .new-msg-card:hover {
        opacity: 0.88;
    }
</style>

<!-- Statistics Cards -->
<div class="stats-row">
    <div class="stat-card primary">
        <div class="stat-info">
            <h6>Total conversations</h6>
            <h3><?php echo count($conversations); ?></h3>
        </div>
        <div class="stat-icon">
            <i class="fas fa-comments"></i>
        </div>
    </div>
    
    <div class="stat-card warning">
        <div class="stat-info">
            <h6>Unread messages</h6>
            <h3><?php echo $unread_count; ?></h3>
        </div>
        <div class="stat-icon">
            <i class="fas fa-envelope"></i>
        </div>
    </div>
    
    <div class="stat-card danger clickable new-msg-card" data-bs-toggle="modal" data-bs-target="#newMessageModal">
        <i class="fas fa-plus"></i>
        <span>New message to admin</span>
    </div>
</div>

<?php if ($message): ?>
    <div class="alert alert-<?php echo $message_type; ?> alert-dismissible fade show mx-4 mb-3" role="alert">
        <i class="fas fa-<?php echo $message_type === 'success' ? 'check-circle' : 'exclamation-circle'; ?> me-2"></i>
        <?php echo $message; ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<div class="message-layout">
    <!-- Conversations Sidebar -->
    <div class="conversation-sidebar">
        <div class="sidebar-header">
            <h5><i class="fas fa-inbox me-2"></i>Conversations</h5>
        </div>
        
        <div class="conversation-list">
            <?php if (empty($conversations)): ?>
                <div class="empty-state">
                    <i class="fas fa-inbox"></i>
                    <h5>No conversations yet</h5>
                    <p>Start by sending a message to admin</p>
                    <button class="btn btn-primary btn-sm mt-3" data-bs-toggle="modal" data-bs-target="#newMessageModal">
                        <i class="fas fa-plus me-2"></i>Send first message
                    </button>
                </div>
            <?php else: ?>
                <?php foreach ($conversations as $conv): ?>
                    <div class="conversation-item <?php echo isset($_GET['conversation_id']) && $_GET['conversation_id'] == $conv['id'] ? 'active' : ''; ?> <?php echo $conv['unread_replies'] > 0 ? 'unread' : ''; ?>" 
                         onclick="window.location.href='email.php?conversation_id=<?php echo $conv['id']; ?>'">
                        <div class="conv-subject">
                            <?php echo htmlspecialchars($conv['subject']); ?>
                            <?php if ($conv['unread_replies'] > 0): ?>
                                <span class="badge-unread"><?php echo $conv['unread_replies']; ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="conv-meta">
                            <i class="fas fa-user" style="font-size:0.7rem;"></i>
                            <?php echo htmlspecialchars($conv['sender_first_name'] . ' ' . $conv['sender_last_name']); ?>
                            <span class="role-badge <?php echo $conv['sender_role']; ?>">
                                <?php echo $conv['sender_role']; ?>
                            </span>
                        </div>
                        <div class="conv-preview">
                            <?php echo htmlspecialchars(substr($conv['message'], 0, 80)); ?>
                        </div>
                        <div class="conv-footer">
                            <span>
                                <i class="fas fa-reply" style="font-size:0.7rem;"></i>
                                <?php echo $conv['reply_count'] - 1; ?> replies
                            </span>
                            <span><?php echo date('M d, Y', strtotime($conv['created_at'])); ?></span>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Message Thread -->
    <div class="message-main">
        <?php if ($selected_conversation): ?>
            <div class="thread-header">
                <h5><?php echo htmlspecialchars($selected_conversation['subject']); ?></h5>
                <div class="thread-meta">
                    <i class="fas fa-clock me-1"></i>
                    Started <?php echo date('F d, Y \a\t g:i A', strtotime($selected_conversation['created_at'])); ?>
                </div>
            </div>
            
            <div class="message-thread">
                <!-- Original Message -->
                <div class="message-bubble <?php echo $selected_conversation['sender_id'] == $user_id ? 'sent' : 'received'; ?>">
                    <div class="bubble-header">
                        <strong>
                            <?php echo htmlspecialchars($selected_conversation['sender_first_name'] . ' ' . $selected_conversation['sender_last_name']); ?>
                            <span class="role-badge <?php echo $selected_conversation['sender_role']; ?>">
                                <?php echo $selected_conversation['sender_role']; ?>
                            </span>
                        </strong>
                        <span><?php echo date('M d, g:i A', strtotime($selected_conversation['created_at'])); ?></span>
                    </div>
                    <div class="bubble-body"><?php echo nl2br(htmlspecialchars($selected_conversation['message'])); ?></div>
                </div>
                
                <!-- Replies -->
                <?php foreach ($conversation_thread as $reply): ?>
                    <div class="message-bubble <?php echo $reply['sender_id'] == $user_id ? 'sent' : 'received'; ?>">
                        <div class="bubble-header">
                            <strong>
                                <?php echo htmlspecialchars($reply['sender_first_name'] . ' ' . $reply['sender_last_name']); ?>
                                <span class="role-badge <?php echo $reply['sender_role']; ?>">
                                    <?php echo $reply['sender_role']; ?>
                                </span>
                            </strong>
                            <span><?php echo date('M d, g:i A', strtotime($reply['created_at'])); ?></span>
                        </div>
                        <div class="bubble-body"><?php echo nl2br(htmlspecialchars($reply['message'])); ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
            
            <!-- Reply Form -->
            <div class="reply-form">
                <form method="POST">
                    <input type="hidden" name="action" value="send">
                    <input type="hidden" name="parent_id" value="<?php echo $selected_conversation['id']; ?>">
                    <input type="hidden" name="subject" value="Re: <?php echo htmlspecialchars($selected_conversation['subject']); ?>">
                    
                    <div class="reply-input-group">
                        <textarea name="message" rows="3" placeholder="Type your reply" required></textarea>
                        <button type="submit" class="reply-btn">
                            <i class="fas fa-paper-plane me-2"></i>Send reply
                        </button>
                    </div>
                </form>
            </div>
        <?php else: ?>
            <div class="empty-state">
                <i class="fas fa-envelope-open-text"></i>
                <h5>Select a conversation to view messages</h5>
                <p>Or start a new conversation with admin.</p>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- New Message Modal -->
<div class="modal fade" id="newMessageModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-envelope me-2"></i>New message to admin</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" value="send">
                    
                    <div class="mb-3">
                        <label class="form-label">Subject *</label>
                        <input type="text" class="form-control" name="subject" required placeholder="What is this about?">
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Message *</label>
                        <textarea class="form-control" name="message" rows="8" required placeholder="Type your message"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-paper-plane me-2"></i>Send message
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php
$content = ob_get_clean();
require_once 'views/layout.php';
?>
