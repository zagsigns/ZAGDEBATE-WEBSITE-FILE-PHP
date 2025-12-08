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

/* Load settings */
$settings = get_settings($pdo) ?: [];
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
$user = current_user();
$isLoggedIn = $user && !empty($user['id']);
$isAdmin = $isLoggedIn && is_admin($user);
$isCreator = $isLoggedIn && ($debate['creator_id'] == $user['id']);

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

/* POST handling */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $isLoggedIn) {
  if ($_POST['action'] === 'join' && !$joined) {
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
<html lang="en">
<head>
  <?php $meta_title = htmlspecialchars($debate['title']) . ' • Debate • ZAG DEBATE'; include __DIR__ . '/../seo/meta.php'; ?>
  <link rel="stylesheet" href="/assets/css/style.css">
  <style>
    /* ===== Group call: compact professional grid ===== */

    :root {
      --video-gap: 10px;
      --video-min: 110px;
      --video-min-desktop: 160px;
      --video-radius: 10px;
      --video-border: rgba(255,255,255,0.06);
      --accent: #ff3b30;
    }

    /* Container */
    #remoteVideos {
      display: grid;
      gap: var(--video-gap);
      margin-top: 12px;
      align-items: stretch;
      justify-items: stretch;
    }

    /* Responsive columns: adapt to screen and participant count via JS */
    /* default fallback */
    #remoteVideos.grid-cols-1 { grid-template-columns: repeat(1, 1fr); }
    #remoteVideos.grid-cols-2 { grid-template-columns: repeat(2, 1fr); }
    #remoteVideos.grid-cols-3 { grid-template-columns: repeat(3, 1fr); }
    #remoteVideos.grid-cols-4 { grid-template-columns: repeat(4, 1fr); }
    #remoteVideos.grid-cols-5 { grid-template-columns: repeat(5, 1fr); }

    /* Video tile */
    .video-wrap {
      position: relative;
      overflow: hidden;
      border-radius: var(--video-radius);
      border: 1px solid var(--video-border);
      background: #000;
      min-height: 90px;
      height: 100%;
      display:flex;
      align-items:center;
      justify-content:center;
      transition: transform .22s ease, box-shadow .22s ease;
      cursor: pointer;
    }

    .video-wrap video {
      width: 100%;
      height: 100%;
      object-fit: cover;
      display:block;
    }

    /* Small overlay label */
    .video-meta {
      position: absolute;
      left: 8px;
      bottom: 8px;
      background: rgba(0,0,0,0.45);
      color: #fff;
      padding: 4px 8px;
      border-radius: 6px;
      font-size: 0.78rem;
      display:flex;
      gap:8px;
      align-items:center;
      pointer-events: none;
    }

    /* Active speaker highlight */
    .video-wrap.speaking {
      box-shadow: 0 8px 30px rgba(16,185,129,0.12);
      border-color: rgba(16,185,129,0.35);
      transform: translateY(-4px);
    }

    /* Zoomed tile (clicked) */
    .video-wrap.zoomed {
      position: fixed;
      top: 50%;
      left: 50%;
      width: 92vw;
      height: 72vh;
      transform: translate(-50%, -50%);
      z-index: 4000;
      border-radius: 12px;
      box-shadow: 0 30px 80px rgba(0,0,0,0.6);
    }
    .video-wrap.zoomed video { object-fit: contain; }

    /* Local selfie (small fixed) */
    #localVideo {
      position: fixed;
      bottom: 16px;
      right: 16px;
      width: 96px;
      height: 128px;
      border-radius: 10px;
      border: 2px solid var(--accent);
      object-fit: cover;
      z-index: 4500;
      cursor: pointer;
      box-shadow: 0 12px 30px rgba(0,0,0,0.45);
      transition: transform .22s ease, width .22s ease, height .22s ease;
      background: #000;
    }
    #localVideo.zoomed {
      width: 90vw;
      height: 72vh;
      top: 50%;
      left: 50%;
      transform: translate(-50%, -50%);
      z-index: 5000;
    }

    /* Controls bar */
    .call-controls {
      position: fixed;
      left: 50%;
      transform: translateX(-50%);
      bottom: 18px;
      display:flex;
      gap:10px;
      z-index: 4600;
      background: rgba(12,19,32,0.88);
      padding: 8px;
      border-radius: 999px;
      border: 1px solid rgba(255,255,255,0.04);
      box-shadow: 0 10px 30px rgba(0,0,0,0.45);
      align-items:center;
    }
    .control-btn { padding:10px 12px; border-radius:999px; background:transparent; color:#fff; border:1px solid rgba(255,255,255,0.06); cursor:pointer; font-weight:700; }
    .control-btn.danger { background: linear-gradient(90deg,#ef4444,#dc2626); border:none; }

    /* Placeholder */
    .video-empty { color: var(--muted); font-size:0.95rem; padding:18px; text-align:center; }

    /* Responsive adjustments */
    @media (max-width: 1100px) {
      :root { --video-min-desktop: 140px; }
      #localVideo { width: 88px; height: 120px; }
    }
    @media (max-width: 800px) {
      /* on small screens use 2-3 columns depending on count (JS will set classes) */
      #localVideo { width: 84px; height: 112px; bottom: 12px; right: 12px; }
      .call-controls { bottom: 12px; gap:8px; padding:6px; }
    }
    @media (max-width: 520px) {
      /* mobile: small thumbnails stacked in grid */
      #localVideo { width: 76px; height: 100px; bottom: 10px; right: 10px; }
      .video-wrap { min-height: 96px; }
      .call-controls { bottom: 10px; gap:6px; padding:6px; }
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
    echo '<img src="' . htmlspecialchars($debate['thumb_image']) . '" alt="Thumb" style="width:100%;max-height:320px;object-fit:cover;border-radius:8px;border:1px solid var(--border)">';
  }
}
?>

    <p style="margin-top:10px"><?= nl2br(htmlspecialchars($debate['description'])) ?></p>

<?php
$validGallery = [];
foreach ($gallery as $g) {
  $galleryPath = __DIR__ . '/../' . ltrim($g, '/');
  if (!empty($g) && file_exists($galleryPath)) {
    $validGallery[] = $g;
  }
}
if (!empty($validGallery)): ?>
  <div class="grid" style="margin-top:12px">
    <?php foreach ($validGallery as $g): ?>
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
            <?= ($isAdmin || $isCreator || $credits_required <= 0 || $freeJoinAllowed)
                ? 'Join (Free)'
                : "Join ({$credits_required} credits)"; ?>
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

      <!-- Floating control bar (shown after enabling camera) -->
      <div id="callControls" class="call-controls" style="display:none" aria-hidden="true">
        <button id="muteBtn" class="control-btn" title="Mute / Unmute">🔇</button>
        <button id="camBtn" class="control-btn" title="Toggle Camera">📷</button>
        <button id="switchBtn" class="control-btn" title="Switch Camera">🔁</button>
        <button id="hangupBtn" class="control-btn danger" title="Leave call">📴</button>
      </div>

      <!-- Local selfie video (fixed) -->
      <video id="localVideo" autoplay muted playsinline></video>

      <!-- Remote videos grid -->
      <div id="remoteVideos" class="grid grid-cols-3" aria-live="polite" style="margin-top:12px">
        <div class="video-empty">No participants yet. Enable camera & mic to join the call.</div>
      </div>

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
/* ===== Compact group call behavior =====
   - Responsive grid columns are set by JS based on participant count and screen width
   - Thumbnails remain small; click to zoom
   - Active speaker visual pulse (best-effort)
   - Selfie remains fixed and clickable
*/

/* ICE servers (add TURN credentials if available) */
const ICE_SERVERS = [
  { urls: 'stun:stun.l.google.com:19302' },
  { urls: 'stun:stun1.l.google.com:19302' }
];

const signalingURL = 'https://zagdebate-signaling.onrender.com';
const roomId = 'debate-' + <?= (int)$debate['id'] ?>;

let socket = null;
let localStream = null;
const peers = {}; // id -> peer
const peerMeta = {}; // id -> {joinedAt, label}

const startBtn = document.getElementById('startBtn');
const leaveBtn = document.getElementById('leaveBtn');
const localVideo = document.getElementById('localVideo');
const remoteVideos = document.getElementById('remoteVideos');
const statusLabel = document.getElementById('status');

const callControls = document.getElementById('callControls');
const muteBtn = document.getElementById('muteBtn');
const camBtn = document.getElementById('camBtn');
const switchBtn = document.getElementById('switchBtn');
const hangupBtn = document.getElementById('hangupBtn');

let audioEnabled = true;
let videoEnabled = true;
let currentFacingMode = 'user';

/* Utility: update status */
function updateStatus(msg) {
  if (statusLabel) statusLabel.textContent = msg;
  console.log('[WebRTC]', msg);
}

/* Show/hide controls */
function showControls(show = true) {
  if (!callControls) return;
  callControls.style.display = show ? 'flex' : 'none';
  callControls.setAttribute('aria-hidden', show ? 'false' : 'true');
}

/* Grid layout helper: choose columns based on count and width */
function layoutGrid() {
  const count = remoteVideos.querySelectorAll('.video-wrap').length;
  const w = window.innerWidth;
  let cols = 3;

  if (w <= 520) {
    // mobile: 1 or 2 columns
    cols = Math.min(2, Math.max(1, Math.ceil(Math.sqrt(count))));
  } else if (w <= 900) {
    // small tablet: 2-3 columns
    cols = Math.min(3, Math.max(2, Math.ceil(Math.sqrt(count))));
  } else {
    // desktop: up to 4 columns, but keep thumbnails compact
    cols = Math.min(4, Math.max(2, Math.ceil(Math.sqrt(count))));
  }

  // set class
  remoteVideos.classList.remove('grid-cols-1','grid-cols-2','grid-cols-3','grid-cols-4','grid-cols-5');
  remoteVideos.classList.add('grid-cols-' + cols);
}

/* Create or update a remote video tile */
function addRemoteVideo(id, stream, label = 'Participant') {
  let wrap = document.getElementById('wrap-' + id);
  if (!wrap) {
    wrap = document.createElement('div');
    wrap.id = 'wrap-' + id;
    wrap.className = 'video-wrap';
    wrap.setAttribute('data-peer-id', id);

    const video = document.createElement('video');
    video.id = 'video-' + id;
    video.autoplay = true;
    video.playsInline = true;
    video.muted = false;
    video.style.width = '100%';
    video.style.height = '100%';
    wrap.appendChild(video);

    const meta = document.createElement('div');
    meta.className = 'video-meta';
    meta.textContent = label;
    wrap.appendChild(meta);

    // click handler: toggle zoom
    wrap.addEventListener('click', (e) => {
      // if already zoomed, unzoom; else zoom this and unzoom others
      const isZoomed = wrap.classList.toggle('zoomed');
      if (isZoomed) {
        document.querySelectorAll('#remoteVideos .video-wrap').forEach(other => {
          if (other !== wrap) other.classList.remove('zoomed');
        });
        localVideo.classList.remove('zoomed');
      }
    });

    // append and remove placeholder if present
    const placeholder = remoteVideos.querySelector('.video-empty');
    if (placeholder) placeholder.remove();
    remoteVideos.appendChild(wrap);
    layoutGrid();
  }

  const videoEl = wrap.querySelector('video');
  if (videoEl) {
    try {
      videoEl.srcObject = stream;
    } catch (e) {
      // fallback: create object URL (older browsers)
      try { videoEl.src = URL.createObjectURL(stream); } catch (err) {}
    }
  }

  // try attach audio indicator
  tryAttachAudioIndicator(stream, wrap.id);
}

/* Remove tile */
function removeRemoteVideo(id) {
  const wrap = document.getElementById('wrap-' + id);
  if (wrap) wrap.remove();
  layoutGrid();
  if (!remoteVideos.querySelector('.video-wrap')) {
    const placeholder = document.createElement('div');
    placeholder.className = 'video-empty';
    placeholder.textContent = 'No participants yet. Enable camera & mic to join the call.';
    remoteVideos.appendChild(placeholder);
  }
}

/* Small VAD: highlight speaking participant (best-effort) */
function tryAttachAudioIndicator(stream, wrapId) {
  try {
    const audioTracks = stream.getAudioTracks();
    if (!audioTracks || audioTracks.length === 0) return;
    const audioCtx = new (window.AudioContext || window.webkitAudioContext)();
    const src = audioCtx.createMediaStreamSource(new MediaStream([audioTracks[0]]));
    const analyser = audioCtx.createAnalyser();
    analyser.fftSize = 256;
    src.connect(analyser);
    const data = new Uint8Array(analyser.frequencyBinCount);
    const wrap = document.getElementById(wrapId);
    function tick() {
      analyser.getByteFrequencyData(data);
      let sum = 0;
      for (let i = 0; i < data.length; i++) sum += data[i];
      const avg = sum / data.length;
      if (wrap) {
        if (avg > 20) wrap.classList.add('speaking'); else wrap.classList.remove('speaking');
      }
      requestAnimationFrame(tick);
    }
    tick();
  } catch (e) {
    // ignore if not supported
  }
}

/* Local selfie click toggles zoom */
localVideo?.addEventListener('click', () => {
  localVideo.classList.toggle('zoomed');
  // unzoom remote tiles when local zoomed
  if (localVideo.classList.contains('zoomed')) {
    document.querySelectorAll('#remoteVideos .video-wrap').forEach(w => w.classList.remove('zoomed'));
  }
});

/* Keyboard accessibility: Enter toggles zoom on focused tile */
remoteVideos?.addEventListener('keydown', e => {
  if (e.key === 'Enter' && e.target && e.target.closest('.video-wrap')) {
    e.target.closest('.video-wrap').classList.toggle('zoomed');
  }
});

/* Control handlers */
muteBtn?.addEventListener('click', () => {
  if (!localStream) return;
  audioEnabled = !audioEnabled;
  localStream.getAudioTracks().forEach(t => t.enabled = audioEnabled);
  muteBtn.textContent = audioEnabled ? '🔇' : '🔈';
  muteBtn.classList.toggle('toggled', !audioEnabled);
});
camBtn?.addEventListener('click', () => {
  if (!localStream) return;
  videoEnabled = !videoEnabled;
  localStream.getVideoTracks().forEach(t => t.enabled = videoEnabled);
  camBtn.textContent = videoEnabled ? '📷' : '🚫';
  camBtn.classList.toggle('toggled', !videoEnabled);
});
switchBtn?.addEventListener('click', async () => {
  if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) return;
  currentFacingMode = currentFacingMode === 'user' ? 'environment' : 'user';
  try {
    const newStream = await navigator.mediaDevices.getUserMedia({
      audio: { echoCancellation: true, noiseSuppression: true },
      video: { facingMode: { exact: currentFacingMode }, width: { ideal: 640 }, height: { ideal: 360 } }
    });
    const newVideoTrack = newStream.getVideoTracks()[0];
    const oldVideoTrack = localStream.getVideoTracks()[0];
    if (oldVideoTrack) oldVideoTrack.stop();
    try { localStream.removeTrack(oldVideoTrack); } catch(e) {}
    localStream.addTrack(newVideoTrack);
    localVideo.srcObject = null;
    localVideo.srcObject = localStream;
    Object.values(peers).forEach(p => {
      try {
        const senders = p._pc && p._pc.getSenders && p._pc.getSenders();
        if (senders) {
          const sender = senders.find(s => s.track && s.track.kind === 'video');
          if (sender) sender.replaceTrack(newVideoTrack);
        }
      } catch (e) {}
    });
    updateStatus('Camera switched.');
  } catch (err) {
    updateStatus('Could not switch camera: ' + err.message);
  }
});
hangupBtn?.addEventListener('click', () => leaveBtn?.click());

/* Socket + peer logic (robust) */
function createSocket() {
  socket = io(signalingURL, {
    transports: ['websocket'],
    reconnection: true,
    reconnectionAttempts: 10,
    reconnectionDelay: 500,
    reconnectionDelayMax: 3000,
    timeout: 10000,
    pingInterval: 20000,
    pingTimeout: 5000
  });

  socket.on('connect', () => {
    updateStatus('Connected to signaling server. Joining room...');
    socket.emit('join-room', roomId);
  });

  socket.on('connect_error', err => {
    updateStatus('Signaling connect error: ' + (err && err.message ? err.message : err));
  });

  socket.on('user-joined', id => {
    updateStatus('Peer joined: ' + id);
    if (!peers[id]) peers[id] = createPeer(id, true);
  });

  socket.on('signal', ({ signal, sender }) => {
    if (!peers[sender]) peers[sender] = createPeer(sender, false);
    try { peers[sender].signal(signal); } catch(e) { console.warn('signal apply error', e); }
  });

  socket.on('user-left', id => {
    updateStatus('Peer left: ' + id);
    destroyPeer(id);
  });
}

/* Create peer with trickle ICE and timeouts */
function createPeer(id, initiator) {
  const peer = new SimplePeer({
    initiator,
    trickle: true,
    stream: localStream,
    config: { iceServers: ICE_SERVERS }
  });

  peers[id] = peer;

  peer.on('signal', signal => {
    try {
      socket?.emit('signal', { roomId, signal, target: id }, (ack) => {
        if (!ack || ack.status !== 'ok') {
          setTimeout(() => {
            try { socket?.emit('signal', { roomId, signal, target: id }); } catch(e) {}
          }, 800 + Math.random() * 400);
        }
      });
    } catch (e) {
      console.warn('emit signal error', e);
    }
  });

  let connectTimer = setTimeout(() => {
    if (!peer.connected) {
      console.warn('Peer connection timeout for', id);
      try { peer.destroy(); } catch(e) {}
      delete peers[id];
      setTimeout(() => { if (!peers[id]) peers[id] = createPeer(id, true); }, 700 + Math.random()*800);
    }
  }, 12000);

  peer.on('connect', () => {
    clearTimeout(connectTimer);
    updateStatus('Peer connected: ' + id);
  });

  peer.on('stream', remoteStream => {
    clearTimeout(connectTimer);
    // label can be improved to show user name if available
    addRemoteVideo(id, remoteStream, 'Participant');
    updateStatus('Received remote stream from ' + id);
  });

  peer.on('close', () => {
    clearTimeout(connectTimer);
    destroyPeer(id);
  });

  peer.on('error', err => {
    clearTimeout(connectTimer);
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
  removeRemoteVideo(id);
}

/* Start / Leave handlers */
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
      video: { facingMode: currentFacingMode, width: { ideal: 640 }, height: { ideal: 360 } }
    });

    localVideo.srcObject = localStream;
    showControls(true);
    audioEnabled = true;
    videoEnabled = true;
    if (muteBtn) muteBtn.textContent = '🔇';
    if (camBtn) camBtn.textContent = '📷';

    updateStatus('Camera/mic enabled. Connecting to signaling server...');
    createSocket();
  } catch (err) {
    console.error('getUserMedia error:', err);
    updateStatus('Error: ' + err.message);
    alert('Camera/mic error: ' + err.message);
  }
});

leaveBtn?.addEventListener('click', () => {
  const confirmLeave = confirm('Are you sure you want to leave the call?');
  if (!confirmLeave) return;

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
  showControls(false);
  updateStatus('Left the call.');
});

/* Window resize: relayout grid */
window.addEventListener('resize', () => {
  layoutGrid();
});

/* Initial layout call */
document.addEventListener('DOMContentLoaded', () => {
  layoutGrid();
});
</script>
</body>
</html>
