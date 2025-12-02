<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/app.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $id = (int)$_POST['id'];
  $status = $_POST['status'];
  $pdo->prepare("UPDATE withdrawals SET status=? WHERE id=?")->execute([$status, $id]);
}

$rows = $pdo->query("SELECT w.*, u.name, u.email FROM withdrawals w JOIN users u ON w.user_id=u.id ORDER BY w.created_at DESC")->fetchAll();
?>
<!DOCTYPE html>
<html>
<head>
  <?php $meta_title='Withdrawals • ZAG DEBATE'; include __DIR__ . '/../seo/meta.php'; ?>
  <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
<?php include __DIR__ . '/../includes/header.php'; ?>
<div class="container">
  <h2>Withdrawal requests</h2>
  <?php foreach ($rows as $r): ?>
    <div class="card" style="margin-bottom:8px">
      <p><strong><?= htmlspecialchars($r['name']) ?></strong> • <?= htmlspecialchars($r['email']) ?> • $<?= number_format((float)$r['amount_usd'],2) ?> • Status: <?= htmlspecialchars($r['status']) ?></p>
      <p class="label">Details: <?= htmlspecialchars($r['details_json']) ?></p>
      <form method="post">
        <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
        <select class="input" name="status" style="width:auto;display:inline-block">
          <option value="pending">pending</option>
          <option value="approved">approved</option>
          <option value="rejected">rejected</option>
          <option value="paid">paid</option>
        </select>
        <button class="btn" type="submit">Update</button>
      </form>
      <p class="label">Admin pays manually (outside website), then mark as paid.</p>
    </div>
  <?php endforeach; ?>
</div>
</body>
</html>
