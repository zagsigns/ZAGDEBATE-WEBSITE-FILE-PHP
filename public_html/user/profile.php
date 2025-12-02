<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/app.php';
require_login();
$user = current_user();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $name = trim($_POST['name'] ?? '');
  $bio = trim($_POST['bio'] ?? '');
  $photo_path = $user['profile_photo'];

  if (!empty($_FILES['profile_photo']['name'])) {
    $dir = __DIR__ . '/../assets/img/';
    @mkdir($dir, 0775, true);
    $ext = pathinfo($_FILES['profile_photo']['name'], PATHINFO_EXTENSION);
    $safe = 'profile_' . $user['id'] . '_' . time() . '.' . strtolower($ext);
    $target = $dir . $safe;
    if (move_uploaded_file($_FILES['profile_photo']['tmp_name'], $target)) {
      $photo_path = '/assets/img/' . $safe;
    }
  }

  $pdo->prepare("UPDATE users SET name=?, bio=?, profile_photo=? WHERE id=?")
      ->execute([$name ?: $user['name'], $bio, $photo_path, (int)$user['id']]);

  // Refresh session
  $stmt = $pdo->prepare("SELECT * FROM users WHERE id=?");
  $stmt->execute([(int)$user['id']]);
  $_SESSION['user'] = $stmt->fetch();
  $success = 'Profile updated.';
}
?>
<!DOCTYPE html>
<html>
<head>
  <?php $meta_title='Edit Profile • ZAG DEBATE'; include __DIR__ . '/../seo/meta.php'; ?>
  <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
<?php include __DIR__ . '/../includes/header.php'; ?>
<div class="container">
  <h2>Edit profile</h2>
  <?php if (!empty($success)): ?><div class="alert alert-success"><?= htmlspecialchars($success) ?></div><?php endif; ?>
  <form method="post" class="card" enctype="multipart/form-data">
    <label class="label">Name</label>
    <input class="input" type="text" name="name" value="<?= htmlspecialchars($user['name']) ?>" required>
    <label class="label">Bio</label>
    <textarea class="input" name="bio" rows="4"><?= htmlspecialchars($user['bio'] ?? '') ?></textarea>
    <label class="label">Profile photo</label>
    <input class="input" type="file" name="profile_photo" accept="image/*">
    <button class="btn" type="submit">Save</button>
  </form>
  <div class="card" style="margin-top:12px">
    <h3>Change password (OTP required)</h3>
    <p><a class="btn" href="/auth/forgot_password.php">Send password-change OTP</a></p>
  </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>

</body>
</html>
