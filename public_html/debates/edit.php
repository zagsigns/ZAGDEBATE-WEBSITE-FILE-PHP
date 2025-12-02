<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/app.php';
require_login();

$user = current_user();
$id = (int)($_GET['id'] ?? 0);

// Fetch debate: allow admin to edit any, creator only their own
if (is_admin($user)) {
    $stmt = $pdo->prepare("SELECT * FROM debates WHERE id=?");
    $stmt->execute([$id]);
} else {
    $stmt = $pdo->prepare("SELECT * FROM debates WHERE id=? AND creator_id=?");
    $stmt->execute([$id, (int)$user['id']]);
}
$debate = $stmt->fetch();
if (!$debate) { echo 'Not found'; exit; }

// Load settings (in case editing requires credits in future)
$settings = $pdo->query("SELECT debate_access_mode, credits_to_create, credits_to_join FROM settings LIMIT 1")->fetch();
$access_mode = $settings['debate_access_mode'] ?? 'free';
$credits_required = 0; // currently free

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $title = trim($_POST['title'] ?? '');
  $description = trim($_POST['description'] ?? '');
  $thumb = $debate['thumb_image'];
  $gallery = json_decode($debate['gallery_json'], true) ?: [];

  // If editing required credits, enforce here (creators only)
  if (!is_admin($user) && $access_mode === 'credits' && $credits_required > 0) {
    $stmt = $pdo->prepare("SELECT credits FROM users WHERE id=?");
    $stmt->execute([$user['id']]);
    $userCredits = (int)$stmt->fetchColumn();

    if ($userCredits < $credits_required) {
      $_SESSION['flash_error'] = "You need $credits_required credits to edit a debate.";
      header('Location: /credits/buy.php');
      exit;
    }
  }

  // Handle thumb upload
  if (!empty($_FILES['thumb']['name'])) {
    $ext = strtolower(pathinfo($_FILES['thumb']['name'], PATHINFO_EXTENSION));
    $safe = 'deb_thumb_' . $user['id'] . '_' . time() . '.' . $ext;
    if (move_uploaded_file($_FILES['thumb']['tmp_name'], __DIR__ . '/../assets/img/' . $safe)) {
      $thumb = '/assets/img/' . $safe;
    }
  }

  // Handle gallery upload
  if (!empty($_FILES['gallery']['name'][0])) {
    foreach ($_FILES['gallery']['name'] as $i => $name) {
      $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
      $safe = 'deb_gal_' . $user['id'] . '_' . time() . '_' . $i . '.' . $ext;
      if (move_uploaded_file($_FILES['gallery']['tmp_name'][$i], __DIR__ . '/../assets/img/' . $safe)) {
        $gallery[] = '/assets/img/' . $safe;
      }
    }
  }

  // Update debate
  $pdo->beginTransaction();
  try {
    if (is_admin($user)) {
      $pdo->prepare("UPDATE debates SET title=?, description=?, thumb_image=?, gallery_json=? WHERE id=?")
          ->execute([$title, $description, $thumb, json_encode($gallery), $id]);
    } else {
      $pdo->prepare("UPDATE debates SET title=?, description=?, thumb_image=?, gallery_json=? WHERE id=? AND creator_id=?")
          ->execute([$title, $description, $thumb, json_encode($gallery), $id, (int)$user['id']]);
    }

    // Deduct credits if required (creators only)
    if (!is_admin($user) && $access_mode === 'credits' && $credits_required > 0) {
      $deduct = $pdo->prepare("UPDATE users SET credits = credits - ? WHERE id=? AND credits >= ?");
      $deduct->execute([$credits_required, $user['id'], $credits_required]);
      if ($deduct->rowCount() === 0) {
        $pdo->rollBack();
        $_SESSION['flash_error'] = "Failed to deduct credits.";
        header('Location: /credits/buy.php');
        exit;
      }
    }

    $pdo->commit();
    header('Location: /debates/view.php?id=' . $id);
    exit;
  } catch (Exception $e) {
    $pdo->rollBack();
    $error = 'Could not save changes.';
  }
}
?>
<!DOCTYPE html>
<html>
<head>
  <?php $meta_title='Edit Debate • ZAG DEBATE'; include __DIR__ . '/../seo/meta.php'; ?>
  <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
<?php include __DIR__ . '/../includes/header.php'; ?>
<div class="container">
  <h2>Edit debate</h2>
  <?php if (!empty($_SESSION['flash_error'])): ?>
    <div class="alert alert-error"><?= htmlspecialchars($_SESSION['flash_error']) ?></div>
    <?php unset($_SESSION['flash_error']); ?>
  <?php endif; ?>
  <?php if (!empty($error)): ?>
    <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>
  <form method="post" class="card" enctype="multipart/form-data">
    <label class="label">Title</label>
    <input class="input" type="text" name="title" value="<?= htmlspecialchars($debate['title']) ?>" required>
    <label class="label">Description</label>
    <textarea class="input" name="description" rows="6" required><?= htmlspecialchars($debate['description']) ?></textarea>
    <label class="label">Thumb image</label>
    <input class="input" type="file" name="thumb" accept="image/*">
    <label class="label">Add to gallery</label>
    <input class="input" type="file" name="gallery[]" accept="image/*" multiple>
    <button class="btn" type="submit">Save</button>
  </form>
</div>
</body>
</html>
