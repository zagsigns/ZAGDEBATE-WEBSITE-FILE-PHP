<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/app.php';
require_login();
$user = current_user();
$id = (int)($_GET['id'] ?? 0);

// Fetch debate owned by current user
$stmt = $pdo->prepare("SELECT * FROM debates WHERE id=? AND creator_id=?");
$stmt->execute([$id, (int)$user['id']]);
$debate = $stmt->fetch();
if (!$debate) {
    $_SESSION['flash_error'] = "Debate not found or you don't have permission.";
    header('Location: /user/dashboard.php');
    exit;
}

// Load settings (future-proof: if you want to charge credits for deletion)
$settings = $pdo->query("SELECT debate_access_mode FROM settings LIMIT 1")->fetch();
$access_mode = $settings['debate_access_mode'] ?? 'free';
// Optional: define a credits_to_delete column in settings later
$credits_required = 0; // currently free

try {
    $pdo->beginTransaction();

    // If deletion requires credits in future
    if ($access_mode === 'credits' && $credits_required > 0) {
        $stmt = $pdo->prepare("SELECT credits FROM users WHERE id=?");
        $stmt->execute([$user['id']]);
        $userCredits = (int)$stmt->fetchColumn();

        if ($userCredits < $credits_required) {
            $pdo->rollBack();
            $_SESSION['flash_error'] = "You need $credits_required credits to delete a debate.";
            header('Location: /credits/buy.php');
            exit;
        }

        $deduct = $pdo->prepare("UPDATE users SET credits = credits - ? WHERE id=? AND credits >= ?");
        $deduct->execute([$credits_required, $user['id'], $credits_required]);
        if ($deduct->rowCount() === 0) {
            $pdo->rollBack();
            $_SESSION['flash_error'] = "Failed to deduct credits.";
            header('Location: /credits/buy.php');
            exit;
        }
    }

    // Clean up related records
    $pdo->prepare("DELETE FROM debate_participants WHERE debate_id=?")->execute([$id]);
    $pdo->prepare("DELETE FROM chat_messages WHERE debate_id=?")->execute([$id]);
    $pdo->prepare("DELETE FROM debate_spend WHERE debate_id=?")->execute([$id]);

    // Delete debate itself
    $pdo->prepare("DELETE FROM debates WHERE id=? AND creator_id=?")->execute([$id, (int)$user['id']]);

    $pdo->commit();
    $_SESSION['flash_success'] = "Debate deleted successfully.";
} catch (Exception $e) {
    $pdo->rollBack();
    $_SESSION['flash_error'] = "Could not delete debate.";
}

header('Location: /user/dashboard.php');
exit;
