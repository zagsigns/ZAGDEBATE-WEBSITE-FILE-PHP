<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/app.php';
require_login();
$user = current_user();
ensure_wallet($pdo, (int)$user['id']);
$wallet = $pdo->prepare("SELECT * FROM wallets WHERE user_id=?");
$wallet->execute([(int)$user['id']]);
$wallet = $wallet->fetch();
?>
<!DOCTYPE html>
<html>
<head>
  <?php $meta_title='Wallet • ZAG DEBATE'; include __DIR__ . '/../seo/meta.php'; ?>
  <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
<div class="container">
  <h2>Your wallet</h2>
  <div class="card">
    <p><strong>Credits:</strong> <?= (int)$wallet['credits'] ?></p>
    <p><strong>Earnings:</strong> $<?= number_format((float)$wallet['earnings_usd'], 2) ?></p>
    <a class="btn" href="/user/buy_credits.php">Buy credits</a>
    <a class="btn-outline" href="/user/withdraw_request.php">Withdraw earnings</a>
  </div>
</div>
</body>
</html>
