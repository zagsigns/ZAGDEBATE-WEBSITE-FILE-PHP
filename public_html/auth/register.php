<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/mailer.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $name = trim($_POST['name'] ?? '');
  $email = strtolower(trim($_POST['email'] ?? ''));
  $password = $_POST['password'] ?? '';

  if ($name && filter_var($email, FILTER_VALIDATE_EMAIL) && strlen($password) >= 6) {
    $exists = $pdo->prepare("SELECT id FROM users WHERE email=?");
    $exists->execute([$email]);
    if ($exists->fetch()) {
      $error = 'Email already registered.';
    } else {
      $hash = password_hash($password, PASSWORD_BCRYPT);
      $pdo->prepare("INSERT INTO users (name, email, password_hash) VALUES (?, ?, ?)")
          ->execute([$name, $email, $hash]);
      $user_id = (int)$pdo->lastInsertId();
      ensure_wallet($pdo, $user_id);

      // Create OTP
      $otp = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
      $expires = date('Y-m-d H:i:s', time() + 600);
      $pdo->prepare("INSERT INTO otps (user_id, otp_code, purpose, expires_at) VALUES (?, ?, 'email_verify', ?)")
          ->execute([$user_id, $otp, $expires]);
      send_otp_email($email, $otp, 'verification');
      $_SESSION['pending_verify_user_id'] = $user_id;
      header('Location: /auth/verify_otp.php');
      exit;
    }
  } else {
    $error = 'Please enter valid name, email, and password (min 6 chars).';
  }
}
?>
<!DOCTYPE html>
<html>
<head>
  <?php $meta_title='Register • ZAG DEBATE'; include __DIR__ . '/../seo/meta.php'; ?>
  <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
<?php include __DIR__ . '/../includes/header.php'; ?>
<div class="container">
  <h2>Create account</h2>
  <?php if (!empty($error)): ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
  <form method="post" class="card">
    <label class="label">Profile name</label>
    <input class="input" type="text" name="name" required>
    <label class="label">Email</label>
    <input class="input" type="email" name="email" required>
    <label class="label">Password</label>
    <input class="input" type="password" name="password" required>
    <button class="btn" type="submit" style="margin-top:12px">Register</button>
    <p class="label" style="margin-top:12px">Already have an account? <a href="/auth/login.php">Login</a></p>
  </form>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>


</body>
</html>
