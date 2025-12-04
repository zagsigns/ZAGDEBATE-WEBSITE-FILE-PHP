<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/app.php';

session_destroy(); // clear any old session
session_start();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $email = strtolower(trim($_POST['email'] ?? ''));
  $password = $_POST['password'] ?? '';

  // Fetch user by email
  $stmt = $pdo->prepare("SELECT * FROM users WHERE email=? LIMIT 1");
  $stmt->execute([$email]);
  $user = $stmt->fetch();

  if ($user && password_verify($password, $user['password_hash'])) {
    // Save user in session
    $_SESSION['user'] = $user;
    ensure_wallet($pdo, (int)$user['id']);

    // Redirect based on role
    if ($user['role'] === 'admin') {
      header('Location: /admin/dashboard.php');
    } else {
      header('Location: /user/dashboard.php');
    }
    exit;
  } else {
    $error = 'Invalid credentials.';
  }
}
?>
<!DOCTYPE html>
<html>
<head>
  <?php $meta_title='Login • ZAG DEBATE'; include __DIR__ . '/../seo/meta.php'; ?>
  <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
<?php include __DIR__ . '/../includes/header.php'; ?>
<div class="container">
  <h2>Login</h2>
  <?php if (!empty($error)): ?>
    <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>
  <form method="post" class="card">
    <label class="label">Email</label>
    <input class="input" type="email" name="email" required>
    <label class="label">Password</label>
    <input class="input" type="password" name="password" required>
    <button class="btn" type="submit" style="margin-top:12px">Login</button>
    <p class="label" style="margin-top:12px">
      <a href="/auth/forgot_password.php">Forgot password?</a>
    </p>
    <p class="label" style="margin-top:6px">
      <a href="/auth/register.php">Create a new account</a>
    </p>
  </form>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>
