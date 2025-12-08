<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/app.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
  if ($_POST['action'] === 'delete') {
    $pdo->prepare("DELETE FROM users WHERE id=? AND role='user'")->execute([(int)$_POST['id']]);
  } elseif ($_POST['action'] === 'update') {
    $pdo->prepare("UPDATE users SET name=?, bio=? WHERE id=? AND role='user'")
        ->execute([trim($_POST['name']), trim($_POST['bio']), (int)$_POST['id']]);
  }
}

$users = $pdo->query("SELECT id,name,email,bio,is_verified FROM users WHERE role='user' ORDER BY created_at DESC")->fetchAll();
?>
<!DOCTYPE html>
<html>
<head>
  <?php $meta_title='Manage Users • ZAG DEBATE'; include __DIR__ . '/../seo/meta.php'; ?>
  <link rel="stylesheet" href="/assets/css/style.css">
  <style>
    /* Ensure delete button matches primary red .btn styling */
    .btn-danger {
      padding:10px 14px;
      border-radius:8px;
      background:#e03b3b;
      color:#fff;
      border:none;
      cursor:pointer;
      font-weight:700;
      display:inline-block;
    }
    .btn-danger:hover { background:#c83232; }

    /* Keep outline variant for other uses */
    .btn-outline {
      display:inline-flex; align-items:center; justify-content:center;
      gap:8px; padding:8px 10px; border-radius:8px;
      background: transparent; color: inherit; border: 1px solid rgba(255,255,255,0.06);
      text-decoration: none; font-weight: 600; cursor: pointer;
    }

    /* Small spacing tweak for forms */
    .user-card-form { margin-bottom:8px; }
  </style>
  <script>
    // Attach a confirmation prompt to all delete forms on the page
    document.addEventListener('DOMContentLoaded', function () {
      document.querySelectorAll('form[data-action="delete"]').forEach(function(form) {
        form.addEventListener('submit', function(e) {
          var nameEl = form.closest('.card')?.querySelector('strong');
          var name = nameEl ? nameEl.textContent.trim() : 'this user';
          var confirmed = confirm('Are you sure you want to delete ' + name + '? This action cannot be undone.');
          if (!confirmed) {
            e.preventDefault();
          }
        });
      });
    });
  </script>
</head>
<body>
<?php include __DIR__ . '/../includes/header.php'; ?>
<div class="container">
  <h2>Users</h2>
  <?php foreach ($users as $u): ?>
    <div class="card" style="margin-bottom:8px">
      <p><strong><?= htmlspecialchars($u['name']) ?></strong> • <?= htmlspecialchars($u['email']) ?> • <?= $u['is_verified']?'Verified':'Not verified' ?></p>

      <form method="post" class="user-card-form">
        <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
        <input type="hidden" name="action" value="update">
        <label class="label">Name</label>
        <input class="input" type="text" name="name" value="<?= htmlspecialchars($u['name']) ?>">
        <label class="label">Bio</label>
        <textarea class="input" name="bio" rows="2"><?= htmlspecialchars($u['bio'] ?? '') ?></textarea>
        <button class="btn" type="submit">Save</button>
      </form>

      <form method="post" style="margin-top:8px" data-action="delete">
        <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
        <input type="hidden" name="action" value="delete">
        <button class="btn-danger" type="submit">Delete user</button>
      </form>
    </div>
  <?php endforeach; ?>
</div>
</body>
</html>
