<?php
$pageTitle = 'Notifications';
require_once __DIR__ . '/includes/config.php';
requireLogin();
$db = getDB();
$userId = (int)$_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'mark_all') {
        $stmt = $db->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ?");
        $stmt->bind_param('i', $userId); $stmt->execute(); $stmt->close();
        flash('success', 'All notifications marked as read.');
    } elseif ($action === 'mark_one') {
        $id = (int)($_POST['notification_id'] ?? 0);
        $stmt = $db->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?");
        $stmt->bind_param('ii', $id, $userId); $stmt->execute(); $stmt->close();
    }
    redirect('notifications.php');
}

$stmt = $db->prepare("SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 100");
$stmt->bind_param('i', $userId); $stmt->execute(); $result = $stmt->get_result();
$notifications = []; while ($row = $result->fetch_assoc()) { $notifications[] = $row; } $stmt->close();
require_once __DIR__ . '/includes/header.php';
?>
<section class="page-hero"><div class="container"><div class="section-tag">Account activity</div><h1>Your <span class="accent">Notifications</span></h1><p>Booking confirmations, reservation updates and account alerts.</p></div></section>
<section class="section"><div class="container">
<div class="notification-toolbar"><h2>Recent activity</h2><?php if ($notifications): ?><form method="post"><?= csrfField() ?><input type="hidden" name="action" value="mark_all"><button class="btn btn-secondary" type="submit">Mark All Read</button></form><?php endif; ?></div>
<?php if (!$notifications): ?><div class="empty-state"><strong>No notifications yet</strong><p>Your booking and order updates will appear here.</p></div><?php else: ?><div class="notification-list">
<?php foreach ($notifications as $notice): ?><article class="notification-card <?= !(int)$notice['is_read'] ? 'unread' : '' ?>"><div class="notification-icon">●</div><div><strong><?= e($notice['title']) ?></strong><p><?= e($notice['message']) ?></p><small><?= date('d M Y, h:i A', strtotime($notice['created_at'])) ?></small></div><div class="notification-actions"><?php if ($notice['link_url']): ?><a class="btn btn-secondary btn-sm" href="<?= url($notice['link_url']) ?>">Open</a><?php endif; ?><?php if (!(int)$notice['is_read']): ?><form method="post"><?= csrfField() ?><input type="hidden" name="action" value="mark_one"><input type="hidden" name="notification_id" value="<?= (int)$notice['id'] ?>"><button class="cart-remove" type="submit">Mark read</button></form><?php endif; ?></div></article><?php endforeach; ?>
</div><?php endif; ?>
</div></section>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
