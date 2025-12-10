<?php
// public_html/debates/view.php
// Full file: preserves chat and page structure; improves group video call connection speed.
// Key changes for faster connect:
// - Uses trickle ICE (trickle: true) so offers/ICE candidates are exchanged incrementally
// - Faster polling intervals for signals (600ms) and participants (2000ms)
// - Proactively creates peers for joined participants on start and sends signals immediately
// - Keeps all chat, share, copy link, and other features intact
// IMPORTANT: Back up your original file before replacing.

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/app.php';


// --- Delete handler: must run before any output or debate fetch ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_debate') {
    if (session_status() === PHP_SESSION_NONE) session_start();

    $delId = (int)($_POST['debate_id'] ?? 0);
    if ($delId <= 0) {
        $_SESSION['flash_error'] = 'Invalid debate id.';
        header('Location: /debates/list.php');
        exit;
    }

    $user = function_exists('current_user') ? current_user() : ($_SESSION['user'] ?? null);
    if (empty($user['id'])) {
        $_SESSION['flash_error'] = 'You must be logged in to delete a debate.';
        header('Location: /auth/login.php');
        exit;
    }

    $q = $pdo->prepare("SELECT id, creator_id, thumb_image, gallery_json FROM debates WHERE id = ? LIMIT 1");
    $q->execute([$delId]);
    $row = $q->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        $_SESSION['flash_error'] = 'Debate not found.';
        header('Location: /debates/list.php');
        exit;
    }

    $isOwner = ((int)$row['creator_id'] === (int)$user['id']);
    $isAdminUser = function_exists('is_admin') && is_admin($user);
    if (!($isOwner || $isAdminUser)) {
        $_SESSION['flash_error'] = 'You are not authorized to delete this debate.';
        header('Location: /debates/view.php?id=' . $delId);
        exit;
    }

    try {
        $pdo->beginTransaction();
        $pdo->prepare("DELETE FROM chat_messages WHERE debate_id = ?")->execute([$delId]);
        $pdo->prepare("DELETE FROM debate_participants WHERE debate_id = ?")->execute([$delId]);
        $pdo->prepare("DELETE FROM debate_spend WHERE debate_id = ?")->execute([$delId]);
        $pdo->prepare("DELETE FROM webrtc_signals WHERE room = ? OR room = ?")->execute(['debate_' . $delId, (string)$delId]);
        $pdo->prepare("DELETE FROM debates WHERE id = ? LIMIT 1")->execute([$delId]);
        $pdo->commit();

        // best-effort file cleanup
        if (!empty($row['thumb_image'])) {
            $local = __DIR__ . '/../' . ltrim($row['thumb_image'], '/');
            if (file_exists($local)) @unlink($local);
        }
        if (!empty($row['gallery_json'])) {
            $g = json_decode($row['gallery_json'], true);
            if (is_array($g)) {
                foreach ($g as $p) {
                    $local = __DIR__ . '/../' . ltrim($p, '/');
                    if (file_exists($local)) @unlink($local);
                }
            }
        }

        $_SESSION['flash_success'] = 'Debate deleted successfully.';
        header('Location: /debates/list.php');
        exit;
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('Delete debate error: ' . $e->getMessage());
        $_SESSION['flash_error'] = 'Failed to delete debate. Please try again later.';
        header('Location: /debates/view.php?id=' . $delId);
        exit;
    }
}
// --- end delete handler ---






// Ensure session is started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/* Helpers */
function jsonResponse($arr) {
    header('Content-Type: application/json');
    echo json_encode($arr);
    exit;
}

/* Get debate id */
$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    http_response_code(400);
    echo 'Invalid debate id';
    exit;
}

/* Load debate */
$stmt = $pdo->prepare("
  SELECT d.*, u.name AS creator_name, u.id AS creator_id
  FROM debates d
  JOIN users u ON d.creator_id = u.id
  WHERE d.id = ?
  LIMIT 1
");
$stmt->execute([$id]);
$debate = $stmt->fetch(PDO::FETCH_ASSOC);
// Ensure description is always a string to avoid PHP 8.1+ deprecation warnings
$debate_description = isset($debate['description']) ? (string)$debate['description'] : '';

if (!$debate) {
    http_response_code(404);
    echo 'Debate not found';
    exit;
}

/* Create signaling table if not exists (best-effort) */
try {
    $pdo->exec("
      CREATE TABLE IF NOT EXISTS webrtc_signals (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        room VARCHAR(255) NOT NULL,
        from_user_id INT NOT NULL,
        to_user_id INT DEFAULT NULL,
        signal_data LONGTEXT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        delivered TINYINT(1) DEFAULT 0,
        INDEX (room),
        INDEX (to_user_id),
        INDEX (from_user_id)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
} catch (Exception $e) {
    // ignore creation errors (may lack privileges)
}

/* User context (defensive) */
$user = function_exists('current_user') ? current_user() : null;
if (!$user && !empty($_SESSION['user'])) $user = $_SESSION['user'];
$isLoggedIn = !empty($user['id']);
$userId = $isLoggedIn ? (int)$user['id'] : 0;
$isAdmin = $isLoggedIn && function_exists('is_admin') && is_admin($user);
$isCreator = $isLoggedIn && ($debate['creator_id'] == $userId);

/* Timing and counts */
$createdAt = strtotime($debate['created_at']);
$minutesSinceCreated = ($createdAt > 0) ? (time() - $createdAt) / 60 : PHP_INT_MAX;

/* Load settings (if available) */
$settings = function_exists('get_settings') ? get_settings($pdo) : [];
$access_mode = $settings['debate_access_mode'] ?? 'free';
$credits_required = (int)($settings['credits_to_join'] ?? 0);
$credit_rate = (float)($settings['credit_usd_rate'] ?? 0.10);
$free_join_limit = (int)($settings['free_join_limit'] ?? 0);
$free_join_per_debate = (int)($settings['free_join_per_debate'] ?? 0);
$free_join_time_minutes = (int)($settings['free_join_time_minutes'] ?? 0);

/* Joined check */
$joined = false;
if ($isLoggedIn) {
    $j = $pdo->prepare("SELECT id FROM debate_participants WHERE debate_id = ? AND user_id = ? LIMIT 1");
    $j->execute([$id, $userId]);
    $joined = (bool)$j->fetchColumn();
}

/* Free join allowance helper */
function freeJoinAllowedCalc($pdo, $userId, $debateId, $free_join_limit, $free_join_per_debate, $minutesSinceCreated) {
    $userJoinedCount = 0;
    $debateJoinedCount = 0;
    $joinedCountStmt = $pdo->prepare("SELECT COUNT(*) FROM debate_participants WHERE user_id = ?");
    $joinedCountStmt->execute([$userId]);
    $userJoinedCount = (int)$joinedCountStmt->fetchColumn();
    $debateJoinedStmt = $pdo->prepare("SELECT COUNT(*) FROM debate_participants WHERE debate_id = ?");
    $debateJoinedStmt->execute([$debateId]);
    $debateJoinedCount = (int)$debateJoinedStmt->fetchColumn();
    return ($userJoinedCount < $free_join_limit || $debateJoinedCount < $free_join_per_debate || $minutesSinceCreated <= $free_join_time_minutes);
}

/* Chat: AJAX endpoints and normal POST handling */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $isLoggedIn) {
    $action = $_POST['action'];

    if ($action === 'join' && !$joined) {
        $freeJoinAllowed = freeJoinAllowedCalc($pdo, $userId, $id, $free_join_limit, $free_join_per_debate, $minutesSinceCreated);
        if ($isAdmin || $isCreator || $access_mode !== 'credits' || $credits_required <= 0 || $freeJoinAllowed) {
            try {
                $ins = $pdo->prepare("INSERT INTO debate_participants (debate_id, user_id, joined_at) VALUES (?, ?, NOW())");
                $ins->execute([$id, $userId]);
                $joined = true;
                $success = 'Joined debate successfully (free access).';
            } catch (PDOException $e) {
                error_log('Join (free) failed: ' . $e->getMessage());
                if (($e->errorInfo[0] ?? '') === '23000') $joined = true;
                else $error = 'Could not join debate. Please try again later.';
            }
        } else {
            // credits flow
            try {
                $w = $pdo->prepare("SELECT id, credits FROM wallets WHERE user_id = ? LIMIT 1");
                $w->execute([$userId]);
                $walletRow = $w->fetch(PDO::FETCH_ASSOC);
                if (!$walletRow) {
                    $createW = $pdo->prepare("INSERT INTO wallets (user_id, credits, earnings_usd, created_at) VALUES (?, 0, 0, NOW())");
                    $createW->execute([$userId]);
                    $walletRow = ['id' => $pdo->lastInsertId(), 'credits' => 0];
                }
                $userCredits = (int)$walletRow['credits'];

                if ($userCredits >= $credits_required) {
                    $pdo->beginTransaction();
                    $upd = $pdo->prepare("UPDATE wallets SET credits = credits - ? WHERE user_id = ? AND credits >= ?");
                    $upd->execute([$credits_required, $userId, $credits_required]);
                    if ($upd->rowCount() === 0) {
                        $pdo->rollBack();
                        $error = "Not enough credits. You need $credits_required credits.";
                    } else {
                        $ins = $pdo->prepare("INSERT INTO debate_participants (debate_id, user_id, joined_at) VALUES (?, ?, NOW())");
                        $ins->execute([$id, $userId]);

                        $usd_value = $credits_required * $credit_rate;
                        $pdo->prepare("INSERT INTO debate_spend (debate_id, user_id, credits, usd_value, created_at) VALUES (?, ?, ?, ?, NOW())")
                            ->execute([$id, $userId, $credits_required, $usd_value]);

                        $creator_share = $usd_value * 0.50;
                        $pdo->prepare("UPDATE wallets SET earnings_usd = earnings_usd + ? WHERE user_id = ?")
                            ->execute([$creator_share, (int)$debate['creator_id']]);

                        $pdo->commit();
                        $success = "Joined debate successfully. Spent $credits_required credits.";
                        $joined = true;
                    }
                } else {
                    $error = "Not enough credits. You need $credits_required credits.";
                }
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                error_log('Join (credits) error: ' . $e->getMessage());
                $error = 'Could not join debate. Please try again later.';
            }
        }

        // respond for AJAX
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
            jsonResponse(['ok' => true, 'joined' => $joined, 'message' => $success ?: $error]);
        }
    }

    elseif ($action === 'send_message') {
        $msg = trim($_POST['message'] ?? '');
        if ($msg && $joined) {
            $ins = $pdo->prepare("INSERT INTO chat_messages (debate_id, user_id, message, created_at) VALUES (?, ?, ?, NOW())");
            $ins->execute([$id, $userId, $msg]);
            $lastId = $pdo->lastInsertId();

            // If AJAX, return the inserted message info
            if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
                $q = $pdo->prepare("SELECT cm.id, cm.message, cm.created_at, u.name FROM chat_messages cm JOIN users u ON cm.user_id = u.id WHERE cm.id = ? LIMIT 1");
                $q->execute([$lastId]);
                $row = $q->fetch(PDO::FETCH_ASSOC);
                jsonResponse(['ok' => true, 'message' => $row]);
            }

            // Normal POST: redirect
            header('Location: ' . $_SERVER['REQUEST_URI'] . '#chat');
            exit;
        } else {
            if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
                jsonResponse(['ok' => false, 'error' => 'Join the debate to chat.']);
            }
            $error = 'Join the debate to chat.';
        }
    }

    elseif ($action === 'delete_message' && !empty($_POST['message_id'])) {
        $msgId = (int)$_POST['message_id'];
        $canDelete = false;
        if ($isAdmin) $canDelete = true;
        else {
            $ownerStmt = $pdo->prepare("SELECT user_id FROM chat_messages WHERE id = ? LIMIT 1");
            $ownerStmt->execute([$msgId]);
            $owner = $ownerStmt->fetchColumn();
            if ($owner && (int)$owner === $userId) $canDelete = true;
        }
        if ($canDelete) {
            $del = $pdo->prepare("DELETE FROM chat_messages WHERE id = ?");
            $del->execute([$msgId]);
            if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
                jsonResponse(['ok' => true, 'deleted' => $msgId]);
            } else {
                header('Location: ' . $_SERVER['REQUEST_URI'] . '#chat');
                exit;
            }
        } else {
            if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
                jsonResponse(['ok' => false, 'error' => 'You are not allowed to delete this message.']);
            }
            $error = 'You are not allowed to delete this message.';
        }
    }
}

/* AJAX-only endpoints for chat polling and participants (used by client) */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action'])) {
    $ajax_action = $_POST['ajax_action'];

    if ($ajax_action === 'fetch_messages') {
        $since_id = isset($_POST['since_id']) ? (int)$_POST['since_id'] : 0;
        $q = $pdo->prepare("
          SELECT cm.id, cm.message, cm.created_at, cm.user_id, u.name AS user_name
          FROM chat_messages cm
          JOIN users u ON cm.user_id = u.id
          WHERE cm.debate_id = ? AND cm.id > ?
          ORDER BY cm.created_at ASC
          LIMIT 200
        ");
        $q->execute([$id, $since_id]);
        $rows = $q->fetchAll(PDO::FETCH_ASSOC);
        jsonResponse(['ok' => true, 'messages' => $rows]);
    }

    if ($ajax_action === 'fetch_participants') {
        // return joined participants with user names
        $q = $pdo->prepare("
          SELECT dp.user_id, u.name AS user_name
          FROM debate_participants dp
          JOIN users u ON dp.user_id = u.id
          WHERE dp.debate_id = ?
        ");
        $q->execute([$id]);
        $rows = $q->fetchAll(PDO::FETCH_ASSOC);
        jsonResponse(['ok' => true, 'participants' => $rows]);
    }
}

/* Signaling endpoints (webrtc=1) */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['webrtc']) && $_POST['webrtc'] === '1' && isset($_POST['action'])) {
    $action = $_POST['action'];

    if ($action === 'signal_send') {
        $room = $_POST['room'] ?? '';
        $from = (int)($_POST['from_user_id'] ?? 0);
        $to = isset($_POST['to_user_id']) && $_POST['to_user_id'] !== '' ? (int)$_POST['to_user_id'] : null;
        $signal = $_POST['signal'] ?? '';

        if (!$room || !$from || !$signal) {
            jsonResponse(['ok' => false, 'error' => 'Missing parameters']);
        }

        try {
            $ins = $pdo->prepare("INSERT INTO webrtc_signals (room, from_user_id, to_user_id, signal_data) VALUES (?, ?, ?, ?)");
            $ins->execute([$room, $from, $to, $signal]);
            jsonResponse(['ok' => true, 'id' => $pdo->lastInsertId()]);
        } catch (Exception $e) {
            jsonResponse(['ok' => false, 'error' => 'DB insert failed']);
        }
    }

    if ($action === 'signal_poll') {
        $room = $_POST['room'] ?? '';
        $to = (int)($_POST['to_user_id'] ?? 0);
        $since_id = isset($_POST['since_id']) ? (int)$_POST['since_id'] : 0;

        if (!$room || !$to) {
            jsonResponse(['ok' => false, 'error' => 'Missing parameters']);
        }

        try {
            $q = $pdo->prepare("
              SELECT id, room, from_user_id, to_user_id, signal_data, created_at
              FROM webrtc_signals
              WHERE room = ?
                AND id > ?
                AND (to_user_id = ? OR to_user_id IS NULL)
              ORDER BY id ASC
              LIMIT 200
            ");
            $q->execute([$room, $since_id, $to]);
            $rows = $q->fetchAll(PDO::FETCH_ASSOC);

            // Mark as delivered for those specifically addressed to this user
            $idsToMark = [];
            foreach ($rows as $r) {
                if ((int)$r['to_user_id'] === $to) $idsToMark[] = (int)$r['id'];
            }
            if (!empty($idsToMark)) {
                $in = implode(',', array_fill(0, count($idsToMark), '?'));
                $stmt = $pdo->prepare("UPDATE webrtc_signals SET delivered = 1 WHERE id IN ($in)");
                $stmt->execute($idsToMark);
            }

            jsonResponse(['ok' => true, 'signals' => $rows]);
        } catch (Exception $e) {
            jsonResponse(['ok' => false, 'error' => 'DB query failed']);
        }
    }

    if ($action === 'signal_cleanup') {
        $room = $_POST['room'] ?? '';
        $user = (int)($_POST['user_id'] ?? 0);
        if (!$room || !$user) jsonResponse(['ok' => false, 'error' => 'Missing parameters']);
        try {
            $del = $pdo->prepare("DELETE FROM webrtc_signals WHERE room = ? AND from_user_id = ?");
            $del->execute([$room, $user]);
            jsonResponse(['ok' => true]);
        } catch (Exception $e) {
            jsonResponse(['ok' => false, 'error' => 'Cleanup failed']);
        }
    }
}

/* Auto-join for eligible users (defensive) */
if ($isLoggedIn && !$joined) {
    $freeJoinAllowed = freeJoinAllowedCalc($pdo, $userId, $id, $free_join_limit, $free_join_per_debate, $minutesSinceCreated);
    $allowAutoJoin = $isAdmin || $isCreator || $access_mode !== 'credits' || $freeJoinAllowed;
    if ($allowAutoJoin) {
        try {
            $ins = $pdo->prepare("INSERT INTO debate_participants (debate_id, user_id, joined_at) VALUES (?, ?, NOW())");
            $ins->execute([$id, $userId]);
            $joined = true;
        } catch (PDOException $e) {
            error_log('Auto-join failed: ' . $e->getMessage());
            if (($e->errorInfo[0] ?? '') === '23000') $joined = true;
        }
    }
}

/* Share metadata */
$debateTitle = trim($debate['title']);
// Safe meta description (use normalized description)
$debateDesc = trim(mb_substr(strip_tags($debate_description), 0, 200));

$siteBase = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'zagdebate.com');
$debateUrl = $siteBase . '/debates/view.php?id=' . (int)$debate['id'];
$debateImage = !empty($debate['thumb_image']) ? (strpos($debate['thumb_image'], 'http') === 0 ? $debate['thumb_image'] : $siteBase . $debate['thumb_image']) : $siteBase . '/assets/img/default_thumb.jpg';

/* Image dimensions */
$imgWidth = 1200;
$imgHeight = 630;
$localThumbPath = __DIR__ . '/../' . ltrim($debate['thumb_image'] ?? '', '/');
if (!empty($debate['thumb_image']) && file_exists($localThumbPath)) {
    $size = @getimagesize($localThumbPath);
    if ($size && isset($size[0], $size[1])) {
        $imgWidth = (int)$size[0];
        $imgHeight = (int)$size[1];
    }
}

/* Load recent chat messages for initial render (last 500) */
$messages = $pdo->prepare("
  SELECT cm.*, u.name AS user_name
  FROM chat_messages cm
  JOIN users u ON cm.user_id = u.id
  WHERE cm.debate_id = ?
  ORDER BY cm.created_at ASC
  LIMIT 500
");
$messages->execute([$id]);
$chatMessages = $messages->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php $meta_title = htmlspecialchars($debate['title']) . ' • Debate • ZAG DEBATE'; include __DIR__ . '/../seo/meta.php'; ?>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link rel="stylesheet" href="/assets/css/style.css">
  <meta property="og:title" content="<?= htmlspecialchars($debateTitle, ENT_QUOTES) ?>">
  <meta property="og:description" content="<?= htmlspecialchars($debateDesc, ENT_QUOTES) ?>">
  <meta property="og:image" content="<?= htmlspecialchars($debateImage, ENT_QUOTES) ?>">
  <meta property="og:image:width" content="<?= $imgWidth ?>">
  <meta property="og:image:height" content="<?= $imgHeight ?>">
  <meta property="og:url" content="<?= htmlspecialchars($debateUrl, ENT_QUOTES) ?>">
  <meta name="twitter:card" content="summary_large_image">
  <meta name="twitter:title" content="<?= htmlspecialchars($debateTitle, ENT_QUOTES) ?>">
  <meta name="twitter:description" content="<?= htmlspecialchars($debateDesc, ENT_QUOTES) ?>">
  <meta name="twitter:image" content="<?= htmlspecialchars($debateImage, ENT_QUOTES) ?>">

  <style>
    :root { --accent:#e03b3b; --muted:rgba(255,255,255,0.6); --card-bg:rgba(6,10,16,0.6); --border:rgba(255,255,255,0.06); --control-size:56px; --video-gap:12px; --tile-min:160px; }
    body { background: linear-gradient(180deg,#05060a,#07101a); color:#e6eef8; font-family:Inter, system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial; }
    .container { max-width:1200px; margin:22px auto; padding:0 18px; box-sizing:border-box; }
    .card { background: rgba(8,12,18,0.55); border-radius:12px; padding:20px; color:inherit; margin-bottom:18px; border:1px solid rgba(255,255,255,0.03); box-shadow: 0 8px 30px rgba(0,0,0,0.6); }
    h2 { margin:0 0 6px 0; font-size:1.45rem; letter-spacing: -0.2px; }
    .label { color:var(--muted); margin:0 0 8px 0; font-size:0.95rem; }

    .debate-thumb { width:100%; max-height:420px; object-fit:cover; border-radius:10px; border:1px solid var(--border); margin-top:12px; }

    .share-row { display:flex; gap:10px; align-items:center; flex-wrap:wrap; margin-top:14px; }
    .share-btn { padding:10px 14px; border-radius:10px; font-weight:700; background:transparent; border:1px solid rgba(255,255,255,0.06); color:inherit; display:inline-flex; gap:10px; align-items:center; cursor:pointer; transition: transform .12s ease, background .12s ease; }
    .share-btn.primary { background: linear-gradient(90deg,#ff6b6b,var(--accent)); color:#fff; border:none; }
    .share-btn:hover { transform: translateY(-3px); }

    /* Chat */
    .chat-wrap { display:flex; flex-direction:column; gap:12px; width:100%; }
    #chatBox { max-height:420px; overflow:auto; border:1px solid var(--border); border-radius:10px; padding:12px; background: linear-gradient(180deg, rgba(12,18,28,0.6), rgba(6,10,16,0.55)); box-shadow: inset 0 1px 0 rgba(255,255,255,0.02); scroll-behavior: smooth; }
    .chat-message { display:flex; gap:10px; margin-bottom:12px; max-width:100%; width:100%; align-items:flex-end; }
    .chat-message.you { justify-content:flex-end; }
    .chat-avatar { width:44px; height:44px; border-radius:50%; flex:0 0 44px; background:linear-gradient(135deg,#2b2b2b,#111); display:flex; align-items:center; justify-content:center; color:#fff; font-weight:700; font-size:0.95rem; text-transform:uppercase; border:1px solid rgba(255,255,255,0.04); }
    .message-content { display:flex; flex-direction:column; max-width:78%; }
    .chat-bubble { padding:12px 14px; border-radius:14px; line-height:1.35; font-size:0.95rem; word-break:break-word; box-shadow: 0 10px 30px rgba(2,6,23,0.45); max-width:100%; }
    .chat-bubble.other { background: linear-gradient(180deg,#0f1724,#0b1220); color:#e6eef8; border:1px solid rgba(255,255,255,0.03); }
    .chat-bubble.you { background: linear-gradient(90deg,#ff6b6b,var(--accent)); color:#fff; border:none; }
    .chat-meta { display:flex; gap:8px; align-items:center; margin-top:8px; font-size:0.78rem; color:rgba(255,255,255,0.55); flex-wrap:wrap; }
    .chat-time { background: rgba(255,255,255,0.03); padding:6px 10px; border-radius:999px; font-size:0.78rem; color:rgba(255,255,255,0.65); }

    .chat-input-row { display:flex; gap:10px; margin-top:12px; align-items:center; }
    .chat-input { flex:1; padding:12px 14px; border-radius:12px; border:1px solid rgba(255,255,255,0.04); background: rgba(0,0,0,0.35); color:inherit; outline:none; font-size:0.95rem; box-sizing:border-box; }
    .btn { padding:10px 14px; border-radius:10px; background:var(--accent); color:#fff; border:none; cursor:pointer; font-weight:800; text-decoration:none; display:inline-block; box-shadow: 0 10px 30px rgba(224,59,59,0.12); }
    .btn:hover { transform: translateY(-2px); }

    /* Video call */
    .call-controls { display:flex; gap:14px; align-items:center; margin-top:12px; flex-wrap:wrap; }
    .control-btn { width:var(--control-size); height:var(--control-size); border-radius:50%; display:inline-flex; align-items:center; justify-content:center; font-size:20px; color:#fff; border:none; cursor:pointer; box-shadow:0 12px 30px rgba(0,0,0,0.45); }
    .control-btn.primary { background: linear-gradient(90deg,#ff6b6b,var(--accent)); }
    .control-btn.danger { background: linear-gradient(90deg,#ef4444,#dc2626); }

    .video-stage { display:grid; gap:var(--video-gap); grid-template-columns: repeat(auto-fill, minmax(var(--tile-min), 1fr)); align-items:stretch; width:100%; }
    .video-wrap { position:relative; overflow:hidden; border-radius:12px; border:1px solid rgba(255,255,255,0.04); background:#000; min-height:140px; display:flex; align-items:center; justify-content:center; cursor:pointer; transition: transform .14s ease, box-shadow .14s ease; }
    .video-wrap:hover { transform: translateY(-6px); box-shadow: 0 30px 60px rgba(0,0,0,0.6); }
    .video-wrap video { width:100%; height:100%; object-fit:cover; display:block; border-radius:12px; }
    .video-meta { position:absolute; left:12px; bottom:12px; background: rgba(0,0,0,0.45); color:#fff; padding:8px 12px; border-radius:12px; font-size:0.9rem; display:flex; gap:8px; align-items:center; font-weight:700; }

    .self-tile { position: fixed; right: 22px; bottom: 22px; width:150px; height:190px; border-radius:12px; overflow:hidden; border:1px solid rgba(255,255,255,0.06); background:#000; z-index:2000; cursor:pointer; box-shadow:0 20px 60px rgba(0,0,0,0.6); transition: transform .12s ease, box-shadow .12s ease; }
    .self-tile:hover { transform: translateY(-6px); box-shadow:0 30px 80px rgba(0,0,0,0.7); }
    .self-tile video { width:100%; height:100%; object-fit:cover; display:block; }

    .video-wrap.zoomed, .self-tile.zoomed { position: fixed !important; top:50% !important; left:50% !important; transform: translate(-50%, -50%) !important; width:92vw !important; height:72vh !important; z-index:99999 !important; border-radius:12px !important; box-shadow:0 40px 120px rgba(0,0,0,0.85) !important; }
    .video-wrap.zoomed video, .self-tile.zoomed video { object-fit:contain; }

    .video-empty { color:var(--muted); padding:18px; border:1px dashed var(--border); border-radius:8px; text-align:center; }

    @media (max-width:900px) { :root { --control-size:48px; --tile-min:140px; } .self-tile { width:120px; height:150px; right:14px; bottom:14px; } }
    @media (max-width:640px) { :root { --control-size:44px; --tile-min:110px; } .self-tile { display:none; } }
  </style>
</head>
<body>
  <?php include __DIR__ . '/../includes/header.php'; ?>

  <div class="container">
    <div class="card">
      <h2><?= htmlspecialchars($debate['title']) ?></h2>
      <p class="label">Hosted by <?= htmlspecialchars($debate['creator_name']) ?></p>

      <?php if (!empty($debate['thumb_image'])):
        $thumbPath = __DIR__ . '/../' . ltrim($debate['thumb_image'], '/');
        if (file_exists($thumbPath)):
      ?>
          <img class="debate-thumb" src="<?= htmlspecialchars($debate['thumb_image']) ?>" alt="Thumb">
      <?php else: ?>
          <img class="debate-thumb" src="<?= htmlspecialchars($debateImage) ?>" alt="Thumb">
      <?php endif; endif; ?>

      <p style="margin-top:12px; line-height:1.6; color:rgba(255,255,255,0.9);"><?= nl2br(htmlspecialchars($debate_description, ENT_QUOTES, 'UTF-8')) ?></p>
      
      
      



<?php if ($isLoggedIn && ($isCreator || $isAdmin)): ?>
  <div class="edit-action" style="margin-top:18px; margin-bottom:10px; display:flex; gap:8px; align-items:center;">
    <a href="/debates/edit.php?id=<?= (int)$debate['id'] ?>"
       class="btn"
       role="button"
       aria-label="Edit debate"
       style="background:#e03b3b; color:#fff; border:none; display:inline-flex; gap:8px; align-items:center; padding:10px 14px; border-radius:10px; font-weight:700;">
      <span style="font-size:16px">✏️</span> Edit
    </a>

    <form method="post" style="display:inline;" onsubmit="return confirm('Delete this debate? This cannot be undone.');">
      <input type="hidden" name="action" value="delete_debate">
      <input type="hidden" name="debate_id" value="<?= (int)$debate['id'] ?>">
      <button type="submit"
              class="btn"
              style="background:#e03b3b; color:#fff; border:none; display:inline-flex; gap:8px; align-items:center; padding:10px 14px; border-radius:10px; font-weight:700;"
              aria-label="Delete debate">
        <span style="font-size:16px">🗑️</span> Delete
      </button>
    </form>
  </div>
<?php endif; ?>








      <div class="share-row">
        <button class="share-btn primary" id="shareBtn" type="button" title="Share this debate"><span style="font-size:16px">📤</span> Share</button>
        <button class="share-btn" id="copyLinkBtn" type="button" title="Copy debate link"><span style="font-size:16px">🔗</span> Copy link</button>
      </div>

      <?php if (!empty($success)): ?><div class="alert alert-success" style="margin-top:12px"><?= htmlspecialchars($success) ?></div><?php endif; ?>
      <?php if (!empty($error)): ?><div class="alert alert-error" style="margin-top:12px"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    </div>

    <!-- Chat -->
    <div class="card" id="chat">
      <h3>Group chat</h3>
      <div class="chat-wrap">
        <div id="chatBox" data-debate-id="<?= (int)$debate['id'] ?>" aria-live="polite" role="log">
          <?php foreach ($chatMessages as $m):
            $isYou = $isLoggedIn && isset($user['id']) && ((int)$m['user_id'] === (int)$user['id']);
            $canDelete = $isAdmin || $isYou;
            $name = $m['user_name'] ?? 'User';
            $ts = strtotime($m['created_at']) ?: time();
            $text = nl2br(htmlspecialchars($m['message']));
            $initials = '';
            $parts = preg_split('/\s+/', trim($name));
            if (count($parts) === 1) $initials = strtoupper(substr($parts[0],0,2));
            else $initials = strtoupper(substr($parts[0],0,1) . substr(end($parts),0,1));
          ?>
            <div class="chat-message <?= $isYou ? 'you' : 'other' ?>" data-msg-id="<?= (int)$m['id'] ?>">
              <?php if (!$isYou): ?><div class="chat-avatar"><?= htmlspecialchars($initials) ?></div><?php endif; ?>
              <div class="message-content <?= $isYou ? 'you' : 'other' ?>">
                <div style="display:flex; align-items:flex-start; width:100%;">
                  <?php if ($isYou && $canDelete): ?>
                    <div class="msg-actions"><button class="msg-delete" data-msg-id="<?= (int)$m['id'] ?>" title="Delete message">🗑️</button></div>
                  <?php endif; ?>
                  <div class="chat-bubble <?= $isYou ? 'you' : 'other' ?>"><?= $text ?></div>
                  <?php if (!$isYou && $canDelete): ?>
                    <div class="msg-actions"><button class="msg-delete" data-msg-id="<?= (int)$m['id'] ?>" title="Delete message">🗑️</button></div>
                  <?php endif; ?>
                </div>
                <div class="chat-meta <?= $isYou ? 'you' : 'other' ?>">
                  <div class="name" style="font-weight:700;font-size:0.9rem;color:<?= $isYou ? 'rgba(255,255,255,0.95)' : 'rgba(255,255,255,0.85)' ?>;"><?= htmlspecialchars($name) ?></div>
                  <div class="chat-time"><?= date('H:i', $ts) ?></div>
                </div>
              </div>
              <?php if ($isYou): ?><div class="chat-avatar"><?= htmlspecialchars($initials) ?></div><?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>

        <?php if ($joined && $isLoggedIn): ?>
          <form id="chatForm" method="post" style="display:flex;flex-direction:column;margin-top:12px" onsubmit="return false;">
            <div class="chat-input-row">
              <input id="chatMessage" class="chat-input" type="text" name="message" placeholder="Type message..." autocomplete="off" required>
              <button id="chatSendBtn" class="btn" type="button">Send ✉️</button>
            </div>
          </form>
        <?php else: ?>
          <?php if (!$isLoggedIn): ?>
            <div style="margin-top:12px">
                <a class="login-cta btn" href="/auth/login.php" title="Login to participate">
                <span style="font-size:18px">🔐</span> Login to participate in this debate
                </a>
            </div>
          <?php else: ?>
            <p class="label">Join to participate in chat.</p>
          <?php endif; ?>
        <?php endif; ?>
      </div>
    </div>

    <!-- Video call -->
    <div class="card">
      <h3>Group video call</h3>
      <p class="label">Join with camera and mic to participate in the live group call. Click any tile to zoom it.</p>

      <?php if ($joined && $isLoggedIn): ?>
        <div style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:12px; align-items:center;">
          <button class="btn" id="startBtn" type="button">Enable camera & mic</button>
          <button class="btn" id="leaveBtn" type="button" style="display:none">Leave call</button>
          <div style="margin-left:auto; display:flex; gap:8px; align-items:center;">
            <button id="muteBtn" class="control-btn primary" title="Mute / Unmute">🔇</button>
            <button id="camBtn" class="control-btn primary" title="Toggle Camera">📷</button>
            <button id="hangupBtn" class="control-btn danger" title="Leave call">📴</button>
          </div>
        </div>

        <div style="position:relative;margin-top:14px">
          <div style="display:flex;gap:12px;align-items:flex-start">
            <div style="flex:1">
              <div id="remoteVideos" class="video-stage" aria-live="polite">
                <div class="video-empty">No participants yet. Enable camera & mic to join the call.</div>
              </div>
            </div>

            <div id="selfTile" class="self-tile" title="Click to zoom your video" style="display:none">
              <video id="localVideo" autoplay muted playsinline></video>
            </div>
          </div>
        </div>

        <div id="status" class="label" style="margin-top:12px">Ready. Click "Enable camera & mic".</div>
      <?php else: ?>
        <p class="label">Join the debate to enable video calls.</p>
      <?php endif; ?>
    </div>

  </div>

  <?php include __DIR__ . '/../includes/footer.php'; ?>

  <!-- simple-peer -->
  <script src="https://unpkg.com/simple-peer@9.11.1/simplepeer.min.js"></script>

  <script>
  // Shared data
  const debateUrl = <?= json_encode($debateUrl) ?>;
  const debateTitle = <?= json_encode($debateTitle) ?>;
  const debateDesc = <?= json_encode($debateDesc) ?>;
  const debateImage = <?= json_encode($debateImage) ?>;
  const currentUserId = <?= json_encode($userId ?: null) ?>;
  const currentUserName = <?= json_encode($user['name'] ?? 'You') ?>;

  // Restore share & copy
  document.getElementById('copyLinkBtn')?.addEventListener('click', function () {
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(debateUrl).then(function () {
        alert('Link copied to clipboard');
      }, function () {
        prompt('Copy this link', debateUrl);
      });
    } else {
      prompt('Copy this link', debateUrl);
    }
  });

  document.getElementById('shareBtn')?.addEventListener('click', function () {
    if (navigator.share) {
      navigator.share({ title: debateTitle, text: debateDesc, url: debateUrl }).catch(()=>{});
    } else {
      const waText = encodeURIComponent(debateTitle + ' ' + debateUrl);
      window.open('https://wa.me/?text=' + waText, '_blank', 'noopener');
    }
  });

  /* Chat polling and send (keeps working as before) */
  (function () {
    const chatBox = document.getElementById('chatBox');
    const chatInput = document.getElementById('chatMessage');
    const chatSendBtn = document.getElementById('chatSendBtn');
    let lastMessageId = 0;
    (function initLastId() {
      const nodes = chatBox.querySelectorAll('[data-msg-id]');
      for (const n of nodes) {
        const id = parseInt(n.dataset.msgId || 0);
        if (id > lastMessageId) lastMessageId = id;
      }
    })();

    function formatTime(ts) {
      const d = new Date(ts * 1000 || ts);
      const hh = String(d.getHours()).padStart(2,'0');
      const mm = String(d.getMinutes()).padStart(2,'0');
      return hh + ':' + mm;
    }
    function initials(name) {
      if (!name) return 'U';
      const parts = name.trim().split(/\s+/);
      if (parts.length === 1) return parts[0].slice(0,2).toUpperCase();
      return (parts[0][0] + parts[parts.length-1][0]).toUpperCase();
    }
    function escapeHtml(s) {
      if (!s) return '';
      return s.replace(/[&<>"']/g, function (m) {
        return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]);
      }).replace(/\n/g, '<br>');
    }
    function createMessageElement(m) {
      const isYou = (m.user_id && parseInt(m.user_id) === parseInt(currentUserId));
      const row = document.createElement('div');
      row.className = 'chat-message' + (isYou ? ' you' : ' other');
      row.dataset.msgId = m.id || '';
      const avatar = document.createElement('div');
      avatar.className = 'chat-avatar';
      avatar.textContent = initials(m.user_name || 'User');
      const container = document.createElement('div');
      container.className = 'message-content ' + (isYou ? 'you' : 'other');
      const topRow = document.createElement('div');
      topRow.style.display = 'flex';
      topRow.style.alignItems = 'flex-start';
      topRow.style.width = '100%';
      const bubble = document.createElement('div');
      bubble.className = 'chat-bubble ' + (isYou ? 'you' : 'other');
      bubble.innerHTML = escapeHtml(m.message || '');
      topRow.appendChild(bubble);
      const meta = document.createElement('div');
      meta.className = 'chat-meta ' + (isYou ? 'you' : 'other');
      const nameEl = document.createElement('div');
      nameEl.style.fontWeight = '700';
      nameEl.style.fontSize = '0.85rem';
      nameEl.style.color = isYou ? 'rgba(255,255,255,0.9)' : 'rgba(255,255,255,0.8)';
      nameEl.textContent = m.user_name || 'User';
      const timeEl = document.createElement('div');
      timeEl.className = 'chat-time';
      timeEl.textContent = formatTime(Math.floor(new Date(m.created_at).getTime()/1000));
      meta.appendChild(nameEl);
      meta.appendChild(timeEl);
      container.appendChild(topRow);
      container.appendChild(meta);
      if (isYou) {
        row.appendChild(container);
        row.appendChild(avatar);
      } else {
        row.appendChild(avatar);
        row.appendChild(container);
      }
      return row;
    }

    async function fetchMessages() {
      try {
        const fd = new FormData();
        fd.append('ajax_action', 'fetch_messages');
        fd.append('since_id', lastMessageId);
        const res = await fetch(location.pathname + location.search, { method: 'POST', credentials: 'same-origin', body: fd });
        const json = await res.json();
        if (json && json.ok && Array.isArray(json.messages) && json.messages.length) {
          for (const m of json.messages) {
            const el = createMessageElement(m);
            chatBox.appendChild(el);
            lastMessageId = Math.max(lastMessageId, parseInt(m.id || 0));
          }
          chatBox.scrollTop = chatBox.scrollHeight - chatBox.clientHeight;
        }
      } catch (e) {
        console.warn('fetchMessages error', e);
      }
    }

    async function sendMessage() {
      const text = (chatInput.value || '').trim();
      if (!text) return;
      chatSendBtn.disabled = true;
      const temp = { id: 't' + Date.now(), message: text, created_at: new Date().toISOString(), user_id: currentUserId, user_name: currentUserName };
      chatBox.appendChild(createMessageElement(temp));
      chatBox.scrollTop = chatBox.scrollHeight - chatBox.clientHeight;

      const fd = new FormData();
      fd.append('action', 'send_message');
      fd.append('message', text);
      try {
        const res = await fetch(location.pathname + location.search, { method: 'POST', credentials: 'same-origin', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' } });
        const json = await res.json();
        if (json && json.ok && json.message) {
          const tempEl = chatBox.querySelector('[data-msg-id^="t"]');
          if (tempEl) tempEl.dataset.msgId = json.message.id;
          lastMessageId = Math.max(lastMessageId, parseInt(json.message.id || 0));
        }
      } catch (e) {
        console.warn('sendMessage error', e);
      } finally {
        chatInput.value = '';
        chatInput.focus();
        chatSendBtn.disabled = false;
      }
    }

    async function onDeleteClick(e) {
      const btn = e.currentTarget;
      const msgId = btn.dataset.msgId;
      if (!msgId) return;
      if (!confirm('Delete this message? This cannot be undone.')) return;
      const fd = new FormData();
      fd.append('action', 'delete_message');
      fd.append('message_id', msgId);
      try {
        const res = await fetch(location.pathname + location.search, { method: 'POST', credentials: 'same-origin', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' } });
        const json = await res.json();
        if (json && json.ok) {
          const el = chatBox.querySelector('[data-msg-id="' + msgId + '"]');
          if (el) el.remove();
        } else {
          alert('Could not delete message.');
        }
      } catch (e) {
        alert('Could not delete message. Try again.');
      }
    }

    document.querySelectorAll('.msg-delete').forEach(btn => btn.addEventListener('click', onDeleteClick));
    if (chatSendBtn && chatInput) {
      chatSendBtn.addEventListener('click', sendMessage);
      chatInput.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' && !e.shiftKey) {
          e.preventDefault();
          sendMessage();
        }
      });
    }

    setInterval(fetchMessages, 900);
    setTimeout(fetchMessages, 500);
  })();

  /* Video call: DB-backed signaling with trickle ICE and faster polling for quicker connect */
  (function () {
    const startBtn = document.getElementById('startBtn');
    const leaveBtn = document.getElementById('leaveBtn');
    const callControls = document.getElementById('callControls');
    const localVideo = document.getElementById('localVideo');
    const selfTile = document.getElementById('selfTile');
    const remoteVideos = document.getElementById('remoteVideos');
    const statusLabel = document.getElementById('status');
    const muteBtn = document.getElementById('muteBtn');
    const camBtn = document.getElementById('camBtn');
    const hangupBtn = document.getElementById('hangupBtn');

    const debateRoom = 'debate-<?= (int)$debate['id'] ?>';
    const ICE_SERVERS = [
      { urls: 'stun:stun.l.google.com:19302' },
      { urls: 'stun:stun1.l.google.com:19302' }
      // Add TURN server here if you have one for better connectivity
    ];

    let localStream = null;
    let peers = {}; // remoteUserId -> { peer, wrap, vid }
    let pollingSignals = null;
    let pollingParticipants = null;
    let lastSignalId = 0;
    let participants = []; // array of { user_id, user_name }

    function ajaxPost(url, data) {
      return fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: data
      }).then(r => r.json());
    }

    function log(msg) {
      if (statusLabel) statusLabel.textContent = msg;
      console.log('[call]', msg);
    }

    function showControls(show) {
      if (callControls) callControls.style.display = show ? 'flex' : 'none';
      if (selfTile) selfTile.style.display = show ? 'block' : 'none';
      if (leaveBtn) leaveBtn.style.display = show ? 'inline-block' : 'none';
    }

    async function fetchParticipants() {
      try {
        const fd = new FormData();
        fd.append('ajax_action', 'fetch_participants');
        const res = await fetch(location.pathname + location.search, { method: 'POST', credentials: 'same-origin', body: fd });
        const json = await res.json();
        if (json && json.ok && Array.isArray(json.participants)) {
          participants = json.participants.map(p => ({ user_id: parseInt(p.user_id), user_name: p.user_name }));
          if (participants.length > 0) {
            const empty = remoteVideos.querySelector('.video-empty');
            if (empty) empty.remove();
          }
          // ensure tiles exist for participants (only joined users)
          for (const p of participants) {
            if (p.user_id === parseInt(currentUserId)) continue;
            if (!peers[p.user_id]) {
              const wrap = document.createElement('div');
              wrap.className = 'video-wrap';
              wrap.dataset.peerUserId = p.user_id;
              const vid = document.createElement('video');
              vid.autoplay = true; vid.playsInline = true; vid.controls = false;
              wrap.appendChild(vid);
              const meta = document.createElement('div');
              meta.className = 'video-meta';
              meta.textContent = p.user_name || ('User ' + p.user_id);
              wrap.appendChild(meta);
              wrap.addEventListener('click', () => {
                const current = document.querySelector('.video-wrap.zoomed, .self-tile.zoomed');
                if (wrap.classList.contains('zoomed')) { wrap.classList.remove('zoomed'); document.body.style.overflow = ''; }
                else { if (current) current.classList.remove('zoomed'); wrap.classList.add('zoomed'); document.body.style.overflow = 'hidden'; }
              });
              remoteVideos.appendChild(wrap);
              peers[p.user_id] = { peer: null, wrap: wrap, vid: vid, name: p.user_name };
            } else {
              peers[p.user_id].name = p.user_name;
              const meta = peers[p.user_id].wrap.querySelector('.video-meta');
              if (meta) meta.textContent = p.user_name;
            }
          }
          // remove tiles for users who left
          const currentIds = participants.map(x => x.user_id);
          Object.keys(peers).forEach(pid => {
            pid = parseInt(pid);
            if (pid === parseInt(currentUserId)) return;
            if (!currentIds.includes(pid)) {
              try { peers[pid].wrap.remove(); } catch (e) {}
              try { if (peers[pid].peer) peers[pid].peer.destroy(); } catch (e) {}
              delete peers[pid];
            }
          });
        }
      } catch (e) {
        console.warn('fetchParticipants error', e);
      }
    }

    async function sendSignal(toUserId, signal) {
      const fd = new FormData();
      fd.append('webrtc', '1');
      fd.append('action', 'signal_send');
      fd.append('room', debateRoom);
      fd.append('from_user_id', currentUserId);
      fd.append('to_user_id', toUserId === null ? '' : toUserId);
      fd.append('signal', JSON.stringify(signal));
      try {
        return await ajaxPost(location.pathname + '?id=<?= (int)$debate['id'] ?>', fd);
      } catch (e) {
        console.warn('sendSignal error', e);
        return null;
      }
    }

    async function pollSignals() {
      if (!currentUserId) return;
      const fd = new FormData();
      fd.append('webrtc', '1');
      fd.append('action', 'signal_poll');
      fd.append('room', debateRoom);
      fd.append('to_user_id', currentUserId);
      fd.append('since_id', lastSignalId);
      try {
        const res = await ajaxPost(location.pathname + '?id=<?= (int)$debate['id'] ?>', fd);
        if (res && res.ok && Array.isArray(res.signals)) {
          for (const s of res.signals) {
            lastSignalId = Math.max(lastSignalId, parseInt(s.id || 0));
            const from = parseInt(s.from_user_id);
            const signal = JSON.parse(s.signal_data || '{}');
            if (from === parseInt(currentUserId)) continue;
            if (!peers[from]) {
              const wrap = document.createElement('div');
              wrap.className = 'video-wrap';
              wrap.dataset.peerUserId = from;
              const vid = document.createElement('video');
              vid.autoplay = true; vid.playsInline = true; vid.controls = false;
              wrap.appendChild(vid);
              const meta = document.createElement('div');
              meta.className = 'video-meta';
              meta.textContent = 'Participant';
              wrap.appendChild(meta);
              wrap.addEventListener('click', () => {
                const current = document.querySelector('.video-wrap.zoomed, .self-tile.zoomed');
                if (wrap.classList.contains('zoomed')) { wrap.classList.remove('zoomed'); document.body.style.overflow = ''; }
                else { if (current) current.classList.remove('zoomed'); wrap.classList.add('zoomed'); document.body.style.overflow = 'hidden'; }
              });
              remoteVideos.appendChild(wrap);
              peers[from] = { peer: null, wrap: wrap, vid: vid, name: 'Participant' };
            }
            if (!peers[from].peer) {
              createPeer(from, false, peers[from].name);
            }
            try { peers[from].peer.signal(signal); } catch (e) { console.warn('signal apply error', e); }
          }
        }
      } catch (e) {
        console.warn('pollSignals error', e);
      }
    }

    function attachStream(videoEl, stream) {
      try { videoEl.srcObject = stream; videoEl.play().catch(()=>{}); } catch (e) { console.warn(e); }
    }

    // create peer with deterministic initiator rule; use trickle:true for faster incremental connect
    function createPeer(remoteUserId, explicitInitiator, displayName) {
      if (!localStream) {
        console.warn('localStream not ready yet for peer', remoteUserId);
        return;
      }
      if (peers[remoteUserId] && peers[remoteUserId].peer) return;

      const initiator = typeof explicitInitiator === 'boolean' ? explicitInitiator : (parseInt(currentUserId) < parseInt(remoteUserId));
      const p = new SimplePeer({
        initiator: initiator,
        trickle: true, // enable trickle for faster incremental ICE exchange
        stream: localStream,
        config: { iceServers: ICE_SERVERS }
      });

      if (!peers[remoteUserId]) {
        const wrap = document.createElement('div');
        wrap.className = 'video-wrap';
        wrap.dataset.peerUserId = remoteUserId;
        const vid = document.createElement('video');
        vid.autoplay = true; vid.playsInline = true; vid.controls = false;
        wrap.appendChild(vid);
        const meta = document.createElement('div');
        meta.className = 'video-meta';
        meta.textContent = displayName || ('User ' + remoteUserId);
        wrap.appendChild(meta);
        wrap.addEventListener('click', () => {
          const current = document.querySelector('.video-wrap.zoomed, .self-tile.zoomed');
          if (wrap.classList.contains('zoomed')) { wrap.classList.remove('zoomed'); document.body.style.overflow = ''; }
          else { if (current) current.classList.remove('zoomed'); wrap.classList.add('zoomed'); document.body.style.overflow = 'hidden'; }
        });
        remoteVideos.appendChild(wrap);
        peers[remoteUserId] = { peer: null, wrap: wrap, vid: vid, name: displayName };
      }

      peers[remoteUserId].peer = p;

      p.on('signal', async (signal) => {
        // send incremental signals (offer/answer and ICE candidates) immediately
        await sendSignal(remoteUserId, signal);
      });

      p.on('stream', (stream) => {
        attachStream(peers[remoteUserId].vid, stream);
      });

      p.on('close', () => {
        try { peers[remoteUserId].wrap.remove(); } catch (e) {}
        try { p.destroy(); } catch (e) {}
        delete peers[remoteUserId];
      });

      p.on('error', (err) => console.warn('peer error', err));

      console.log('Created peer', remoteUserId, 'initiator=', initiator);
      return p;
    }

    async function startCall() {
      if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
        alert('Camera and microphone not supported by this browser.');
        return;
      }
      try {
        log('Requesting camera & microphone...');
        const stream = await navigator.mediaDevices.getUserMedia({ video: { width: { ideal: 1280 }, height: { ideal: 720 } }, audio: true });
        localStream = stream;
        if (localVideo) { localVideo.srcObject = stream; localVideo.play().catch(()=>{}); }
        showControls(true);
        if (startBtn) startBtn.style.display = 'none';
        if (leaveBtn) leaveBtn.style.display = 'inline-block';
        log('Camera & mic enabled. Preparing peers...');

        // fetch participants and create peers for each joined user
        await fetchParticipants();
        for (const p of participants) {
          if (p.user_id === parseInt(currentUserId)) continue;
          // create peer (deterministic initiator)
          createPeer(p.user_id, null, p.user_name);
        }

        // start polling signals and participants
        pollingSignals = setInterval(pollSignals, 600); // faster polling for quicker connect
        pollingParticipants = setInterval(fetchParticipants, 2000);
        pollSignals();

      } catch (err) {
        console.error('getUserMedia error', err);
        alert('Could not access camera/microphone: ' + (err.message || err));
        log('Camera & mic not enabled.');
      }
    }

    function toggleMute() {
      if (!localStream) return;
      const tracks = localStream.getAudioTracks();
      if (!tracks.length) return;
      tracks.forEach(t => t.enabled = !t.enabled);
      const enabled = tracks[0].enabled;
      muteBtn.textContent = enabled ? '🔇' : '🔈';
    }

    function toggleCam() {
      if (!localStream) return;
      const tracks = localStream.getVideoTracks();
      if (!tracks.length) return;
      tracks.forEach(t => t.enabled = !t.enabled);
      const enabled = tracks[0].enabled;
      camBtn.textContent = enabled ? '📷' : '📷';
    }

    async function leaveCall() {
      if (!confirm('Leave the call?')) return;
      if (pollingSignals) { clearInterval(pollingSignals); pollingSignals = null; }
      if (pollingParticipants) { clearInterval(pollingParticipants); pollingParticipants = null; }
      Object.keys(peers).forEach(pid => {
        try { if (peers[pid].peer) peers[pid].peer.destroy(); } catch (e) {}
        try { peers[pid].wrap.remove(); } catch (e) {}
      });
      peers = {};
      remoteVideos.innerHTML = '<div class="video-empty">No participants yet. Enable camera & mic to join the call.</div>';
      if (localStream) { try { localStream.getTracks().forEach(t => t.stop()); } catch (e) {} localStream = null; }
      if (localVideo) { try { localVideo.pause(); localVideo.srcObject = null; } catch (e) {} }
      showControls(false);
      if (startBtn) startBtn.style.display = 'inline-block';
      if (leaveBtn) leaveBtn.style.display = 'none';
      log('Left call.');

      try {
        const fd = new FormData();
        fd.append('webrtc', '1');
        fd.append('action', 'signal_cleanup');
        fd.append('room', debateRoom);
        fd.append('user_id', currentUserId);
        await ajaxPost(location.pathname + '?id=<?= (int)$debate['id'] ?>', fd);
      } catch (e) { console.warn('cleanup error', e); }
    }

    if (selfTile) selfTile.addEventListener('click', function () {
      const el = selfTile;
      if (el.classList.contains('zoomed')) { el.classList.remove('zoomed'); document.body.style.overflow = ''; }
      else { document.querySelectorAll('.video-wrap.zoomed, .self-tile.zoomed').forEach(x => x.classList.remove('zoomed')); el.classList.add('zoomed'); document.body.style.overflow = 'hidden'; }
    });

    if (startBtn) startBtn.addEventListener('click', startCall);
    if (leaveBtn) leaveBtn.addEventListener('click', leaveCall);
    if (hangupBtn) hangupBtn.addEventListener('click', leaveCall);
    if (muteBtn) muteBtn.addEventListener('click', toggleMute);
    if (camBtn) camBtn.addEventListener('click', toggleCam);

    window.addEventListener('beforeunload', function () {
      try {
        const fd = new FormData();
        fd.append('webrtc', '1');
        fd.append('action', 'signal_cleanup');
        fd.append('room', debateRoom);
        fd.append('user_id', currentUserId);
        navigator.sendBeacon(location.pathname + '?id=<?= (int)$debate['id'] ?>', fd);
      } catch (e) {}
    });

    showControls(false);
  })();
  </script>
</body>
</html>
