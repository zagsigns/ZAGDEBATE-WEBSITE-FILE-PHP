<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/app.php';
require_login();
$user = current_user();

// Load platform settings
$settings = $pdo->query("SELECT debate_access_mode, credits_to_create FROM settings LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$access_mode = $settings['debate_access_mode'] ?? 'free';
$credits_required = (int)($settings['credits_to_create'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $title = trim($_POST['title'] ?? '');
  $description = trim($_POST['description'] ?? '');
  $thumb = null;
  $gallery = [];

  if ($title && $description) {
    // If access mode is 'credits', check user balance (skip for admins)
    if (!is_admin($user) && $access_mode === 'credits' && $credits_required > 0) {
      $stmt = $pdo->prepare("SELECT credits FROM wallets WHERE user_id=?");
      $stmt->execute([$user['id']]);
      $userCredits = (int)$stmt->fetchColumn();

      if ($userCredits < $credits_required) {
        $_SESSION['flash_error'] = "You need $credits_required credits to create a debate.";
        header('Location: /user/buy_credits.php');
        exit;
      }
    }

    // Thumb upload
    if (!empty($_FILES['thumb']['name'])) {
      $dir = __DIR__ . '/../assets/img/';
      @mkdir($dir, 0775, true);
      $ext = strtolower(pathinfo($_FILES['thumb']['name'], PATHINFO_EXTENSION));
      $safe = 'deb_thumb_' . $user['id'] . '_' . time() . '.' . $ext;
      if (move_uploaded_file($_FILES['thumb']['tmp_name'], $dir . $safe)) {
        $thumb = '/assets/img/' . $safe;
      }
    }

    // Gallery upload
    if (!empty($_FILES['gallery']['name'][0])) {
      foreach ($_FILES['gallery']['name'] as $i => $name) {
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $safe = 'deb_gal_' . $user['id'] . '_' . time() . '_' . $i . '.' . $ext;
        if (move_uploaded_file($_FILES['gallery']['tmp_name'][$i], __DIR__ . '/../assets/img/' . $safe)) {
          $gallery[] = '/assets/img/' . $safe;
        }
      }
    }

    // Create debate and deduct credits atomically
    try {
      $pdo->beginTransaction();

      $stmt = $pdo->prepare("INSERT INTO debates (creator_id, title, description, thumb_image, gallery_json, created_at) 
                             VALUES (?, ?, ?, ?, ?, NOW())");
      $stmt->execute([(int)$user['id'], $title, $description, $thumb, json_encode($gallery)]);
      $debateId = (int)$pdo->lastInsertId();

      if (!is_admin($user) && $access_mode === 'credits' && $credits_required > 0) {
        $deduct = $pdo->prepare("UPDATE wallets SET credits = credits - ? WHERE user_id = ? AND credits >= ?");
        $deduct->execute([$credits_required, $user['id'], $credits_required]);

        if ($deduct->rowCount() === 0) {
          $pdo->rollBack();
          $_SESSION['flash_error'] = "Failed to deduct credits. Please try again.";
          header('Location: /user/buy_credits.php');
          exit;
        }
      }

      $pdo->commit();
      header('Location: /debates/view.php?id=' . $debateId);
      exit;
    } catch (Exception $e) {
      $pdo->rollBack();
      $error = 'Something went wrong. Please try again.';
    }
  } else {
    $error = 'Title and description are required.';
  }
}
?>
<!DOCTYPE html>
<html>
<head>
  <?php $meta_title='Create Debate • ZAG DEBATE'; include __DIR__ . '/../seo/meta.php'; ?>
  <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
<?php include __DIR__ . '/../includes/header.php'; ?>
<div class="container">
  <h2>Create debate</h2>
  <?php if (!empty($_SESSION['flash_error'])): ?>
    <div class="alert alert-error"><?= htmlspecialchars($_SESSION['flash_error']) ?></div>
    <?php unset($_SESSION['flash_error']); ?>
  <?php endif; ?>
  <?php if (!empty($error)): ?>
    <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>
  <form method="post" class="card" enctype="multipart/form-data">
    <label class="label">Title</label>
    <input class="input" type="text" name="title" required>
    <label class="label">Description</label>
    <textarea class="input" name="description" rows="6" required></textarea>
    <div class="form-row">
      <div>
        <label class="label">Thumb image</label>
        <input class="input" type="file" name="thumb" accept="image/*">
      </div>
      <div>
        <label class="label">Image gallery (multiple)</label>
        <input class="input" type="file" name="gallery[]" accept="image/*" multiple>
      </div>
    </div>
    <?php if (!is_admin($user) && $access_mode === 'credits' && $credits_required > 0): ?>
      <p class="label" style="margin-top:8px">Creating a debate costs <?= $credits_required ?> credits.</p>
    <?php else: ?>
      <p class="label" style="margin-top:8px">Creating a debate is free.</p>
    <?php endif; ?>
    <button class="btn" type="submit">Create</button>
  </form>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>
