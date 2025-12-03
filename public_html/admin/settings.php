<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/app.php';
require_admin();

// Always fetch the latest settings row
$stmt = $pdo->query("SELECT * FROM settings ORDER BY updated_at DESC LIMIT 1");
$settings = $stmt->fetch(PDO::FETCH_ASSOC);

$success = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  try {
    // Collect values from form
    $credit_usd_rate = (float)($_POST['credit_usd_rate'] ?? $settings['credit_usd_rate']);
    $credit_inr_rate = (int)($_POST['credit_rate_inr_per_credit'] ?? $settings['credit_rate_inr_per_credit']);
    $threshold = (float)($_POST['withdraw_threshold_usd'] ?? $settings['withdraw_threshold_usd']);
    $razorpay_key = trim($_POST['razorpay_key'] ?? $settings['razorpay_key']);
    $razorpay_secret = trim($_POST['razorpay_secret'] ?? $settings['razorpay_secret']);
    $admin_email = trim($_POST['admin_email'] ?? $settings['admin_email']);
    $access_mode = ($_POST['debate_access_mode'] === 'credits') ? 'credits' : 'free';
    $credits_to_create = (int)($_POST['credits_to_create'] ?? $settings['credits_to_create']);
    $credits_to_join = (int)($_POST['credits_to_join'] ?? $settings['credits_to_join']);
    $free_create_limit = (int)($_POST['free_create_limit'] ?? $settings['free_create_limit']);
    $free_join_limit = (int)($_POST['free_join_limit'] ?? $settings['free_join_limit']);
    $free_join_per_debate = (int)($_POST['free_join_per_debate'] ?? $settings['free_join_per_debate']);
    $free_join_time_minutes = (int)($_POST['free_join_time_minutes'] ?? $settings['free_join_time_minutes']);

    // Update the latest row
    $stmt = $pdo->prepare("UPDATE settings SET 
      credit_usd_rate=?, 
      credit_rate_inr_per_credit=?, 
      withdraw_threshold_usd=?, 
      razorpay_key=?, 
      razorpay_secret=?, 
      admin_email=?, 
      debate_access_mode=?, 
      credits_to_create=?, 
      credits_to_join=?, 
      free_create_limit=?, 
      free_join_limit=?, 
      free_join_per_debate=?, 
      free_join_time_minutes=? 
      ORDER BY updated_at DESC LIMIT 1");

    $stmt->execute([
      $credit_usd_rate,
      $credit_inr_rate,
      $threshold,
      $razorpay_key,
      $razorpay_secret,
      $admin_email,
      $access_mode,
      $credits_to_create,
      $credits_to_join,
      $free_create_limit,
      $free_join_limit,
      $free_join_per_debate,
      $free_join_time_minutes
    ]);

    // Reload updated settings
    $stmt = $pdo->query("SELECT * FROM settings ORDER BY updated_at DESC LIMIT 1");
    $settings = $stmt->fetch(PDO::FETCH_ASSOC);
    $success = 'Settings updated successfully.';
  } catch (Exception $e) {
    $error = 'Failed to update settings. Error: ' . $e->getMessage();
  }
}
?>
<!DOCTYPE html>
<html>
<head>
  <?php $meta_title='Admin Settings • ZAG DEBATE'; include __DIR__ . '/../seo/meta.php'; ?>
  <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
<?php include __DIR__ . '/../includes/header.php'; ?>
<div class="container">
  <h2>Admin Settings</h2>
  <?php if (!empty($success)): ?><div class="alert alert-success"><?= htmlspecialchars($success) ?></div><?php endif; ?>
  <?php if (!empty($error)): ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

  <form method="post" class="card">
    <label class="label">Admin email</label>
    <input class="input" type="email" name="admin_email" value="<?= htmlspecialchars($settings['admin_email']) ?>" required>

    <label class="label">Credit USD rate (per credit)</label>
    <input class="input" type="number" step="0.01" name="credit_usd_rate" value="<?= htmlspecialchars($settings['credit_usd_rate']) ?>" required>

    <label class="label">Credit INR rate (per credit)</label>
    <input class="input" type="number" name="credit_rate_inr_per_credit" value="<?= htmlspecialchars($settings['credit_rate_inr_per_credit']) ?>" required>

    <label class="label">Withdraw threshold USD</label>
    <input class="input" type="number" step="0.01" name="withdraw_threshold_usd" value="<?= htmlspecialchars($settings['withdraw_threshold_usd']) ?>" required>

    <label class="label">Razorpay Key</label>
    <input class="input" type="text" name="razorpay_key" value="<?= htmlspecialchars($settings['razorpay_key']) ?>">

    <label class="label">Razorpay Secret</label>
    <input class="input" type="text" name="razorpay_secret" value="<?= htmlspecialchars($settings['razorpay_secret']) ?>">

    <label class="label">Debate Access Mode</label>
    <select class="input" name="debate_access_mode">
      <option value="free" <?= $settings['debate_access_mode'] === 'free' ? 'selected' : '' ?>>Free</option>
      <option value="credits" <?= $settings['debate_access_mode'] === 'credits' ? 'selected' : '' ?>>Credits</option>
    </select>

    <label class="label">Credits to create a debate</label>
    <input class="input" type="number" name="credits_to_create" value="<?= htmlspecialchars($settings['credits_to_create']) ?>" required>

    <label class="label">Credits to join a debate</label>
    <input class="input" type="number" name="credits_to_join" value="<?= htmlspecialchars($settings['credits_to_join']) ?>" required>

    <h3 style="margin-top:16px">Free Debate Limits</h3>

    <label class="label">Free debates per user (create)</label>
    <input class="input" type="number" name="free_create_limit" value="<?= htmlspecialchars($settings['free_create_limit']) ?>" min="0">

    <label class="label">Free debates per user (join)</label>
    <input class="input" type="number" name="free_join_limit" value="<?= htmlspecialchars($settings['free_join_limit']) ?>" min="0">

    <label class="label">Free join slots per debate</label>
    <input class="input" type="number" name="free_join_per_debate" value="<?= htmlspecialchars($settings['free_join_per_debate']) ?>" min="0">

    <label class="label">Free join time limit (minutes)</label>
    <input class="input" type="number" name="free_join_time_minutes" value="<?= htmlspecialchars($settings['free_join_time_minutes']) ?>" min="0">

    <button class="btn" type="submit" style="margin-top:12px">Save settings</button>
  </form>
</div>
</body>
</html>
