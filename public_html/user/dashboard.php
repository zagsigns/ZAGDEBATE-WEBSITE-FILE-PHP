<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/app.php';
require_login();
$user = current_user();
ensure_wallet($pdo, (int)$user['id']);
$wallet = $pdo->prepare("SELECT * FROM wallets WHERE user_id=?");
$wallet->execute([(int)$user['id']]);
$wallet = $wallet->fetch();
$settings = get_settings($pdo);
?>
<!DOCTYPE html>
<html>
<head>
  <?php $meta_title='User Dashboard • ZAG DEBATE'; include __DIR__ . '/../seo/meta.php'; ?>
  <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
<?php include __DIR__ . '/../includes/header.php'; ?>
<div class="container">
  <h2>Welcome, <?= htmlspecialchars($user['name']) ?></h2>
  <div class="grid">
    <div class="card">
      <h3>Your Wallet</h3>
      <p><strong>Credits:</strong> <?= (int)$wallet['credits'] ?></p>
      <p><strong>Earnings (USD):</strong> $<?= number_format((float)$wallet['earnings_usd'], 2) ?></p>
      <a class="btn" href="/user/buy_credits.php">Buy Credits</a>
      <a class="btn" href="/user/withdraw_request.php">Withdraw Earnings</a>
    </div>
    <div class="card">
      <h3>Your Debates</h3>
      <a class="btn" href="/debates/create.php">Create Debate</a>
      <a class="btn" href="/debates/list.php">Explore Debates</a>
    </div>
    <div class="card">
      <h3>Profile</h3>
      <a class="btn" href="/user/profile.php">Edit Profile</a>
    </div>
  </div>
  <div class="card" style="margin-top:16px">
    <p class="label">Withdraw threshold: $<?= number_format((float)$settings['withdraw_threshold_usd'], 2) ?> • Credit rate: $<?= number_format((float)$settings['credit_usd_rate'], 2) ?> per credit</p>
  </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>

</body>
</html>
