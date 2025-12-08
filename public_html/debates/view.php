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

/*
 * Settings must be loaded early because several derived variables
 * (free join limits, credits, etc.) depend on them.
 */
$settings = get_settings($pdo) ?: [];
$access_mode = $settings['debate_access_mode'] ?? 'free';
$credits_required = (int)($settings['credits_to_join'] ?? 0);
$credit_rate = (float)($settings['credit_usd_rate'] ?? 0.10);
$free_join_limit = (int)($settings['free_join_limit'] ?? 0);
$free_join_per_debate = (int)($settings['free_join_per_debate'] ?? 0);
$free_join_time_minutes = (int)($settings['free_join_time_minutes'] ?? 0);

/* Gallery JSON decode */
$gallery = [];
if (!empty($debate['gallery_json'])) {
  $gallery = json_decode($debate['gallery_json'], true) ?: [];
}

/* Current user and roles */
$user = current_user();
$isLoggedIn = $user && !empty($user['id']);
$isAdmin = $isLoggedIn && is_admin($user);
$isCreator = $isLoggedIn && ($debate['creator_id'] == $user['id']);

/* Has the current user already joined this debate? */
$joined = false;
if ($isLoggedIn) {
  $j = $pdo->prepare("SELECT id FROM debate_participants WHERE debate_id=? AND user_id=?");
  $j->execute([$id, (int)$user['id']]);
  $joined = (bool)$j->fetch();
}

/* Derived timing and counts used for free-join logic */
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

/* Evaluate free join allowance once (safe defaults already set above) */
$freeJoinAllowed = (
  $userJoinedCount < $free_join_limit ||
  $debateJoinedCount < $free_join_per_debate ||
  $minutesSinceCreated <= $free_join_time_minutes
);

/* Success / error messages */
$success = '';
$error = '';

/*
 * POST handling
 * Note: we reuse the counts and $freeJoinAllowed computed above.
 * After a successful join we update $joined and optionally counts/messages.
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $isLoggedIn) {
  if ($_POST['action'] === 'join' && !$joined) {
    // Re-evaluate freeJoinAllowed in case settings changed between requests
    $freeJoinAllowed = (
      $userJoinedCount < $free_join_limit ||
      $debateJoinedCount < $free_join_per_debate ||
      $minutesSinceCreated <= $free_join_time_minutes
    );

    if ($isAdmin || $isCreator || $access_mode !== 'credits' || $credits_required <= 0 || $freeJoinAllowed) {
      // Free join path
      $ins = $pdo->prepare("INSERT INTO debate_participants (debate_id, user_id) VALUES (?, ?)");
      $ins->execute([$id, (int)$user['id']]);
      $success = 'Joined debate successfully (free access).';
      $joined = true;

      // Update local counts for immediate UI feedback
      $debateJoinedCount++;
      $userJoinedCount++;
    } else {
      // Credits path
      $wallet = $pdo->prepare("SELECT credits FROM wallets WHERE user_id=?");
      $wallet->execute([(int)$user['id']]);
      $userCredits = (int)$wallet->fetchColumn();

      if ($userCredits >= $credits_required) {
        $pdo->beginTransaction();
        try {
          // Deduct credits safely
          $pdo->prepare("UPDATE wallets SET credits=credits-? WHERE user_id=? AND credits >= ?")
              ->execute([$credits_required, (int)$user['id'], $credits_required]);

          // Add participant
          $pdo->prepare("INSERT INTO debate_participants (debate_id, user_id) VALUES (?, ?)")->execute([$id, (int)$user['id']]);

          // Record spend and transfer creator share
          $usd_value = $credits_required * $credit_rate;
          $pdo->prepare("INSERT INTO debate_spend (debate_id, user_id, credits, usd_value) VALUES (?, ?, ?, ?)")
              ->execute([$id, (int)$user['id'], $credits_required, $usd_value]);

          $creator_share = $usd_value * 0.50;
          $pdo->prepare("UPDATE wallets SET earnings_usd=earnings_usd+? WHERE user_id=?")
              ->execute([$creator_share, (int)$debate['creator_id']]);

          $pdo->commit();
          $success = "Joined debate successfully. Spent $credits_required credits.";
          $joined = true;

          // Update local counts for immediate UI feedback
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
<html>
<head>
  <?php $meta_title = htmlspecialchars($debate['title']) . ' • Debate • ZAG DEBATE'; include __DIR__ . '/../seo/meta.php'; ?>
  <link rel="stylesheet" href="/assets/css/style.css">
  <style>
    /* Video call UI improvements: fixed selfie, responsive grid, controls */
    /* Remote videos grid */
    #remoteVideos {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
      gap: 12px;
      margin-top: 12px;
    }
    #remoteVideos .video-wrap {
      position: relative;
      overflow: hidden;
      border-radius: 12px;
      border: 1px solid var(--border);
      background: #000;
      box-shadow: 0 6px 18px rgba(0,0,0,0.35);
    }
    #remoteVideos video {
      width: 100%;
      height: 100%;
      display: block;
      object-fit: cover;
      cursor: pointer;
      transition: transform .28s ease, box-shadow .28s ease;
    }
    #remoteVideos .video-meta {
      position: absolute;
      left: 8px;
      bottom: 8px;
      background: rgba(0,0,0,0.45);
      color: #fff;
      padding: 6px 8px;
      border-radius: 8px;
      font-size: 0.85rem;
      display:flex;
      gap:8px;
      align-items:center;
    }
    #remoteVideos video.zoomed {
      transform: scale(1.02);
      box-shadow: 0 18px 40px rgba(0,0,0,0.6);
      z-index: 2500;
    }

    /* Local selfie fixed box */
    #localVideo {
      position: fixed;
      bottom: 18px;
      right: 18px;
      width: 120px;
      height: 160px;
      border-radius: 12px;
      border: 2px solid var(--accent);
      object-fit: cover;
      cursor: pointer;
      z-index: 3000;
      box-shadow: 0 10px 30px rgba(0,0,0,0.45);
      transition: all .28s ease;
      background: #000;
    }
    #localVideo.zoomed {
      width: 80%;
      height: 70%;
      bottom: 50%;
      right: 50%;
      transform: translate(50%, 50%);
      z-index: 4000;
      border-radius: 12px;
    }

    /* Floating control bar */
    .call-controls {
      position: fixed;
      left: 50%;
      transform: translateX(-50%);
      bottom: 18px;
      display:flex;
      gap:12px;
      z-index: 3500;
      background: rgba(12,19,32,0.85);
      padding: 8px;
      border-radius: 999px;
      border: 1px solid rgba(255,255,255,0.04);
      box-shadow: 0 10px 30px rgba(0,0,0,0.45);
      align-items:center;
    }
    .control-btn {
      display:inline-flex;
      align-items:center;
      justify-content:center;
      gap:8px;
      padding:10px 12px;
      border-radius: 999px;
      background: transparent;
      color: #fff;
      border: 1px solid rgba(255,255,255,0.06);
      cursor: pointer;
      font-weight:700;
      transition: transform .12s ease, background .12s ease;
    }
    .control-btn:hover { transform: translateY(-3px); }
    .control-btn.danger {
      background: linear-gradient(90deg,#ef4444,#dc2626);
      border: none;
      color: #fff;
    }
    .control-btn.toggled {
      background: rgba(255,255,255,0.06);
    }

    /* Status label */
    #status {
      margin-top: 8px;
      color: var(--muted);
    }

    /* Responsive adjustments */
    @media (max-width: 900px) {
      #localVideo { width: 100px; height: 140px; bottom: 14px; right: 14px; }
      .call-controls { bottom: 14px; padding: 6px; gap:8px; }
      #remoteVideos { gap: 8px; }
    }
    @media (max-width: 520px) {
      #localVideo { width: 92px; height: 120px; bottom: 12px; right: 12px; }
      .call-controls { bottom: 12px; gap:6px; padding:6px; }
      .control-btn { padding:8px 10px; font-size:0.95rem; }
      #remoteVideos { grid-template-columns: 1fr; }
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
// Ensure $validGallery is always defined
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

      <!-- Floating control bar (will be shown once camera enabled) -->
      <div id="callControls" class="call-controls" style="display:none" aria-hidden="true">
        <button id="muteBtn" class="control-btn" title="Mute / Unmute">🔇</button>
        <button id="camBtn" class="control-btn" title="Toggle Camera">📷</button>
        <button id="switchBtn" class="control-btn" title="Switch Camera">🔁</button>
        <button id="hangupBtn" class="control-btn danger" title="Leave call">📴</button>
      </div>

      <!-- Local selfie video (fixed) -->
      <video id="localVideo" autoplay muted playsinline></video>

      <!-- Remote videos grid -->
      <div id="remoteVideos" class="grid" aria-live="polite" style="margin-top:12px"></div>

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

const callControls = document.getElementById('callControls');
const muteBtn = document.getElementById('muteBtn');
const camBtn = document.getElementById('camBtn');
const switchBtn = document.getElementById('switchBtn');
const hangupBtn = document.getElementById('hangupBtn');

let audioEnabled = true;
let videoEnabled = true;
let currentFacingMode = 'user'; // 'user' or 'environment'

function updateStatus(msg) {
  if (statusLabel) statusLabel.textContent = msg;
  console.log('[WebRTC]', msg);
}

function showControls(show = true) {
  if (!callControls) return;
  callControls.style.display = show ? 'flex' : 'none';
  callControls.setAttribute('aria-hidden', show ? 'false' : 'true');
}

// Toggle mute/unmute
function toggleMute() {
  if (!localStream) return;
  const audioTracks = localStream.getAudioTracks();
  audioEnabled = !audioEnabled;
  audioTracks.forEach(t => t.enabled = audioEnabled);
  muteBtn.textContent = audioEnabled ? '🔇' : '🔈';
  muteBtn.classList.toggle('toggled', !audioEnabled);
}

// Toggle camera on/off
function toggleCamera() {
  if (!localStream) return;
  const videoTracks = localStream.getVideoTracks();
  videoEnabled = !videoEnabled;
  videoTracks.forEach(t => t.enabled = videoEnabled);
  camBtn.textContent = videoEnabled ? '📷' : '🚫';
  camBtn.classList.toggle('toggled', !videoEnabled);
}

// Switch camera (front/back) - best effort
async function switchCamera() {
  if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) return;
  currentFacingMode = currentFacingMode === 'user' ? 'environment' : 'user';
  try {
    const newStream = await navigator.mediaDevices.getUserMedia({
      audio: { echoCancellation: true, noiseSuppression: true },
      video: { facingMode: { exact: currentFacingMode }, width: { ideal: 1280 }, height: { ideal: 720 } }
    });
    // Replace tracks in localStream and in peers
    const newVideoTrack = newStream.getVideoTracks()[0];
    const oldVideoTrack = localStream.getVideoTracks()[0];
    if (oldVideoTrack) oldVideoTrack.stop();

    // Replace localStream reference
    localStream.removeTrack(oldVideoTrack);
    localStream.addTrack(newVideoTrack);

    // Update local video element
    localVideo.srcObject = null;
    localVideo.srcObject = localStream;

    // Replace track in each peer connection
    Object.values(peers).forEach(p => {
      try {
        const sender = p._pc && p._pc.getSenders && p._pc.getSenders().find(s => s.track && s.track.kind === 'video');
        if (sender) sender.replaceTrack(newVideoTrack);
      } catch (e) {
        // ignore if not supported
      }
    });

    updateStatus('Camera switched.');
  } catch (err) {
    // Fallback: try without exact constraint
    try {
      const newStream = await navigator.mediaDevices.getUserMedia({
        audio: { echoCancellation: true, noiseSuppression: true },
        video: { facingMode: currentFacingMode, width: { ideal: 1280 }, height: { ideal: 720 } }
      });
      const newVideoTrack = newStream.getVideoTracks()[0];
      const oldVideoTrack = localStream.getVideoTracks()[0];
      if (oldVideoTrack) oldVideoTrack.stop();
      localStream.removeTrack(oldVideoTrack);
      localStream.addTrack(newVideoTrack);
      localVideo.srcObject = null;
      localVideo.srcObject = localStream;
      Object.values(peers).forEach(p => {
        try {
          const sender = p._pc && p._pc.getSenders && p._pc.getSenders().find(s => s.track && s.track.kind === 'video');
          if (sender) sender.replaceTrack(newVideoTrack);
        } catch (e) {}
      });
      updateStatus('Camera switched (fallback).');
    } catch (e) {
      updateStatus('Could not switch camera: ' + e.message);
    }
  }
}

// Toggle zoom for local selfie video
localVideo?.addEventListener('click', () => {
  localVideo.classList.toggle('zoomed');
});

// Toggle zoom for remote videos (event delegation)
remoteVideos?.addEventListener('click', e => {
  const v = e.target;
  if (v && v.tagName === 'VIDEO') {
    v.classList.toggle('zoomed');
    // If zoomed, unzoom other videos
    if (v.classList.contains('zoomed')) {
      document.querySelectorAll('#remoteVideos video').forEach(other => {
        if (other !== v) other.classList.remove('zoomed');
      });
      // also unzoom local
      localVideo.classList.remove('zoomed');
    }
  }
});

// Attach control handlers
muteBtn?.addEventListener('click', toggleMute);
camBtn?.addEventListener('click', toggleCamera);
switchBtn?.addEventListener('click', switchCamera);
hangupBtn?.addEventListener('click', () => {
  // reuse leaveBtn logic
  leaveBtn?.click();
});

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
      video: { facingMode: currentFacingMode, width: { ideal: 1280 }, height: { ideal: 720 } }
    });

    // show controls
    showControls(true);
    // set initial control states
    audioEnabled = true;
    videoEnabled = true;
    muteBtn.textContent = '🔇';
    camBtn.textContent = '📷';

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

function createPeer(id, initiator) {
  const peer = new SimplePeer({
    initiator,
    trickle: false,
    stream: localStream,
    config: { iceServers: [{ urls: 'stun:stun.l.google.com:19302' }] }
  });

  // store underlying RTCPeerConnection for track replacement if available
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

  // expose underlying pc if available (SimplePeer internal)
  try {
    if (peer._pc) peer._pc = peer._pc;
  } catch (e) {}

  peers[id] = peer;
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

function addRemoteVideo(id, stream) {
  // create wrapper
  let wrap = document.getElementById('wrap-' + id);
  if (!wrap) {
    wrap = document.createElement('div');
    wrap.id = 'wrap-' + id;
    wrap.className = 'video-wrap';
    // meta overlay
    const meta = document.createElement('div');
    meta.className = 'video-meta';
    meta.textContent = 'Participant';
    wrap.appendChild(meta);

    const video = document.createElement('video');
    video.id = 'video-' + id;
    video.autoplay = true;
    video.playsInline = true;
    video.setAttribute('data-peer-id', id);
    video.style.width = '100%';
    video.style.height = '100%';
    wrap.appendChild(video);

    remoteVideos.appendChild(wrap);
  }
  const videoEl = wrap.querySelector('video');
  if (videoEl) videoEl.srcObject = stream;
}

function removeRemoteVideo(id) {
  const wrap = document.getElementById('wrap-' + id);
  if (wrap) wrap.remove();
}

// Optional: small visual pulse when someone speaks (basic)
// Not a full VAD implementation; just a simple highlight when audio track exists
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
        wrap.style.boxShadow = avg > 20 ? '0 18px 40px rgba(16,185,129,0.18)' : '0 6px 18px rgba(0,0,0,0.35)';
      }
      requestAnimationFrame(tick);
    }
    tick();
  } catch (e) {
    // ignore
  }
}

// When a new remote stream is added, try to attach indicator
// We call tryAttachAudioIndicator inside addRemoteVideo after setting srcObject
// but since streams arrive asynchronously, we attach a small timeout to attempt
const origAddRemoteVideo = addRemoteVideo;
addRemoteVideo = function(id, stream) {
  origAddRemoteVideo(id, stream);
  setTimeout(() => {
    tryAttachAudioIndicator(stream, 'wrap-' + id);
  }, 500);
};

// Ensure remoteVideos is keyboard accessible: allow Enter to toggle zoom
remoteVideos?.addEventListener('keydown', e => {
  if (e.key === 'Enter' && e.target && e.target.tagName === 'VIDEO') {
    e.target.classList.toggle('zoomed');
  }
});
</script>
</body>
</html>
