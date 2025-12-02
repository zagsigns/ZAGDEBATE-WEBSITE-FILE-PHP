<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/app.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $email = strtolower(trim($_POST['email'] ?? ''));
  $password = $_POST['password'] ?? '';

  $stmt = $pdo->prepare("SELECT * FROM users WHERE email=? AND role='admin' LIMIT 1");
  $stmt->execute([$email]);
  $admin = $stmt->fetch();

  if ($admin && password_verify($password, $admin['password_hash'])) {
    $_SESSION['user'] = $admin;
    header('Location: /admin/dashboard.php');
    exit;
  } else {
    $error = 'Invalid admin credentials.';
  }
}
?>
<!DOCTYPE html>
<html>
<head>
  <?php $meta_title='Admin Login • ZAG DEBATE'; include __DIR__ . '/../seo/meta.php'; ?>
  <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
<div class="container">
  <h2>Admin login</h2>
  <?php if (!empty($error)): ?>
    <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>
  <form method="post" class="card">
    <label class="label">Admin email</label>
    <input class="input" type="email" name="email" value="zagdebate@gmail.com" required>
    <label class="label">Password</label>
    <input class="input" type="password" name="password" required>
    <button class="btn" type="submit">Login</button>
  </form>
</div>
</body>
</html>
