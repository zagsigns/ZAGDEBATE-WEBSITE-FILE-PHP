<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/app.php';
require_admin();

$success = '';
$error = '';

// Handle actions (delete only here, full editing handled in edit.php)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'], $_POST['id'])) {
  $id = (int)$_POST['id'];
  if ($_POST['action'] === 'delete') {
    try {
      $pdo->prepare("DELETE FROM debates WHERE id=?")->execute([$id]);
      $success = 'Debate deleted successfully.';
    } catch (Exception $e) {
      $error = 'Error deleting debate.';
    }
  }
}

// Fetch debates
$debates = $pdo->query("SELECT d.*, u.name AS creator_name 
                        FROM debates d 
                        JOIN users u ON d.creator_id=u.id 
                        ORDER BY d.created_at DESC")->fetchAll();
?>
<!DOCTYPE html>
<html>
<head>
  <?php $meta_title = 'Manage Debates • ZAG DEBATE'; include __DIR__ . '/../seo/meta.php'; ?>
  <link rel="stylesheet" href="/assets/css/style.css">
  <style>
    /* Layout */
    .form-row { display:flex; align-items:center; gap:8px; flex-wrap:wrap; margin-top:8px; }
    .card { background: rgba(0,0,0,0.45); border-radius:10px; padding:18px; color:inherit; }

    /* Primary red button */
    .btn {
      padding:10px 14px;
      border-radius:8px;
      background:#e03b3b;
      color:#fff;
      border:none;
      cursor:pointer;
      font-weight:700;
      text-decoration:none;
      display:inline-block;
    }
    .btn:hover { background:#c83232; }

    /* Outline button */
    .btn-outline {
      padding:10px 14px;
      border-radius:8px;
      background:transparent;
      color:inherit;
      border:1px solid rgba(255,255,255,0.06);
      text-decoration:none;
      font-weight:600;
      cursor:pointer;
      display:inline-block;
    }

    /* Inline form reset so forms align with links/buttons */
    form.inline { display:inline; margin:0; padding:0; }

    /* Alerts */
    .alert-success { background: rgba(0,128,0,0.08); color:#dff0d8; padding:10px; border-radius:6px; margin-bottom:12px; }
    .alert-error { background: rgba(255,0,0,0.08); color:#ffdddd; padding:10px; border-radius:6px; margin-bottom:12px; }
  </style>
</head>
<body>
<?php include __DIR__ . '/../includes/header.php'; ?>
<div class="container">
  <h2>Debates</h2>

  <?php if (!empty($success)): ?>
    <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
  <?php endif; ?>
  <?php if (!empty($error)): ?>
    <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <?php foreach ($debates as $d): ?>
    <div class="card" style="margin-bottom:12px">
      <p>
        <strong><?= htmlspecialchars($d['title']) ?></strong>
        • By <?= htmlspecialchars($d['creator_name']) ?>
        • Status: <?= htmlspecialchars($d['status']) ?>
      </p>

      <div class="form-row">
        <!-- Update redirects to edit page -->
        <a class="btn" href="/debates/edit.php?id=<?= (int)$d['id'] ?>">Update</a>

        <!-- Delete with unobtrusive confirmation (no inline onsubmit) -->
        <form method="post" class="inline" data-action="delete" data-title="<?= htmlspecialchars($d['title'], ENT_QUOTES) ?>">
          <input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
          <input type="hidden" name="action" value="delete">
          <button class="btn" type="submit">Delete</button>
        </form>

        <!-- View styled like primary button -->
        <a class="btn" href="/debates/view.php?id=<?= (int)$d['id'] ?>">View</a>
      </div>
    </div>
  <?php endforeach; ?>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
  // Attach confirmation to delete forms that use data-action="delete"
  document.querySelectorAll('form[data-action="delete"]').forEach(function(form) {
    form.addEventListener('submit', function(e) {
      // Prefer the data-title attribute (escaped server-side) or fallback to nearby strong text
      var title = form.getAttribute('data-title') || (form.closest('.card')?.querySelector('strong')?.textContent || 'this debate');
      var confirmed = confirm('Are you sure you want to delete "' + title + '"? This action cannot be undone.');
      if (!confirmed) {
        e.preventDefault();
      }
    });
  });
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>
