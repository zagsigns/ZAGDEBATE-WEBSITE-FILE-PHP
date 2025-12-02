<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/app.php';
require_admin();

$total_users = $pdo->query("SELECT COUNT(*) AS c FROM users WHERE role='user'")->fetch()['c'];
$total_debates = $pdo->query("SELECT COUNT(*) AS c FROM debates")->fetch()['c'];
$total_withdrawals = $pdo->query("SELECT COUNT(*) AS c FROM withdrawals")->fetch()['c'];

$revenue = $pdo->query("SELECT SUM(usd_value) AS s FROM debate_spend")->fetch()['s'] ?? 0;
$admin_share = $revenue * 0.50; // total platform share
?>
<!DOCTYPE html>
<html>
<head>
  <?php $meta_title='Admin Dashboard • ZAG DEBATE'; include __DIR__ . '/../seo/meta.php'; ?>
  <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
<?php include __DIR__ . '/../includes/header.php'; ?>

<div class="container">
  <h2>Admin dashboard</h2>
  <div class="grid">
    <div class="card">
      <h3>Users</h3>
      <p><?= (int)$total_users ?></p>
      <a class="btn" href="/admin/users.php" style="margin-top:8px">Manage</a>
    </div>

    <div class="card">
      <h3>Debates</h3>
      <p><?= (int)$total_debates ?></p>
      <a class="btn" href="/admin/debates.php" style="margin-top:8px">Manage</a>
    </div>

    <div class="card">
      <h3>Withdrawals</h3>
      <p><?= (int)$total_withdrawals ?></p>
      <a class="btn" href="/admin/withdrawals.php" style="margin-top:8px">Review</a>
    </div>

    <div class="card">
      <h3>Revenue</h3>
      <p>Total: $<?= number_format((float)$revenue,2) ?> • Admin share: $<?= number_format((float)$admin_share,2) ?></p>
      <a class="btn" href="/admin/settings.php" style="margin-top:8px">Settings</a>
    </div>
  </div>
</div>


<?php include __DIR__ . '/../includes/footer.php'; ?>

</body>
</html>
