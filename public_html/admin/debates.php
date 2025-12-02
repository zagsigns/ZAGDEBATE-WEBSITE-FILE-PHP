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

        <!-- Delete with confirmation -->
        <form method="post" style="display:inline;margin-left:8px"
              onsubmit="return confirm('Are you sure you want to delete this debate? This action cannot be undone.');">
          <input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
          <input type="hidden" name="action" value="delete">
          <button class="btn-outline" type="submit">Delete</button>
        </form>

        <!-- View -->
        <a class="btn-outline" style="margin-left:8px" 
           href="/debates/view.php?id=<?= (int)$d['id'] ?>">View</a>
      </div>
    </div>
  <?php endforeach; ?>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>
