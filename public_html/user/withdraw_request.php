<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/app.php';
require_login();
$user = current_user();
$wallet = $pdo->prepare("SELECT * FROM wallets WHERE user_id=?");
$wallet->execute([(int)$user['id']]);
$wallet = $wallet->fetch();
$settings = get_settings($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $method = $_POST['method'] ?? '';
  $amount = (float)($_POST['amount'] ?? 0);
  $details = [];
  if ($method === 'bank') {
    $details = [
      'account' => trim($_POST['account'] ?? ''),
      'ifsc' => trim($_POST['ifsc'] ?? ''),
      'name' => trim($_POST['name'] ?? '')
    ];
  } elseif ($method === 'upi') {
    $details = ['upi' => trim($_POST['upi'] ?? '')];
  }
  if ($amount > 0 && $amount <= (float)$wallet['earnings_usd'] && $amount >= (float)$settings['withdraw_threshold_usd']) {
    $pdo->prepare("INSERT INTO withdrawals (user_id, amount_usd, method, details_json) VALUES (?, ?, ?, ?)")
        ->execute([(int)$user['id'], $amount, $method, json_encode($details)]);
    $pdo->prepare("UPDATE wallets SET earnings_usd=earnings_usd-? WHERE user_id=?")->execute([$amount, (int)$user['id']]);
    $success = 'Withdrawal request submitted.';
  } else {
    $error = 'Amount must be between threshold and your balance.';
  }
}
?>
<!DOCTYPE html>
<html>
<head>
  <?php $meta_title='Withdraw Earnings • ZAG DEBATE'; include __DIR__ . '/../seo/meta.php'; ?>
  <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
<?php include __DIR__ . '/../includes/header.php'; ?>
<div class="container">
  <h2>Withdraw earnings</h2>
  <?php if (!empty($success)): ?><div class="alert alert-success"><?= htmlspecialchars($success) ?></div><?php endif; ?>
  <?php if (!empty($error)): ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
  <div class="card">
    <p class="label">Balance: $<?= number_format((float)$wallet['earnings_usd'], 2) ?> • Threshold: $<?= number_format((float)$settings['withdraw_threshold_usd'], 2) ?></p>
    <form method="post">
      <label class="label">Amount (USD)</label>
      <input class="input" type="number" step="0.01" name="amount" required>
      <label class="label">Method</label>
      <select class="input" name="method" required>
        <option value="bank">Bank transfer</option>
        <option value="upi">UPI</option>
      </select>
      <div class="card" style="margin-top:8px">
        <div class="form-row">
          <div>
            <label class="label">Account number</label>
            <input class="input" type="text" name="account">
          </div>
          <div>
            <label class="label">IFSC</label>
            <input class="input" type="text" name="ifsc">
          </div>
        </div>
        <label class="label">Account holder name</label>
        <input class="input" type="text" name="name">
        <label class="label">UPI ID (if using UPI)</label>
        <input class="input" type="text" name="upi">
      </div>
      <button class="btn" type="submit">Submit request</button>
    </form>
  </div>
</div>
</body>
</html>
