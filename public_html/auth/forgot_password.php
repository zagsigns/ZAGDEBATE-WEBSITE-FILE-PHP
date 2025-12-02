<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/mailer.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $email = strtolower(trim($_POST['email'] ?? ''));
  $stmt = $pdo->prepare("SELECT id FROM users WHERE email=?");
  $stmt->execute([$email]);
  $u = $stmt->fetch();
  if ($u) {
    $otp = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $expires = date('Y-m-d H:i:s', time()+600);
    $pdo->prepare("INSERT INTO otps (user_id, otp_code, purpose, expires_at) VALUES (?, ?, 'password_reset', ?)")
        ->execute([(int)$u['id'], $otp, $expires]);
    send_otp_email($email, $otp, 'password_reset');
    $_SESSION['reset_user_id'] = (int)$u['id'];
    header('Location: /auth/reset_password_otp.php');
    exit;
  } else {
    $error = 'Email not found.';
  }
}
?>
<!DOCTYPE html>
<html>
<head>
  <?php $meta_title='Forgot Password • ZAG DEBATE'; include __DIR__ . '/../seo/meta.php'; ?>
  <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
<?php include __DIR__ . '/../includes/header.php'; ?>
<div class="container">
  <h2>Password reset</h2>
  <?php if (!empty($error)): ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
  <form method="post" class="card">
    <label class="label">Enter your registered email</label>
    <input class="input" type="email" name="email" required>
    <button class="btn" type="submit">Send OTP</button>
  </form>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>

</body>
</html>
