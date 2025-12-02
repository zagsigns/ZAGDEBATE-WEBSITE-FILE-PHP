<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/app.php';
require_login();
$user = current_user();
$settings = get_settings($pdo);

$debateId = (int)($_GET['id'] ?? 0);
if ($debateId < 1) {
  http_response_code(400);
  exit('Invalid debate ID');
}

// Fetch debate
$stmt = $pdo->prepare("SELECT * FROM debates WHERE id=?");
$stmt->execute([$debateId]);
$debate = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$debate) {
  http_response_code(404);
  exit('Debate not found');
}

// Check if already joined
$check = $pdo->prepare("SELECT 1 FROM debate_participants WHERE debate_id=? AND user_id=?");
$check->execute([$debateId, $user['id']]);
if ($check->fetchColumn()) {
  header('Location: /debates/view.php?id=' . $debateId);
  exit;
}

// Determine cost
$joinCost = (int)($settings['credits_to_join'] ?? 0);
$isAdmin = is_admin($user);
$isCreator = ($debate['creator_id'] == $user['id']);
$freeJoin = $isAdmin || $isCreator;

try {
  $pdo->beginTransaction();

  if (!$freeJoin && $joinCost > 0) {
    $wallet = get_wallet($pdo, $user['id']);
    if ($wallet['credits'] < $joinCost) {
      $pdo->rollBack();
      $_SESSION['flash_error'] = "You need $joinCost credits to join this debate.";
      header('Location: /user/buy_credits.php');
      exit;
    }

    $deduct = $pdo->prepare("UPDATE wallets SET credits = credits - ? WHERE user_id=? AND credits >= ?");
    $deduct->execute([$joinCost, $user['id'], $joinCost]);

    if ($deduct->rowCount() === 0) {
      $pdo->rollBack();
      $_SESSION['flash_error'] = "Failed to deduct credits. Please try again.";
      header('Location: /user/buy_credits.php');
      exit;
    }
  }

  // Add participant
  $pdo->prepare("INSERT INTO debate_participants (debate_id, user_id, joined_at) VALUES (?,?,NOW())")
      ->execute([$debateId, $user['id']]);

  $pdo->commit();
  header('Location: /debates/view.php?id=' . $debateId);
  exit;
} catch (Exception $e) {
  $pdo->rollBack();
  http_response_code(500);
  exit('Server error: ' . $e->getMessage());
}
