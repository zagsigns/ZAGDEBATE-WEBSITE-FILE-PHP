<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/app.php';
require_login();
$user = current_user();
$wallet = $pdo->prepare("SELECT * FROM wallets WHERE user_id=?");
$wallet->execute([(int)$user['id']]);
$wallet = $wallet->fetch();

$rows = $pdo->prepare("SELECT d.title, ds.credits, ds.usd_value, ds.created_at FROM debate_spend ds JOIN debates d ON ds.debate_id=d.id WHERE d.creator_id=? ORDER BY ds.created_at DESC");
$rows->execute([(int)$user['id']]);
$rows = $rows->fetchAll();
?>
<!DOCTYPE html>
<html>
<head>
  <?php $meta_title='Earnings • ZAG DEBATE'; include __DIR__ . '/../seo/meta.php'; ?>
  <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
<div class="container">
  <h2>Your earnings</h2>
  <div class="card">
    <p><strong>Total earnings:</strong> $<?= number_format((float)$wallet['earnings_usd'], 2) ?></p>
    <table style="width:100%;border-collapse:collapse">
      <tr><th style="text-align:left;border-bottom:1px solid var(--border);padding:8px">Debate</th><th style="text-align:left;border-bottom:1px solid var(--border);padding:8px">Credits</th><th style="text-align:left;border-bottom:1px solid var(--border);padding:8px">USD</th></tr>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td style="border-bottom:1px solid var(--border);padding:8px"><?= htmlspecialchars($r['title']) ?></td>
          <td style="border-bottom:1px solid var(--border);padding:8px"><?= (int)$r['credits'] ?></td>
          <td style="border-bottom:1px solid var(--border);padding:8px">$<?= number_format((float)$r['usd_value']*0.50, 2) ?> (50%)</td>
        </tr>
      <?php endforeach; ?>
    </table>
  </div>
</div>
</body>
</html>
