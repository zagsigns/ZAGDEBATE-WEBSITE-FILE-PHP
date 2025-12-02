<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/app.php';

$uid = $_SESSION['reset_user_id'] ?? null;
if (!$uid) { header('Location: /auth/forgot_password.php'); exit; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $otp = trim($_POST['otp'] ?? '');
  $new = $_POST['new_password'] ?? '';
  $stmt = $pdo->prepare("SELECT * FROM otps WHERE user_id=? AND purpose='password_reset' ORDER BY id DESC LIMIT 1");
  $stmt->execute([$uid]);
  $o = $stmt->fetch();
  if ($o && $o['otp_code'] === $otp && strtotime($o['expires_at']) > time() && strlen($new) >= 6) {
    $pdo->prepare("UPDATE users SET password_hash=? WHERE id=?")
        ->execute([password_hash($new, PASSWORD_BCRYPT), $uid]);
    $pdo->prepare("DELETE FROM otps WHERE user_id=? AND purpose='password_reset'")->execute([$uid]);
    unset($_SESSION['reset_user_id']);
    header('Location: /auth/login.php');
    exit;
  } else {
    $error = 'Invalid/expired OTP or weak password.';
  }
}
?>
<!DOCTYPE html>
<html>
<head>
  <?php $meta_title='Reset Password • ZAG DEBATE'; include __DIR__ . '/../seo/meta.php'; ?>
  <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
<div class="container">
  <h2>Reset password</h2>
  <?php if (!empty($error)): ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
  <form method="post" class="card">
    <label class="label">OTP code</label>
    <input class="input" type="text" name="otp" maxlength="6" required>
    <label class="label">New password</label>
    <input class="input" type="password" name="new_password" required>
    <button class="btn" type="submit">Update</button>
  </form>
</div>
</body>
</html>
