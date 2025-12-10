<?php
// public_html/debates/create.php
// Production-ready create handler with description optional (NULL when empty).

ini_set('display_errors', 0);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/app.php';

require_login();
$user = current_user();

// Load platform settings including free create limit
$settings = $pdo->query("SELECT debate_access_mode, credits_to_create, free_create_limit FROM settings")->fetch(PDO::FETCH_ASSOC);
$access_mode = $settings['debate_access_mode'] ?? 'free';
$credits_required = (int)($settings['credits_to_create'] ?? 0);
$free_create_limit = (int)($settings['free_create_limit'] ?? 0);

// Helpers
function safe_filename($prefix, $userId, $index = null, $ext = 'jpg') {
    $time = time();
    $idx = $index === null ? '' : '_' . (int)$index;
    $ext = preg_replace('/[^a-z0-9]/i', '', $ext);
    return sprintf('%s_%d_%d%s.%s', $prefix, (int)$userId, $time, $idx, $ext);
}
function validate_image_upload($file) {
    if (empty($file) || empty($file['tmp_name'])) return ['ok' => false, 'error' => 'No file uploaded', 'ext' => null];
    if ($file['error'] !== UPLOAD_ERR_OK) return ['ok' => false, 'error' => 'Upload error code: ' . $file['error'], 'ext' => null];
    if ($file['size'] > 6 * 1024 * 1024) return ['ok' => false, 'error' => 'File too large (max 6MB)', 'ext' => null];

    $finfo = @finfo_open(FILEINFO_MIME_TYPE);
    $mime = $finfo ? finfo_file($finfo, $file['tmp_name']) : mime_content_type($file['tmp_name']);
    if ($finfo) @finfo_close($finfo);

    $allowed = [
        'image/jpeg' => 'jpg',
        'image/jpg'  => 'jpg',
        'image/png'  => 'png',
        'image/gif'  => 'gif',
        'image/webp' => 'webp'
    ];
    if (!isset($allowed[$mime])) return ['ok' => false, 'error' => 'Unsupported image type: ' . $mime, 'ext' => null];
    return ['ok' => true, 'error' => null, 'ext' => $allowed[$mime]];
}

// Ensure assets dir exists
@mkdir(__DIR__ . '/../assets/img', 0775, true);

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = trim((string)($_POST['title'] ?? ''));
    $description = trim((string)($_POST['description'] ?? ''));
    $thumb = null;
    $gallery = [];

    // Server-side: require only title
    if ($title === '') {
        $error = 'Title is required.';
    } else {
        // Count how many debates this user has already created
        try {
            $createdCountStmt = $pdo->prepare("SELECT COUNT(*) FROM debates WHERE creator_id = ?");
            $createdCountStmt->execute([(int)$user['id']]);
            $userCreatedCount = (int)$createdCountStmt->fetchColumn();
        } catch (Exception $e) {
            error_log('Create: count query failed: ' . $e->getMessage());
            $error = 'Something went wrong. Please try again.';
            $userCreatedCount = 0;
        }

        // Credits check (if applicable)
        if (empty($error) && !is_admin($user) && $access_mode === 'credits' && $credits_required > 0 && $userCreatedCount >= $free_create_limit) {
            try {
                $stmt = $pdo->prepare("SELECT credits FROM wallets WHERE user_id = ? LIMIT 1");
                $stmt->execute([(int)$user['id']]);
                $userCredits = (int)$stmt->fetchColumn();
                if ($userCredits < $credits_required) {
                    $_SESSION['flash_error'] = "You need $credits_required credits to create a debate.";
                    header('Location: /user/buy_credits.php');
                    exit;
                }
            } catch (Exception $e) {
                error_log('Create: wallet query failed: ' . $e->getMessage());
                $error = 'Something went wrong. Please try again.';
            }
        }

        // Thumb upload (optional)
        if (empty($error) && !empty($_FILES['thumb']['name'])) {
            $v = validate_image_upload($_FILES['thumb']);
            if (!$v['ok']) {
                error_log('Thumb validation failed: ' . $v['error'] . ' FILES: ' . print_r($_FILES['thumb'], true));
                $error = 'Thumb upload failed: ' . $v['error'];
            } else {
                $safeName = safe_filename('deb_thumb', $user['id'], null, $v['ext']);
                $dest = __DIR__ . '/../assets/img/' . $safeName;
                if (move_uploaded_file($_FILES['thumb']['tmp_name'], $dest)) {
                    $thumb = '/assets/img/' . $safeName;
                } else {
                    error_log('move_uploaded_file thumb failed: ' . print_r($_FILES['thumb'], true));
                    $error = 'Failed to save thumb image. Check folder permissions.';
                }
            }
        }

        // Gallery upload (optional)
        if (empty($error) && !empty($_FILES['gallery']['name'][0])) {
            foreach ($_FILES['gallery']['name'] as $i => $name) {
                if (empty($_FILES['gallery']['tmp_name'][$i])) continue;
                $file = [
                    'name' => $_FILES['gallery']['name'][$i],
                    'type' => $_FILES['gallery']['type'][$i],
                    'tmp_name' => $_FILES['gallery']['tmp_name'][$i],
                    'error' => $_FILES['gallery']['error'][$i],
                    'size' => $_FILES['gallery']['size'][$i]
                ];
                $v = validate_image_upload($file);
                if (!$v['ok']) {
                    error_log("Gallery file #$i skipped: " . $v['error'] . ' FILE: ' . print_r($file, true));
                    continue;
                }
                $safeName = safe_filename('deb_gal', $user['id'], $i, $v['ext']);
                $dest = __DIR__ . '/../assets/img/' . $safeName;
                if (move_uploaded_file($file['tmp_name'], $dest)) {
                    $gallery[] = '/assets/img/' . $safeName;
                } else {
                    error_log("move_uploaded_file gallery #$i failed: " . print_r($file, true));
                }
            }
        }

        // Normalize optional values to NULL for DB
        $dbDescription = $description !== '' ? $description : null;
        $dbThumb = $thumb !== null ? $thumb : null;
        $dbGallery = !empty($gallery) ? json_encode($gallery, JSON_UNESCAPED_SLASHES) : null;

        // Insert debate and deduct credits atomically
        if (empty($error)) {
            try {
                $pdo->beginTransaction();

                $stmt = $pdo->prepare("INSERT INTO debates (creator_id, title, description, thumb_image, gallery_json, created_at) 
                                       VALUES (?, ?, ?, ?, ?, NOW())");
                $stmt->execute([
                    (int)$user['id'],
                    $title,
                    $dbDescription,
                    $dbThumb,
                    $dbGallery
                ]);
                $debateId = (int)$pdo->lastInsertId();

                // Deduct credits only if free limit exceeded
                if (!is_admin($user) && $access_mode === 'credits' && $credits_required > 0 && $userCreatedCount >= $free_create_limit) {
                    // ensure wallet row exists
                    $w = $pdo->prepare("SELECT id, credits FROM wallets WHERE user_id = ? LIMIT 1");
                    $w->execute([(int)$user['id']]);
                    $walletRow = $w->fetch(PDO::FETCH_ASSOC);
                    if (!$walletRow) {
                        $createW = $pdo->prepare("INSERT INTO wallets (user_id, credits, earnings_usd, created_at) VALUES (?, 0, 0, NOW())");
                        $createW->execute([(int)$user['id']]);
                    }
                    $deduct = $pdo->prepare("UPDATE wallets SET credits = credits - ? WHERE user_id = ? AND credits >= ?");
                    $deduct->execute([$credits_required, (int)$user['id'], $credits_required]);
                    if ($deduct->rowCount() === 0) {
                        $pdo->rollBack();
                        error_log('Create: credit deduction failed for user ' . (int)$user['id']);
                        $_SESSION['flash_error'] = "Failed to deduct credits. Please try again.";
                        header('Location: /user/buy_credits.php');
                        exit;
                    }
                }

                $pdo->commit();
                header('Location: /debates/view.php?id=' . $debateId);
                exit;
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                error_log('Create debate exception: ' . $e->getMessage() . "\nTrace: " . $e->getTraceAsString());
                $error = 'Something went wrong while creating the debate. Please try again.';
            }
        }
    }
}

// Prepare counts for UI
try {
    $createdCountStmt = $pdo->prepare("SELECT COUNT(*) FROM debates WHERE creator_id = ?");
    $createdCountStmt->execute([(int)$user['id']]);
    $userCreatedCount = (int)$createdCountStmt->fetchColumn();
} catch (Exception $e) {
    error_log('Create: count query failed (UI): ' . $e->getMessage());
    $userCreatedCount = 0;
}
$free_left = max(0, $free_create_limit - $userCreatedCount);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php $meta_title='Create Debate • ZAG DEBATE'; include __DIR__ . '/../seo/meta.php'; ?>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link rel="stylesheet" href="/assets/css/style.css">
  <style>
    /* Minimal local styles to keep form usable if your stylesheet is missing */
    .card { max-width:900px; margin:18px auto; padding:18px; background:rgba(0,0,0,0.04); border-radius:8px; }
    .input { width:100%; padding:10px 12px; border-radius:8px; border:1px solid #ddd; box-sizing:border-box; }
    .form-row { display:flex; gap:12px; flex-wrap:wrap; }
    .form-row > div { flex:1; min-width:220px; }
    .label { display:block; margin-bottom:8px; font-weight:700; }
    .alert-error { background: #f8d7da; color:#842029; padding:10px 12px; border-radius:8px; margin-bottom:12px; }
    .btn { padding:10px 14px; border-radius:10px; background:#e03b3b; color:#fff; border:none; cursor:pointer; font-weight:700; }
  </style>
</head>
<body>
<?php include __DIR__ . '/../includes/header.php'; ?>
<div class="container">
  <div class="card">
    <h2>Create debate</h2>

    <?php if (!empty($_SESSION['flash_error'])): ?>
      <div class="alert alert-error"><?= htmlspecialchars($_SESSION['flash_error']) ?></div>
      <?php unset($_SESSION['flash_error']); ?>
    <?php endif; ?>

    <?php if (!empty($error)): ?>
      <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="post" class="card" enctype="multipart/form-data" novalidate>
      <label class="label" for="title">Title <span style="color:#c00">*</span></label>
      <input id="title" class="input" type="text" name="title" required maxlength="255" value="<?= isset($title) ? htmlspecialchars($title) : '' ?>">

      <label class="label" for="description">Description (optional)</label>
      <textarea id="description" class="input" name="description" rows="6"><?= isset($description) ? htmlspecialchars($description) : '' ?></textarea>

      <div class="form-row" style="margin-top:12px">
        <div>
          <label class="label" for="thumb">Thumb image (optional)</label>
          <input id="thumb" class="input" type="file" name="thumb" accept="image/*">
        </div>
        <div>
          <label class="label" for="gallery">Image gallery (optional, multiple)</label>
          <input id="gallery" class="input" type="file" name="gallery[]" accept="image/*" multiple>
        </div>
      </div>

      <?php if (!is_admin($user) && $access_mode === 'credits' && $credits_required > 0 && $userCreatedCount >= $free_create_limit): ?>
        <p class="label" style="margin-top:12px">Creating a debate costs <?= $credits_required ?> credits.</p>
      <?php else: ?>
        <p class="label" style="margin-top:12px">Creating a debate is free (<?= $free_left ?> free chances left).</p>
      <?php endif; ?>

      <div style="margin-top:12px">
        <button class="btn" type="submit">Create</button>
      </div>
    </form>
  </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>
