<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/app.php';

$uid = $_SESSION['pending_verify_user_id'] ?? null;
if (!$uid) { header('Location: /auth/login.php'); exit; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $otp = trim($_POST['otp'] ?? '');
  $stmt = $pdo->prepare("SELECT * FROM otps WHERE user_id=? AND purpose='email_verify' ORDER BY id DESC LIMIT 1");
  $stmt->execute([$uid]);
  $o = $stmt->fetch();
  if ($o && $o['otp_code'] === $otp && strtotime($o['expires_at']) > time()) {
    $pdo->prepare("UPDATE users SET is_verified=1 WHERE id=?")->execute([$uid]);
    $pdo->prepare("DELETE FROM otps WHERE user_id=? AND purpose='email_verify'")->execute([$uid]);
    // Auto-login
    $user = $pdo->prepare("SELECT * FROM users WHERE id=?");
    $user->execute([$uid]);
    $_SESSION['user'] = $user->fetch();
    unset($_SESSION['pending_verify_user_id']);
    header('Location: /user/dashboard.php');
    exit;
  } else {
    $error = 'Invalid or expired OTP.';
  }
}
?>
<!DOCTYPE html>
<html>
<head>
  <?php $meta_title='Verify OTP • ZAG DEBATE'; include __DIR__ . '/../seo/meta.php'; ?>
  <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
<div class="container">
  <h2>Verify your email</h2>
  <?php if (!empty($error)): ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
  <form method="post" class="card">
    <label class="label">Enter 6-digit OTP sent to your email</label>
    <input class="input" type="text" name="otp" maxlength="6" required>
    <button class="btn" type="submit">Verify</button>
  </form>
</div>
</body>
</html>
