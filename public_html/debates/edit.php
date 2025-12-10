<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/app.php';
require_login();

$user = current_user();
$id = (int)($_GET['id'] ?? 0);

// Fetch debate: admin can edit any; creator only theirs
if (is_admin($user)) {
    $stmt = $pdo->prepare("SELECT * FROM debates WHERE id=?");
    $stmt->execute([$id]);
} else {
    $stmt = $pdo->prepare("SELECT * FROM debates WHERE id=? AND creator_id=?");
    $stmt->execute([$id, (int)$user['id']]);
}
$debate = $stmt->fetch();
if (!$debate) { echo 'Not found'; exit; }

// Prep values
$title = $debate['title'] ?? '';
$description = $debate['description'] ?? '';
$thumb = $debate['thumb_image'] ?? null;
$gallery = json_decode($debate['gallery_json'] ?? '[]', true) ?: [];

// Settings (placeholder for future credit gating)
$settings = $pdo->query("SELECT debate_access_mode, credits_to_create FROM settings LIMIT 1")->fetch();
$access_mode = $settings['debate_access_mode'] ?? 'free';
$credits_required = 0; // currently free

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = $_POST['action'] ?? 'save';
  $title = trim($_POST['title'] ?? $title);
  $description = trim($_POST['description'] ?? $description);

  // Immediate delete: thumbnail
  if ($action === 'delete_thumb' && !empty($thumb)) {
    $thumbFs = __DIR__ . '/../' . ltrim($thumb, '/');
    if (file_exists($thumbFs)) @unlink($thumbFs);
    $thumb = null;
    $pdo->prepare("UPDATE debates SET thumb_image=NULL WHERE id=?")->execute([$id]);
    header('Location: /debates/edit.php?id=' . $id);
    exit;
  }

  // Immediate delete: one gallery image
  if ($action === 'delete_gallery_one' && !empty($_POST['img'])) {
    $img = $_POST['img'];
    $newGallery = [];
    foreach ($gallery as $g) {
      if ($g === $img) {
        $fs = __DIR__ . '/../' . ltrim($g, '/');
        if (file_exists($fs)) @unlink($fs);
      } else {
        $newGallery[] = $g;
      }
    }
    $gallery = $newGallery;
    $pdo->prepare("UPDATE debates SET gallery_json=? WHERE id=?")->execute([json_encode($gallery), $id]);
    header('Location: /debates/edit.php?id=' . $id);
    exit;
  }

  // Checkbox batch deletions (optional, processed on Save)
  if (!empty($_POST['delete_thumb_batch']) && !empty($thumb)) {
    $thumbFs = __DIR__ . '/../' . ltrim($thumb, '/');
    if (file_exists($thumbFs)) @unlink($thumbFs);
    $thumb = null;
  }
  if (!empty($_POST['delete_gallery_batch']) && is_array($_POST['delete_gallery_batch'])) {
    $toDelete = array_map('strval', $_POST['delete_gallery_batch']);
    $newGallery = [];
    foreach ($gallery as $img) {
      if (in_array($img, $toDelete, true)) {
        $fs = __DIR__ . '/../' . ltrim($img, '/');
        if (file_exists($fs)) @unlink($fs);
      } else {
        $newGallery[] = $img;
      }
    }
    $gallery = $newGallery;
  }

  // Handle uploads
  // Thumb upload
  if (!empty($_FILES['thumb']['name'])) {
    $ext = strtolower(pathinfo($_FILES['thumb']['name'], PATHINFO_EXTENSION));
    if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) {
      $safe = 'deb_thumb_' . (int)$user['id'] . '_' . time() . '.' . $ext;
      $dest = __DIR__ . '/../assets/img/' . $safe;
      if (move_uploaded_file($_FILES['thumb']['tmp_name'], $dest)) {
        $thumb = '/assets/img/' . $safe;
      } else {
        $error = 'Failed to upload thumbnail.';
      }
    } else {
      $error = 'Invalid thumbnail format.';
    }
  }

  // Gallery upload (multiple)
  if (!empty($_FILES['gallery']['name'][0])) {
    foreach ($_FILES['gallery']['name'] as $i => $name) {
      if (empty($name)) continue;
      $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
      if (!in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) continue;
      $safe = 'deb_gal_' . (int)$user['id'] . '_' . time() . '_' . $i . '.' . $ext;
      $dest = __DIR__ . '/../assets/img/' . $safe;
      if (move_uploaded_file($_FILES['gallery']['tmp_name'][$i], $dest)) {
        $gallery[] = '/assets/img/' . $safe;
      }
    }
  }

  // Optional: enforce credits (currently off)
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

  // Save changes
  $pdo->beginTransaction();
  try {
    if (is_admin($user)) {
      $pdo->prepare("UPDATE debates SET title=?, description=?, thumb_image=?, gallery_json=? WHERE id=?")
          ->execute([$title, $description, $thumb, json_encode($gallery), $id]);
    } else {
      $pdo->prepare("UPDATE debates SET title=?, description=?, thumb_image=?, gallery_json=? WHERE id=? AND creator_id=?")
          ->execute([$title, $description, $thumb, json_encode($gallery), $id, (int)$user['id']]);
    }

    // Deduct credits if enabled (currently off)
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
<html lang="en">
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

  <!-- Explicit delete buttons + Save form -->
  <div class="card" style="margin-bottom:16px">
    <!-- Current thumbnail (conditionally rendered) -->
    <?php if (!empty($thumb)):
      $thumbFs = __DIR__ . '/../' . ltrim($thumb, '/');
      if (file_exists($thumbFs)): ?>
        <div style="margin-top:8px">
          <img src="<?= htmlspecialchars($thumb) ?>" alt="Thumb" style="width:100%;max-height:220px;object-fit:cover;border-radius:8px;border:1px solid var(--border);margin-bottom:8px">
          <form method="post" onsubmit="return confirm('Delete thumbnail?');" style="display:inline">
            <input type="hidden" name="action" value="delete_thumb">
            <button class="btn-outline" type="submit">Delete thumbnail</button>
          </form>
        </div>
      <?php endif; endif; ?>

    <!-- Current gallery (each with a delete button) -->
    <?php if (!empty($gallery)): ?>
      <div class="grid" style="margin-top:16px">
        <?php foreach ($gallery as $img):
          $fs = __DIR__ . '/../' . ltrim($img, '/');
          if (!empty($img) && file_exists($fs)): ?>
            <div class="card">
              <img src="<?= htmlspecialchars($img) ?>" alt="Gallery" style="width:100%;height:140px;object-fit:cover;border-radius:8px;border:1px solid var(--border);margin-bottom:8px">
              <form method="post" onsubmit="return confirm('Delete this image?');">
                <input type="hidden" name="action" value="delete_gallery_one">
                <input type="hidden" name="img" value="<?= htmlspecialchars($img) ?>">
                <button class="btn-outline" type="submit">Delete this image</button>
              </form>
            </div>
          <?php endif; endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

  <!-- Save/update form -->
  <form method="post" class="card" enctype="multipart/form-data">
    <input type="hidden" name="action" value="save">

    <label class="label">Title</label>
    <input class="input" type="text" name="title" value="<?= htmlspecialchars($title) ?>" required>

    <label class="label">Description</label>
    <textarea class="input" name="description" rows="6" ><?= htmlspecialchars($description) ?></textarea>

    <!-- Optional batch delete (processed on Save) -->
    <?php if (!empty($thumb)): ?>
      <label class="label" style="margin-top:8px">
        <input type="checkbox" name="delete_thumb_batch" value="1"> Also delete current thumbnail on Save
      </label>
    <?php endif; ?>

    <?php if (!empty($gallery)): ?>
      <div class="label" style="margin-top:8px">Delete selected gallery images on Save:</div>
      <div class="grid" style="margin-top:8px">
        <?php foreach ($gallery as $img):
          $fs = __DIR__ . '/../' . ltrim($img, '/');
          if (!empty($img) && file_exists($fs)): ?>
            <label class="card" style="padding:8px">
              <img src="<?= htmlspecialchars($img) ?>" alt="Gallery" style="width:100%;height:120px;object-fit:cover;border-radius:8px;border:1px solid var(--border);margin-bottom:6px">
              <input type="checkbox" name="delete_gallery_batch[]" value="<?= htmlspecialchars($img) ?>"> Select to delete
            </label>
          <?php endif; endforeach; ?>
      </div>
    <?php endif; ?>

    <label class="label" style="margin-top:12px">Upload new thumb</label>
    <input class="input" type="file" name="thumb" accept="image/*">

    <label class="label" style="margin-top:12px">Add to gallery</label>
    <input class="input" type="file" name="gallery[]" accept="image/*" multiple>

    <button class="btn" type="submit" style="margin-top:12px">Save</button>
  </form>
</div>

</body>
</html>
