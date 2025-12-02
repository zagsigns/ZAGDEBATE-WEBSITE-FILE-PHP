<?php
require_once __DIR__ . '/../config/db.php';

$debate_id = (int)($_GET['debate_id'] ?? 0);
if ($debate_id <= 0) {
    exit('<div class="label">Invalid debate.</div>');
}

$stmt = $pdo->prepare("
    SELECT c.message, c.created_at, u.name 
    FROM chat_messages c 
    JOIN users u ON c.user_id = u.id 
    WHERE c.debate_id = ? 
    ORDER BY c.created_at DESC 
    LIMIT 100
");
$stmt->execute([$debate_id]);
$messages = $stmt->fetchAll();

if (!$messages) {
    echo '<div class="label">No messages yet. Be the first to chat.</div>';
} else {
    foreach (array_reverse($messages) as $m) {
        $name = htmlspecialchars($m['name']);
        $msg  = htmlspecialchars($m['message']);
        $time = date('H:i', strtotime($m['created_at']));

        echo '<div class="chat-message">';
        echo '<strong class="chat-user">' . $name . ':</strong> ';
        echo '<span class="chat-text">' . $msg . '</span>';
        echo '<span class="chat-time">' . $time . '</span>';
        echo '</div>';
    }
}
?>
