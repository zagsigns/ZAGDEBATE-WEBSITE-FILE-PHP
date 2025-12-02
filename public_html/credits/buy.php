<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (empty($_SESSION['user'])) { header('Location: /auth/login.php'); exit; }
$settings = $pdo->query("SELECT credit_rate_inr_per_credit FROM settings LIMIT 1")->fetch();
$rate = (int)($settings['credit_rate_inr_per_credit'] ?? 10);
?>
<!doctype html>
<html>
<head>
  <link rel="stylesheet" href="/assets/css/style.css">
  <script src="https://checkout.razorpay.com/v1/checkout.js"></script>
</head>
<body>
<?php include __DIR__ . '/../includes/header.php'; ?>
<div class="container card">
  <h2>Buy credits</h2>
  <p>Rate: ₹<?= $rate ?> per credit</p>
  <form id="buyForm">
    <label class="label">Credits</label>
    <input class="input" type="number" min="1" value="10" name="credits">
    <button class="btn" type="submit">Proceed to pay</button>
  </form>
</div>
<script>
document.getElementById('buyForm').addEventListener('submit', async (e) => {
  e.preventDefault();
  const credits = e.target.credits.value;
  const res = await fetch('/payments/create_order.php', {
    method: 'POST',
    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
    body: 'credits=' + encodeURIComponent(credits)
  });
  const data = await res.json();
  const options = {
    key: data.key,
    amount: data.amount,
    currency: "INR",
    name: "ZAG DEBATE",
    description: "Credits purchase",
    order_id: data.order_id,
    prefill: { email: "<?= htmlspecialchars($_SESSION['user']['email']) ?>" },
    theme: { color: "#ff2e2e" },
    handler: async function (resp) {
      // Confirm payment on server
      const ok = await fetch('/payments/confirm_payment.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: new URLSearchParams(resp).toString()
      });
      if (ok.status === 200) { location.href = '/user/credits.php'; }
      else { alert('Payment verification failed'); }
    }
  };
  new Razorpay(options).open();
});
</script>
</body>
</html>
