<?php
// public_html/debates/view.php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/app.php';

// Get debate id
$id = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare("SELECT d.*, u.name AS creator_name, u.id AS creator_id 
                       FROM debates d 
                       JOIN users u ON d.creator_id=u.id 
                       WHERE d.id=?");
$stmt->execute([$id]);
$debate = $stmt->fetch();

if (!$debate) {
  http_response_code(404);
  echo 'Debate not found';
  exit;
}

/* Load settings (if available) */
$settings = function_exists('get_settings') ? get_settings($pdo) : [];
$access_mode = $settings['debate_access_mode'] ?? 'free';
$credits_required = (int)($settings['credits_to_join'] ?? 0);
$credit_rate = (float)($settings['credit_usd_rate'] ?? 0.10);
$free_join_limit = (int)($settings['free_join_limit'] ?? 0);
$free_join_per_debate = (int)($settings['free_join_per_debate'] ?? 0);
$free_join_time_minutes = (int)($settings['free_join_time_minutes'] ?? 0);

/* Gallery decode */
$gallery = [];
if (!empty($debate['gallery_json'])) {
  $gallery = json_decode($debate['gallery_json'], true) ?: [];
}

/* User context */
$user = function_exists('current_user') ? current_user() : null;
$isLoggedIn = $user && !empty($user['id']);
$isAdmin = $isLoggedIn && function_exists('is_admin') && is_admin($user);
$isCreator = $isLoggedIn && ($debate['creator_id'] == ($user['id'] ?? 0));

/* Joined check */
$joined = false;
if ($isLoggedIn) {
  $j = $pdo->prepare("SELECT id FROM debate_participants WHERE debate_id=? AND user_id=?");
  $j->execute([$id, (int)$user['id']]);
  $joined = (bool)$j->fetch();
}

/* Timing and counts */
$createdAt = strtotime($debate['created_at']);
$minutesSinceCreated = ($createdAt > 0) ? (time() - $createdAt) / 60 : PHP_INT_MAX;

$userJoinedCount = 0;
$debateJoinedCount = 0;

if ($isLoggedIn) {
  $joinedCountStmt = $pdo->prepare("SELECT COUNT(*) FROM debate_participants WHERE user_id=?");
  $joinedCountStmt->execute([(int)$user['id']]);
  $userJoinedCount = (int)$joinedCountStmt->fetchColumn();

  $debateJoinedStmt = $pdo->prepare("SELECT COUNT(*) FROM debate_participants WHERE debate_id=?");
  $debateJoinedStmt->execute([$debate['id']]);
  $debateJoinedCount = (int)$debateJoinedStmt->fetchColumn();
}

/* Free join allowance */
$freeJoinAllowed = (
  $userJoinedCount < $free_join_limit ||
  $debateJoinedCount < $free_join_per_debate ||
  $minutesSinceCreated <= $free_join_time_minutes
);

/* Messages */
$success = '';
$error = '';

/* Handle POST actions: join, send_message, delete_message */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $isLoggedIn) {
  $action = $_POST['action'];
  if ($action === 'join' && !$joined) {
    $freeJoinAllowed = (
      $userJoinedCount < $free_join_limit ||
      $debateJoinedCount < $free_join_per_debate ||
      $minutesSinceCreated <= $free_join_time_minutes
    );

    if ($isAdmin || $isCreator || $access_mode !== 'credits' || $credits_required <= 0 || $freeJoinAllowed) {
      $ins = $pdo->prepare("INSERT INTO debate_participants (debate_id, user_id) VALUES (?, ?)");
      $ins->execute([$id, (int)$user['id']]);
      $success = 'Joined debate successfully (free access).';
      $joined = true;
      $debateJoinedCount++;
      $userJoinedCount++;
    } else {
      $wallet = $pdo->prepare("SELECT credits FROM wallets WHERE user_id=?");
      $wallet->execute([(int)$user['id']]);
      $userCredits = (int)$wallet->fetchColumn();

      if ($userCredits >= $credits_required) {
        $pdo->beginTransaction();
        try {
          $pdo->prepare("UPDATE wallets SET credits=credits-? WHERE user_id=? AND credits >= ?")
              ->execute([$credits_required, (int)$user['id'], $credits_required]);

          $pdo->prepare("INSERT INTO debate_participants (debate_id, user_id) VALUES (?, ?)")->execute([$id, (int)$user['id']]);

          $usd_value = $credits_required * $credit_rate;
          $pdo->prepare("INSERT INTO debate_spend (debate_id, user_id, credits, usd_value) VALUES (?, ?, ?, ?)")
              ->execute([$id, (int)$user['id'], $credits_required, $usd_value]);

          $creator_share = $usd_value * 0.50;
          $pdo->prepare("UPDATE wallets SET earnings_usd=earnings_usd+? WHERE user_id=?")
              ->execute([$creator_share, (int)$debate['creator_id']]);

          $pdo->commit();
          $success = "Joined debate successfully. Spent $credits_required credits.";
          $joined = true;
          $debateJoinedCount++;
          $userJoinedCount++;
        } catch (Exception $e) {
          $pdo->rollBack();
          $error = 'Could not join debate. Please try again later.';
        }
      } else {
        $error = "Not enough credits. You need $credits_required credits.";
      }
    }
  } elseif ($action === 'send_message') {
    $msg = trim($_POST['message'] ?? '');
    if ($msg && $joined) {
      $ins = $pdo->prepare("INSERT INTO chat_messages (debate_id, user_id, message, created_at) VALUES (?, ?, ?, NOW())");
      $ins->execute([$id, (int)$user['id'], $msg]);
      // redirect to avoid resubmission and to show server-rendered message id
      header('Location: ' . $_SERVER['REQUEST_URI'] . '#chat');
      exit;
    } else {
      $error = 'Join the debate to chat.';
    }
  } elseif ($action === 'delete_message' && !empty($_POST['message_id'])) {
    $msgId = (int)$_POST['message_id'];
    // Only allow deletion if user owns the message or is admin
    $canDelete = false;
    if ($isAdmin) $canDelete = true;
    else {
      $ownerStmt = $pdo->prepare("SELECT user_id FROM chat_messages WHERE id=? LIMIT 1");
      $ownerStmt->execute([$msgId]);
      $owner = $ownerStmt->fetchColumn();
      if ($owner && (int)$owner === (int)$user['id']) $canDelete = true;
    }
    if ($canDelete) {
      $del = $pdo->prepare("DELETE FROM chat_messages WHERE id=?");
      $del->execute([$msgId]);
      // If AJAX request, return JSON; otherwise redirect
      if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
        header('Content-Type: application/json');
        echo json_encode(['ok' => true, 'deleted' => $msgId]);
        exit;
      } else {
        header('Location: ' . $_SERVER['REQUEST_URI'] . '#chat');
        exit;
      }
    } else {
      $error = 'You are not allowed to delete this message.';
    }
  }
}

/* Prepare share metadata */
$debateTitle = trim($debate['title']);
$debateDesc = trim(mb_substr(strip_tags($debate['description']), 0, 200));
$siteBase = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'zagdebate.com');
$debateUrl = $siteBase . '/debates/view.php?id=' . (int)$debate['id'];
$debateImage = !empty($debate['thumb_image']) ? (strpos($debate['thumb_image'], 'http') === 0 ? $debate['thumb_image'] : $siteBase . $debate['thumb_image']) : $siteBase . '/assets/img/default_thumb.jpg';

/* Attempt to determine image dimensions (best-effort) */
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
$messages = $pdo->prepare("SELECT cm.*, u.name AS user_name FROM chat_messages cm JOIN users u ON cm.user_id=u.id WHERE cm.debate_id=? ORDER BY cm.created_at ASC LIMIT 500");
$messages->execute([$id]);
$chatMessages = $messages->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php $meta_title = htmlspecialchars($debate['title']) . ' • Debate • ZAG DEBATE'; include __DIR__ . '/../seo/meta.php'; ?>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link rel="stylesheet" href="/assets/css/style.css">

  <!-- Open Graph / Twitter meta tags for rich sharing -->
  <meta property="og:title" content="<?= htmlspecialchars($debateTitle, ENT_QUOTES) ?>">
  <meta property="og:description" content="<?= htmlspecialchars($debateDesc, ENT_QUOTES) ?>">
  <meta property="og:image" content="<?= htmlspecialchars($debateImage, ENT_QUOTES) ?>">
  <meta property="og:image:secure_url" content="<?= htmlspecialchars($debateImage, ENT_QUOTES) ?>">
  <meta property="og:image:type" content="image/jpeg">
  <meta property="og:image:width" content="<?= $imgWidth ?>">
  <meta property="og:image:height" content="<?= $imgHeight ?>">
  <meta property="og:url" content="<?= htmlspecialchars($debateUrl, ENT_QUOTES) ?>">
  <meta property="og:type" content="article">
  <meta name="twitter:card" content="summary_large_image">
  <meta name="twitter:title" content="<?= htmlspecialchars($debateTitle, ENT_QUOTES) ?>">
  <meta name="twitter:description" content="<?= htmlspecialchars($debateDesc, ENT_QUOTES) ?>">
  <meta name="twitter:image" content="<?= htmlspecialchars($debateImage, ENT_QUOTES) ?>">

  <style>
    /* Layout container responsiveness */
    :root {
      --accent: #e03b3b;
      --muted: rgba(255,255,255,0.6);
      --card-bg: rgba(6,10,16,0.6);
      --border: rgba(255,255,255,0.06);
    }
    .container { max-width:1100px; margin:18px auto; padding:0 14px; box-sizing:border-box; }
    .card { background: var(--card-bg); border-radius:10px; padding:18px; color:inherit; margin-bottom:16px; }

    /* Responsive image */
    .debate-thumb { width:100%; max-height:420px; object-fit:cover; border-radius:8px; border:1px solid var(--border); }

    /* Share / copy buttons */
    .share-row { display:flex; gap:8px; align-items:center; flex-wrap:wrap; margin-top:12px; }
    .share-btn { padding:8px 12px; border-radius:8px; font-weight:600; background:transparent; border:1px solid var(--border); color:inherit; display:inline-flex; gap:8px; align-items:center; cursor:pointer; }
    .share-btn.primary { background:var(--accent); color:#fff; border:none; }

    /* Chat styles: modern, neat, professional */
    .chat-wrap { display:flex; flex-direction:column; gap:12px; width:100%; }
    #chatBox {
      max-height:420px;
      overflow:auto;
      border:1px solid var(--border);
      border-radius:10px;
      padding:12px;
      background: linear-gradient(180deg, rgba(12,18,28,0.6), rgba(6,10,16,0.55));
      box-shadow: inset 0 1px 0 rgba(255,255,255,0.02);
      scroll-behavior: smooth;
    }
    .chat-message { display:flex; gap:10px; align-items:flex-end; max-width:100%; margin-bottom:10px; }
    .chat-avatar {
      width:44px; height:44px; border-radius:50%; flex:0 0 44px;
      background:linear-gradient(135deg,#2b2b2b,#111); display:flex; align-items:center; justify-content:center;
      color:#fff; font-weight:700; font-size:0.95rem; text-transform:uppercase; border:1px solid rgba(255,255,255,0.04);
    }
    .chat-bubble { padding:10px 12px; border-radius:12px; line-height:1.35; font-size:0.95rem; max-width:78%; word-break:break-word; box-shadow: 0 6px 18px rgba(2,6,23,0.45); }
    .chat-bubble.other { background: linear-gradient(180deg,#0f1724,#0b1220); color:#e6eef8; border:1px solid rgba(255,255,255,0.03); border-top-left-radius:4px; }
    .chat-bubble.you { background: linear-gradient(90deg,#ff6b6b,var(--accent)); color:#fff; border: none; border-top-right-radius:4px; }
    .chat-meta { display:flex; gap:8px; align-items:center; margin-top:6px; font-size:0.78rem; color:rgba(255,255,255,0.55); }
    .chat-time { background: rgba(255,255,255,0.03); padding:4px 8px; border-radius:999px; font-size:0.75rem; color:rgba(255,255,255,0.65); }

    /* Delete button small */
    .msg-actions { margin-left:8px; display:flex; gap:6px; align-items:center; }
    .msg-delete { background:transparent; border:1px solid rgba(255,255,255,0.06); color:inherit; padding:6px 8px; border-radius:8px; cursor:pointer; font-weight:600; }

    /* Input row: keep previous red send button style */
    .chat-input-row { display:flex; gap:8px; margin-top:12px; align-items:center; }
    .chat-input { flex:1; padding:10px 12px; border-radius:10px; border:1px solid var(--border); background: rgba(0,0,0,0.35); color:inherit; outline:none; font-size:0.95rem; box-sizing:border-box; }
    .chat-input:focus { box-shadow: 0 6px 18px rgba(0,0,0,0.45), 0 0 0 3px rgba(224,59,59,0.08); border-color: rgba(224,59,59,0.9); }
    .btn { padding:10px 14px; border-radius:8px; background:var(--accent); color:#fff; border:none; cursor:pointer; font-weight:700; text-decoration:none; display:inline-block; }
    .btn:hover { background:#c83232; }

    /* Login CTA (when not logged in) */
    .login-cta { display:inline-flex; gap:8px; align-items:center; padding:10px 12px; border-radius:8px; background:linear-gradient(90deg,#ff6b6b,var(--accent)); color:#fff; text-decoration:none; font-weight:700; }

    /* Calls UI */
    .call-controls { display:flex; gap:10px; align-items:center; margin-top:12px; flex-wrap:wrap; }
    .control-btn { padding:8px 10px; border-radius:999px; background:transparent; color:#fff; border:1px solid var(--border); cursor:pointer; font-weight:700; }
    .control-btn.danger { background: linear-gradient(90deg,#ef4444,#dc2626); border:none; }

    /* Video grid: responsive, click-to-zoom, self tile */
    .video-stage {
      display:grid;
      gap:12px;
      grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
      align-items:stretch;
    }
    .video-wrap {
      position:relative;
      overflow:hidden;
      border-radius:12px;
      border:1px solid rgba(255,255,255,0.04);
      background:#000;
      min-height:120px;
      display:flex;
      align-items:center;
      justify-content:center;
      cursor:pointer;
      transition: transform .18s ease, box-shadow .18s ease;
    }
    .video-wrap:hover { transform: translateY(-4px); box-shadow: 0 18px 40px rgba(0,0,0,0.5); }
    .video-wrap video { width:100%; height:100%; object-fit:cover; display:block; border-radius:12px; }
    .video-meta { position:absolute; left:8px; bottom:8px; background: rgba(0,0,0,0.45); color:#fff; padding:6px 8px; border-radius:8px; font-size:0.85rem; display:flex; gap:8px; align-items:center; }

    /* Self tile small overlay */
    .self-tile {
      position: absolute;
      right: 12px;
      top: 12px;
      width:110px;
      height:140px;
      border-radius:10px;
      overflow:hidden;
      border:1px solid rgba(255,255,255,0.06);
      background:#000;
      z-index: 2000;
      cursor: pointer;
      box-shadow: 0 10px 30px rgba(0,0,0,0.5);
    }
    .self-tile video { width:100%; height:100%; object-fit:cover; display:block; }

    /* Zoomed tile */
    .video-wrap.zoomed {
      position: fixed !important;
      top: 50% !important;
      left: 50% !important;
      transform: translate(-50%, -50%) !important;
      width: 92vw !important;
      height: 72vh !important;
      z-index: 9999 !important;
      border-radius: 12px !important;
      box-shadow: 0 30px 80px rgba(0,0,0,0.7) !important;
    }
    .video-wrap.zoomed video { object-fit: contain; }

    /* Responsive layout tweaks */
    @media (max-width:900px) {
      .container { padding:0 12px; }
      #chatBox { max-height:360px; }
      .debate-thumb { max-height:360px; }
      .self-tile { width:90px; height:120px; }
    }
    @media (max-width:640px) {
      .container { padding:0 10px; }
      .chat-avatar { width:36px; height:36px; flex:0 0 36px; font-size:0.85rem; }
      .chat-bubble { max-width:72%; padding:9px 10px; }
      #chatBox { max-height:300px; padding:10px; }
      .share-row { gap:6px; }
      .share-btn, .btn { padding:8px 10px; font-size:0.95rem; }
      .video-stage { grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap:8px; }
      .self-tile { display:none; } /* hide floating self tile on very small screens */
    }
  </style>
</head>
<body>
<?php include __DIR__ . '/../includes/header.php'; ?>

<div class="container">
  <div class="card">
    <h2><?= htmlspecialchars($debate['title']) ?></h2>
    <p class="label">By <?= htmlspecialchars($debate['creator_name']) ?></p>

    <?php
    if (!empty($debate['thumb_image'])) {
      $thumbPath = __DIR__ . '/../' . ltrim($debate['thumb_image'], '/');
      if (file_exists($thumbPath)) {
        echo '<img class="debate-thumb" src="' . htmlspecialchars($debate['thumb_image']) . '" alt="Thumb">';
      } else {
        echo '<img class="debate-thumb" src="' . htmlspecialchars($debateImage) . '" alt="Thumb">';
      }
    }
    ?>

    <p style="margin-top:10px"><?= nl2br(htmlspecialchars($debate['description'])) ?></p>

    <!-- Share & Copy -->
    <div class="share-row">
      <button class="share-btn primary" id="shareBtn" type="button" title="Share this debate"><span style="font-size:16px">📤</span> Share</button>
      <button class="share-btn" id="copyLinkBtn" type="button" title="Copy debate link"><span style="font-size:16px">🔗</span> Copy link</button>
    </div>

    <?php if (!empty($success)): ?><div class="alert alert-success"><?= htmlspecialchars($success) ?></div><?php endif; ?>
    <?php if (!empty($error)): ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
  </div>

  <!-- Chat -->
  <div class="card" id="chat">
    <h3>Group chat</h3>

    <div class="chat-wrap">
      <div id="chatBox" data-debate-id="<?= (int)$debate['id'] ?>" aria-live="polite" role="log">
        <?php
        // Render existing messages server-side for initial load
        foreach ($chatMessages as $m):
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
            <?php if (!$isYou): ?>
              <div class="chat-avatar"><?= htmlspecialchars($initials) ?></div>
            <?php endif; ?>

            <div style="display:flex;flex-direction:column;align-items:<?= $isYou ? 'flex-end' : 'flex-start' ?>;">
              <div style="display:flex;align-items:flex-start;gap:8px;">
                <div class="chat-bubble <?= $isYou ? 'you' : 'other' ?>"><?= $text ?></div>
                <?php if ($canDelete): ?>
                  <div class="msg-actions">
                    <button class="msg-delete" data-msg-id="<?= (int)$m['id'] ?>" title="Delete message">🗑️</button>
                  </div>
                <?php endif; ?>
              </div>

              <div class="chat-meta">
                <div style="font-weight:700;font-size:0.85rem;color:<?= $isYou ? 'rgba(255,255,255,0.9)' : 'rgba(255,255,255,0.8)' ?>;">
                  <?= htmlspecialchars($name) ?>
                </div>
                <div class="chat-time"><?= date('H:i', $ts) ?></div>
              </div>
            </div>

            <?php if ($isYou): ?>
              <div class="chat-avatar"><?= htmlspecialchars($initials) ?></div>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>

      <?php if ($joined && $isLoggedIn): ?>
        <form id="chatForm" method="post" style="display:flex;flex-direction:column;margin-top:8px" onsubmit="return false;">
          <div class="chat-input-row">
            <input id="chatMessage" class="chat-input" type="text" name="message" placeholder="Type message..." autocomplete="off" required>
            <button id="chatSendBtn" class="btn" type="button">Send ✉️</button>
          </div>
        </form>
      <?php else: ?>
        <!-- Show login CTA prominently when user is not logged in -->
        <?php if (!$isLoggedIn): ?>
          <div style="margin-top:8px">
            <a class="login-cta" href="/auth/login.php" title="Login to participate">
              <span style="font-size:18px">🔐</span> Login to participate in this debate
            </a>
          </div>
        <?php else: ?>
          <p class="label">Join to participate in chat.</p>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  </div>

  <!-- Calls and other sections -->
  <div class="card">
    <h3>Group audio/video calls</h3>

    <?php if ($joined && $isLoggedIn): ?>
      <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:8px">
        <button class="btn" id="startBtn">Enable camera & mic</button>
        <button class="btn" id="leaveBtn" style="margin-left:8px">Leave call</button>
      </div>

      <div id="callControls" class="call-controls" style="display:none" aria-hidden="true">
        <button id="muteBtn" class="control-btn" title="Mute / Unmute">🔇</button>
        <button id="camBtn" class="control-btn" title="Toggle Camera">📷</button>
        <button id="hangupBtn" class="control-btn danger" title="Leave call">📴</button>
      </div>

      <div style="position:relative;margin-top:12px">
        <div style="display:flex;gap:12px;align-items:flex-start">
          <div style="flex:1">
            <div id="remoteVideos" class="video-stage" aria-live="polite">
              <div class="video-empty" style="color:var(--muted);padding:18px;border:1px dashed var(--border);border-radius:8px">No participants yet. Enable camera & mic to join the call.</div>
            </div>
          </div>

          <!-- Self tile (click to zoom) -->
          <div id="selfTile" class="self-tile" title="Click to zoom your video" style="display:none">
            <video id="localVideo" autoplay muted playsinline></video>
          </div>
        </div>
      </div>

      <div id="status" class="label" style="margin-top:8px">Ready. Click “Enable camera & mic”.</div>
    <?php else: ?>
      <p class="label">Join the debate to enable audio/video calls.</p>
    <?php endif; ?>
  </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>

<!-- Required libs -->
<script src="https://cdn.socket.io/4.7.2/socket.io.min.js"></script>
<script src="https://unpkg.com/simple-peer@9.11.1/simplepeer.min.js"></script>

<!-- Share & Chat Scripts -->
<script>
  const debateUrl = <?= json_encode($debateUrl) ?>;
  const debateTitle = <?= json_encode($debateTitle) ?>;
  const debateDesc = <?= json_encode($debateDesc) ?>;
  const debateImage = <?= json_encode($debateImage) ?>;
  const currentUserId = <?= json_encode($user['id'] ?? null) ?>;
  const currentUserName = <?= json_encode($user['name'] ?? 'You') ?>;
  const isAdmin = <?= json_encode($isAdmin ? true : false) ?>;

  // Copy link button
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

  // Share button: prefer Web Share API; fallback to WhatsApp web share
  document.getElementById('shareBtn')?.addEventListener('click', function () {
    if (navigator.share) {
      navigator.share({
        title: debateTitle,
        text: debateDesc,
        url: debateUrl
      }).catch(() => {});
    } else {
      const waText = encodeURIComponent(debateTitle + ' ' + debateUrl);
      const waUrl = 'https://wa.me/?text=' + waText;
      window.open(waUrl, '_blank', 'noopener');
    }
  });

  // Chat: client-side behavior for send + optimistic UI + delete
  (function () {
    const chatBox = document.getElementById('chatBox');
    const chatInput = document.getElementById('chatMessage');
    const chatSendBtn = document.getElementById('chatSendBtn');

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

    function createMessageElement(opts) {
      // opts: {id, user_id, name, text, ts, isYou, canDelete}
      const row = document.createElement('div');
      row.className = 'chat-message' + (opts.isYou ? ' you' : ' other');
      row.dataset.msgId = opts.id || '';

      const avatar = document.createElement('div');
      avatar.className = 'chat-avatar';
      avatar.textContent = initials(opts.name || 'User');

      const container = document.createElement('div');
      container.style.display = 'flex';
      container.style.flexDirection = 'column';
      container.style.alignItems = opts.isYou ? 'flex-end' : 'flex-start';

      const topRow = document.createElement('div');
      topRow.style.display = 'flex';
      topRow.style.alignItems = 'flex-start';
      topRow.style.gap = '8px';

      const bubble = document.createElement('div');
      bubble.className = 'chat-bubble ' + (opts.isYou ? 'you' : 'other');
      bubble.innerHTML = escapeHtml(opts.text);

      topRow.appendChild(bubble);

      if (opts.canDelete) {
        const actions = document.createElement('div');
        actions.className = 'msg-actions';
        const delBtn = document.createElement('button');
        delBtn.className = 'msg-delete';
        delBtn.dataset.msgId = opts.id;
        delBtn.title = 'Delete message';
        delBtn.textContent = '🗑️';
        delBtn.addEventListener('click', onDeleteClick);
        actions.appendChild(delBtn);
        topRow.appendChild(actions);
      }

      const meta = document.createElement('div');
      meta.className = 'chat-meta';
      const nameEl = document.createElement('div');
      nameEl.style.fontWeight = '700';
      nameEl.style.fontSize = '0.85rem';
      nameEl.style.color = opts.isYou ? 'rgba(255,255,255,0.9)' : 'rgba(255,255,255,0.8)';
      nameEl.textContent = opts.name || 'User';

      const timeEl = document.createElement('div');
      timeEl.className = 'chat-time';
      timeEl.textContent = formatTime(opts.ts || Math.floor(Date.now()/1000));

      meta.appendChild(nameEl);
      meta.appendChild(timeEl);

      container.appendChild(topRow);
      container.appendChild(meta);

      if (opts.isYou) {
        row.appendChild(container);
        row.appendChild(avatar);
      } else {
        row.appendChild(avatar);
        row.appendChild(container);
      }

      return row;
    }

    function appendChatMessage(opts) {
      const el = createMessageElement(opts);
      chatBox.appendChild(el);
      chatBox.scrollTop = chatBox.scrollHeight - chatBox.clientHeight;
    }

    // Delete handler (confirmation + AJAX)
    function onDeleteClick(e) {
      const btn = e.currentTarget;
      const msgId = btn.dataset.msgId;
      if (!msgId) return;
      if (!confirm('Delete this message? This cannot be undone.')) return;

      const fd = new FormData();
      fd.append('action', 'delete_message');
      fd.append('message_id', msgId);

      fetch(location.pathname + location.search, {
        method: 'POST',
        credentials: 'same-origin',
        body: fd,
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
      }).then(r => r.json()).then(json => {
        if (json && json.ok) {
          const el = chatBox.querySelector('[data-msg-id="' + msgId + '"]');
          if (el) el.remove();
        } else {
          alert('Could not delete message.');
        }
      }).catch(() => {
        alert('Could not delete message. Try again.');
      });
    }

    // Attach delete handlers to server-rendered delete buttons
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

    function sendMessage() {
      const text = (chatInput.value || '').trim();
      if (!text) return;
      chatSendBtn.disabled = true;

      // optimistic UI: create a temporary id
      const tempId = 't' + Date.now();
      appendChatMessage({ id: tempId, user_id: currentUserId, name: currentUserName, text: text, ts: Math.floor(Date.now()/1000), isYou: true, canDelete: true });

      const formData = new FormData();
      formData.append('action', 'send_message');
      formData.append('message', text);

      fetch(location.pathname + location.search, {
        method: 'POST',
        credentials: 'same-origin',
        body: formData
      }).then(() => {
        // reload fragment to get server-rendered messages (simple approach)
        // Instead of full reload, you could fetch latest messages via AJAX and reconcile.
        // For now, clear input and leave optimistic message (server will persist).
        chatInput.value = '';
        chatInput.focus();
      }).catch(() => {
        appendChatMessage({ name: 'System', text: 'Failed to send message. Try again.', ts: Math.floor(Date.now()/1000), isYou: false, canDelete: false });
      }).finally(() => {
        chatSendBtn.disabled = false;
      });
    }

    // Auto-scroll to bottom on load
    window.addEventListener('load', function () {
      chatBox.scrollTop = chatBox.scrollHeight - chatBox.clientHeight;
    });
  })();
</script>

<!-- Group call script (requires Socket.IO and SimplePeer loaded above) -->
<script>
(function () {
  // CONFIG: update signalingURL to your signaling server if different
  const signalingURL = 'https://zagdebate-signaling.onrender.com';
  const roomId = 'debate-' + <?= (int)$debate['id'] ?>;

  // ICE servers: add TURN credentials here if you have them
  const ICE_SERVERS = [
    { urls: 'stun:stun.l.google.com:19302' },
    { urls: 'stun:stun1.l.google.com:19302' }
    // Add TURN server here if available:
    // { urls: 'turn:turn.example.com:3478', username: 'user', credential: 'pass' }
  ];

  // UI elements
  const startBtn = document.getElementById('startBtn');
  const leaveBtn = document.getElementById('leaveBtn');
  const localVideo = document.getElementById('localVideo');
  const remoteVideos = document.getElementById('remoteVideos');
  const callControls = document.getElementById('callControls');
  const muteBtn = document.getElementById('muteBtn');
  const camBtn = document.getElementById('camBtn');
  const hangupBtn = document.getElementById('hangupBtn');
  const statusLabel = document.getElementById('status');
  const selfTile = document.getElementById('selfTile');

  let socket = null;
  let localStream = null;
  const peers = {}; // peerId -> SimplePeer instance

  function logStatus(msg) {
    if (statusLabel) statusLabel.textContent = msg;
    console.log('[call]', msg);
  }

  function ensureHttps() {
    if (location.protocol !== 'https:' && location.hostname !== 'localhost') {
      alert('Calls require HTTPS. Please open this page over https.');
      return false;
    }
    return true;
  }

  function createSocket() {
    if (!window.io) {
      logStatus('Socket.IO not loaded.');
      return;
    }
    socket = io(signalingURL, { transports: ['websocket'], reconnection: true });

    socket.on('connect', () => {
      logStatus('Connected to signaling server.');
      socket.emit('join-room', roomId);
    });

    socket.on('connect_error', (err) => {
      console.error('Signaling connect error', err);
      logStatus('Signaling connect error');
    });

    socket.on('user-joined', (id) => {
      logStatus('Peer joined: ' + id);
      if (!peers[id]) createPeer(id, true);
    });

    socket.on('signal', ({ signal, sender }) => {
      if (!peers[sender]) peers[sender] = createPeer(sender, false);
      try { peers[sender].signal(signal); } catch (e) { console.warn('signal apply error', e); }
    });

    socket.on('user-left', (id) => {
      logStatus('Peer left: ' + id);
      destroyPeer(id);
    });
  }

  function createPeer(id, initiator) {
    const peer = new SimplePeer({
      initiator,
      trickle: true,
      stream: localStream,
      config: { iceServers: ICE_SERVERS }
    });

    peers[id] = peer;

    peer.on('signal', (signal) => {
      try {
        socket?.emit('signal', { roomId, signal, target: id });
      } catch (e) { console.warn('emit signal error', e); }
    });

    peer.on('stream', (remoteStream) => {
      addRemoteVideo(id, remoteStream);
    });

    peer.on('connect', () => {
      console.log('Peer connected', id);
    });

    peer.on('close', () => {
      destroyPeer(id);
    });

    peer.on('error', (err) => {
      console.warn('Peer error', id, err);
    });

    return peer;
  }

  function destroyPeer(id) {
    const p = peers[id];
    if (p) {
      try { p.destroy(); } catch (e) {}
      delete peers[id];
    }
    const el = document.getElementById('wrap-' + id);
    if (el) el.remove();
    layoutGrid();
  }

  function addRemoteVideo(id, stream) {
    let wrap = document.getElementById('wrap-' + id);
    if (!wrap) {
      wrap = document.createElement('div');
      wrap.id = 'wrap-' + id;
      wrap.className = 'video-wrap';

      const video = document.createElement('video');
      video.id = 'video-' + id;
      video.autoplay = true;
      video.playsInline = true;
      video.style.width = '100%';
      video.style.height = '100%';
      video.style.objectFit = 'cover';
      wrap.appendChild(video);

      const meta = document.createElement('div');
      meta.className = 'video-meta';
      meta.textContent = 'Participant';
      wrap.appendChild(meta);

      // click to zoom
      wrap.addEventListener('click', function (e) {
        const isZoomed = wrap.classList.toggle('zoomed');
        // unzoom others
        document.querySelectorAll('.video-wrap.zoomed').forEach(el => {
          if (el !== wrap) el.classList.remove('zoomed');
        });
      });

      // remove placeholder if present
      const placeholder = remoteVideos.querySelector('.video-empty');
      if (placeholder) placeholder.remove();

      remoteVideos.appendChild(wrap);
      layoutGrid();
    }

    const videoEl = wrap.querySelector('video');
    try {
      videoEl.srcObject = stream;
    } catch (e) {
      try { videoEl.src = URL.createObjectURL(stream); } catch (err) {}
    }
  }

  function layoutGrid() {
    // choose columns based on count and width
    const count = remoteVideos.querySelectorAll('.video-wrap').length;
    const w = window.innerWidth;
    let cols = 3;
    if (w <= 520) cols = Math.min(2, Math.max(1, Math.ceil(Math.sqrt(count))));
    else if (w <= 900) cols = Math.min(3, Math.max(2, Math.ceil(Math.sqrt(count))));
    else cols = Math.min(4, Math.max(2, Math.ceil(Math.sqrt(count))));
    remoteVideos.style.gridTemplateColumns = `repeat(${cols}, 1fr)`;
  }

  async function startCall() {
    if (!ensureHttps()) return;
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
      alert('Your browser does not support camera/mic. Try Chrome or Safari.');
      return;
    }

    try {
      logStatus('Requesting camera and mic...');
      localStream = await navigator.mediaDevices.getUserMedia({ audio: true, video: { width: 640, height: 360 } });
      if (localVideo) {
        localVideo.srcObject = localStream;
        localVideo.muted = true;
        localVideo.play().catch(()=>{});
      }
      // show self tile
      if (selfTile) selfTile.style.display = 'block';
      showControls(true);
      createSocket();
      startBtn.disabled = true;
      leaveBtn.disabled = false;
      logStatus('Camera/mic enabled. Connecting...');
    } catch (err) {
      console.error('getUserMedia error', err);
      alert('Camera/mic error: ' + (err && err.message ? err.message : err));
      logStatus('Camera/mic error');
    }
  }

  function leaveCall() {
    if (!confirm('Leave the call?')) return;
    Object.keys(peers).forEach(id => destroyPeer(id));
    if (localStream) {
      localStream.getTracks().forEach(t => t.stop());
      localStream = null;
      if (localVideo) localVideo.srcObject = null;
    }
    if (socket) {
      try { socket.disconnect(); } catch (e) {}
      socket = null;
    }
    // hide self tile
    if (selfTile) selfTile.style.display = 'none';
    showControls(false);
    startBtn.disabled = false;
    leaveBtn.disabled = true;
    logStatus('Left the call.');
  }

  function showControls(show) {
    if (!callControls) return;
    callControls.style.display = show ? 'flex' : 'none';
    callControls.setAttribute('aria-hidden', show ? 'false' : 'true');
  }

  function toggleMute() {
    if (!localStream) return;
    const tracks = localStream.getAudioTracks();
    if (!tracks || tracks.length === 0) return;
    const enabled = !tracks[0].enabled;
    tracks.forEach(t => t.enabled = enabled);
    muteBtn.textContent = enabled ? '🔇' : '🔈';
  }

  function toggleCam() {
    if (!localStream) return;
    const tracks = localStream.getVideoTracks();
    if (!tracks || tracks.length === 0) return;
    const enabled = !tracks[0].enabled;
    tracks.forEach(t => t.enabled = enabled);
    camBtn.textContent = enabled ? '📷' : '🚫';
  }

  // Self tile click toggles zoom of local video
  if (selfTile) {
    selfTile.addEventListener('click', function () {
      // create a temporary wrap for local video to zoom
      let wrap = document.getElementById('wrap-self');
      if (!wrap) {
        wrap = document.createElement('div');
        wrap.id = 'wrap-self';
        wrap.className = 'video-wrap zoomed';
        const v = document.createElement('video');
        v.autoplay = true;
        v.playsInline = true;
        v.muted = true;
        v.srcObject = localVideo?.srcObject || null;
        v.style.width = '100%';
        v.style.height = '100%';
        v.style.objectFit = 'contain';
        wrap.appendChild(v);
        document.body.appendChild(wrap);
        wrap.addEventListener('click', () => wrap.remove());
      } else {
        wrap.remove();
      }
    });
  }

  // click-to-zoom for remote tiles handled when created (see addRemoteVideo)

  // Hook UI
  startBtn?.addEventListener('click', startCall);
  leaveBtn?.addEventListener('click', leaveCall);
  muteBtn?.addEventListener('click', toggleMute);
  camBtn?.addEventListener('click', toggleCam);
  hangupBtn?.addEventListener('click', leaveCall);

  // Initialize UI state
  showControls(false);
  if (leaveBtn) leaveBtn.disabled = true;
  logStatus('Ready. Click "Enable camera & mic" to start.');

  // Layout on resize
  window.addEventListener('resize', layoutGrid);
})();
</script>

</body>
</html>
