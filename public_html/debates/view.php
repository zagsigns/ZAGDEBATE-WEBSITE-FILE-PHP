<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/app.php';

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

$gallery = [];
if (!empty($debate['gallery_json'])) {
  $gallery = json_decode($debate['gallery_json'], true) ?: [];
}

$user = current_user();
$isLoggedIn = $user && !empty($user['id']);
$isAdmin = $isLoggedIn && is_admin($user);
$isCreator = $isLoggedIn && ($debate['creator_id'] == $user['id']);

$joined = false;
if ($isLoggedIn) {
  $j = $pdo->prepare("SELECT id FROM debate_participants WHERE debate_id=? AND user_id=?");
  $j->execute([$id, (int)$user['id']]);
  $joined = (bool)$j->fetch();
}

$settings = get_settings($pdo) ?: [];
$access_mode = $settings['debate_access_mode'] ?? 'free';
$credits_required = (int)($settings['credits_to_join'] ?? 0);
$credit_rate = (float)($settings['credit_usd_rate'] ?? 0.10);
$free_join_limit = (int)($settings['free_join_limit'] ?? 0);
$free_join_per_debate = (int)($settings['free_join_per_debate'] ?? 0);
$free_join_time_minutes = (int)($settings['free_join_time_minutes'] ?? 0);

$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $isLoggedIn) {
  if ($_POST['action'] === 'join' && !$joined) {
    $joinedCountStmt = $pdo->prepare("SELECT COUNT(*) FROM debate_participants WHERE user_id=?");
    $joinedCountStmt->execute([(int)$user['id']]);
    $userJoinedCount = (int)$joinedCountStmt->fetchColumn();

    $debateJoinedStmt = $pdo->prepare("SELECT COUNT(*) FROM debate_participants WHERE debate_id=?");
    $debateJoinedStmt->execute([$debate['id']]);
    $debateJoinedCount = (int)$debateJoinedStmt->fetchColumn();

    $createdAt = strtotime($debate['created_at']);
    $minutesSinceCreated = (time() - $createdAt) / 60;

    $freeJoinAllowed = (
      $userJoinedCount < $free_join_limit ||
      $debateJoinedCount < $free_join_per_debate ||
      $minutesSinceCreated <= $free_join_time_minutes
    );

    if ($isAdmin || $isCreator || $access_mode !== 'credits' || $credits_required <= 0 || $freeJoinAllowed) {
      $pdo->prepare("INSERT INTO debate_participants (debate_id, user_id) VALUES (?, ?)")->execute([$id, (int)$user['id']]);
      $success = 'Joined debate successfully (free access).';
      $joined = true;
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
        } catch (Exception $e) {
          $pdo->rollBack();
          $error = 'Could not join debate.';
        }
      } else {
        $error = "Not enough credits. You need $credits_required credits.";
      }
    }
  } elseif ($_POST['action'] === 'send_message') {
    $msg = trim($_POST['message'] ?? '');
    if ($msg && $joined) {
      $pdo->prepare("INSERT INTO chat_messages (debate_id, user_id, message) VALUES (?, ?, ?)")->execute([$id, (int)$user['id'], $msg]);
      $success = 'Message sent.';
    } else {
      $error = 'Join the debate to chat.';
    }
  }
}
?>
<!DOCTYPE html>
<html>
<head>
  <?php $meta_title = htmlspecialchars($debate['title']) . ' • Debate • ZAG DEBATE'; include __DIR__ . '/../seo/meta.php'; ?>
  <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
<?php include __DIR__ . '/../includes/header.php'; ?>
<div class="container">
  <div class="card">
    <h2><?= htmlspecialchars($debate['title']) ?></h2>
    <p class="label">By <?= htmlspecialchars($debate['creator_name']) ?></p>

    <?php if (!empty($debate['thumb_image'])): ?>
      <img src="<?= htmlspecialchars($debate['thumb_image']) ?>" alt="Thumb" style="width:100%;max-height:320px;object-fit:cover;border-radius:8px;border:1px solid var(--border)">
    <?php endif; ?>

    <p style="margin-top:10px"><?= nl2br(htmlspecialchars($debate['description'])) ?></p>

    <?php if (!empty($gallery)): ?>
      <div class="grid" style="margin-top:12px">
        <?php foreach ($gallery as $g): ?>
          <img src="<?= htmlspecialchars($g) ?>" alt="Gallery" style="width:100%;height:140px;object-fit:cover;border-radius:8px;border:1px solid var(--border)">
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <?php if (!empty($success)): ?><div class="alert alert-success"><?= htmlspecialchars($success) ?></div><?php endif; ?>
    <?php if (!empty($error)): ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

    <div style="margin-top:12px">
      <?php if (!$isLoggedIn): ?>
        <a class="btn" href="/auth/login.php">Login to join</a>
      <?php elseif (!$joined): ?>
        <form method="post" style="display:inline">
          <input type="hidden" name="action" value="join">
          <button class="btn" type="submit">
            <?php
            $freeJoinAllowed = (
              $userJoinedCount < $free_join_limit ||
              $debateJoinedCount < $free_join_per_debate ||
              $minutesSinceCreated <= $free_join_time_minutes
            );
            echo ($isAdmin || $isCreator || $credits_required <= 0 || $freeJoinAllowed)
              ? 'Join (Free)' : "Join ({$credits_required} credits)";
            ?>
          </button>
        </form>
        <?php if (!$isAdmin && !$isCreator && $credits_required > 0): ?>
          <a class="btn-outline" href="/user/buy_credits.php" style="margin-left:8px">Buy credits</a>
        <?php endif; ?>
      <?php else: ?>
        <span class="label">You have joined this debate.</span>
      <?php endif; ?>
    </div>

    <?php if ($isLoggedIn && ($isAdmin || $isCreator)): ?>
      <div class="form-row" style="margin-top:16px">
        <a class="btn" href="/debates/edit.php?id=<?= (int)$debate['id'] ?>">Edit Debate</a>
        
                <a class="btn" href="/debates/delete.php?id=<?= (int)$debate['id'] ?>"
           onclick="return confirm('Are you sure you want to delete this debate?');"
           style="margin-left:8px">Delete Debate</a>
      </div>
    <?php endif; ?>
  </div>

  <!-- Group Chat -->
  <div class="card" style="margin-top:16px">
    <h3>Group chat</h3>
    <div id="chatBox"
         data-debate-id="<?= (int)$debate['id'] ?>"
         style="max-height:280px;overflow:auto;border:1px solid var(--border);border-radius:8px;padding:8px;background:#0c1320"></div>

    <?php if ($joined && $isLoggedIn): ?>
      <form method="post" style="margin-top:8px">
        <input type="hidden" name="action" value="send_message">
        <input class="input" type="text" name="message" placeholder="Type message..." required>
        <button class="btn" type="submit" style="margin-top:8px">Send</button>
      </form>
    <?php else: ?>
      <p class="label">Join to participate in chat.</p>
    <?php endif; ?>
  </div>

  <!-- Group Audio/Video Calls -->
  <div class="card" style="margin-top:16px">
    <h3>Group audio/video calls</h3>

    <?php if ($joined && $isLoggedIn): ?>
      <div class="form-row" style="margin-bottom:8px">
        <button class="btn" id="startBtn">Enable camera & mic</button>
        <button class="btn" id="leaveBtn" style="margin-left:8px">Leave call</button>
      </div>

      <video id="localVideo" autoplay muted playsinline
             style="width:100%;border-radius:8px;border:1px solid var(--border);margin-bottom:10px;"></video>

      <div id="remoteVideos" class="grid"></div>
      <div id="status" class="label" style="margin-top:8px">Ready. Click “Enable camera & mic”.</div>
    <?php else: ?>
      <p class="label">Join the debate to enable audio/video calls.</p>
    <?php endif; ?>
  </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>

<!-- Scripts -->
<script src="/assets/js/app.js"></script>
<script src="https://cdn.socket.io/4.7.2/socket.io.min.js"></script>
<script src="https://unpkg.com/simple-peer@9.11.1/simplepeer.min.js"></script>
<script>
const signalingURL = 'https://zagdebate-signaling.onrender.com';
const roomId = 'debate-' + <?= (int)$debate['id'] ?>;

let socket = null;
let localStream = null;
const peers = {};

const startBtn = document.getElementById('startBtn');
const leaveBtn = document.getElementById('leaveBtn');
const localVideo = document.getElementById('localVideo');
const remoteVideos = document.getElementById('remoteVideos');
const statusLabel = document.getElementById('status');

function updateStatus(msg) {
  if (statusLabel) statusLabel.textContent = msg;
  console.log('[WebRTC]', msg);
}

startBtn?.addEventListener('click', async () => {
  try {
    if (location.protocol !== 'https:' && location.hostname !== 'localhost') {
      updateStatus('Calls require HTTPS. Please use https.');
      alert('Calls require HTTPS. Please reload using https.');
      return;
    }

    if (!navigator.mediaDevices?.getUserMedia) {
      updateStatus('Your browser does not support camera/mic.');
      alert('Browser does not support camera/mic. Try Chrome or Safari.');
      return;
    }

    updateStatus('Requesting camera and mic...');
    localStream = await navigator.mediaDevices.getUserMedia({
      audio: { echoCancellation: true, noiseSuppression: true },
      video: { facingMode: 'user', width: { ideal: 1280 }, height: { ideal: 720 } }
    });

    localVideo.srcObject = localStream;
    updateStatus('Camera/mic enabled. Connecting to signaling server...');

    socket = io(signalingURL, { transports: ['websocket'] });

    socket.on('connect', () => {
      updateStatus('Connected. Joining room...');
      socket.emit('join-room', roomId);
    });

    socket.on('connect_error', err => {
      updateStatus('Signaling error: ' + err.message);
    });

    socket.on('user-joined', id => {
      updateStatus('Peer joined: ' + id);
      if (!peers[id]) peers[id] = createPeer(id, true);
    });

    socket.on('signal', ({ signal, sender }) => {
      if (!peers[sender]) peers[sender] = createPeer(sender, false);
      peers[sender].signal(signal);
    });

    socket.on('user-left', id => {
      updateStatus('Peer left: ' + id);
      destroyPeer(id);
    });

  } catch (err) {
    console.error('getUserMedia error:', err);
    updateStatus('Error: ' + err.message);
    alert('Camera/mic error: ' + err.message);
  }
});

leaveBtn?.addEventListener('click', () => {
  Object.keys(peers).forEach(id => destroyPeer(id));
  if (localStream) {
    localStream.getTracks().forEach(t => t.stop());
    localVideo.srcObject = null;
    localStream = null;
  }
  if (socket) {
    socket.disconnect();
    socket = null;
  }
  updateStatus('Left the call.');
});

function createPeer(id, initiator) {
  const peer = new SimplePeer({
    initiator,
    trickle: false,
    stream: localStream,
    config: { iceServers: [{ urls: 'stun:stun.l.google.com:19302' }] }
  });

  peer.on('signal', signal => {
    socket?.emit('signal', { roomId, signal, target: id });
  });

  peer.on('stream', remoteStream => {
    addRemoteVideo(id, remoteStream);
    updateStatus('Connected to peer: ' + id);
  });

  peer.on('close', () => removeRemoteVideo(id));
  peer.on('error', err => {
    console.warn('Peer error', id, err);
    updateStatus('Peer error (' + id + '): ' + err.message);
  });

  return peer;
}

function destroyPeer(id) {
  const p = peers[id];
  if (p) {
    p.destroy();
    delete peers[id];
  }
  removeRemoteVideo(id);
}

function addRemoteVideo(id, stream) {
  let video = document.getElementById('video-' + id);
  if (!video) {
    video = document.createElement('video');
    video.id = 'video-' + id;
    video.autoplay = true;
    video.playsInline = true;
    video.style.width = '100%';
    video.style.borderRadius = '8px';
    video.style.border = '1px solid var(--border)';
    remoteVideos.appendChild(video);
  }
  video.srcObject = stream;
}

function removeRemoteVideo(id) {
  const el = document.getElementById('video-' + id);
  if (el) el.remove();
}
</script>
</body>
</html>
