<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/app.php';
require_login();
$user = current_user();
$settings = get_settings($pdo);
$rate = (float)$settings['credit_usd_rate'];
?>
<!DOCTYPE html>
<html>
<head>
  <?php $meta_title='Buy Credits • ZAG DEBATE'; include __DIR__ . '/../seo/meta.php'; ?>
  <link rel="stylesheet" href="/assets/css/style.css">
  <script src="https://checkout.razorpay.com/v1/checkout.js"></script>
</head>
<body>
<?php include __DIR__ . '/../includes/header.php'; ?>
<div class="container">
  <h2>Buy credits</h2>
  <div class="card">
    <p class="label">Price per credit: $<?= number_format($rate,2) ?></p>
    <form id="paymentForm">
      <label class="label">Credits quantity</label>
      <input class="input" type="number" name="credits" id="credits" min="1" value="10" required>
      <button class="btn" type="button" onclick="startPayment()">Buy Credits</button>
    </form>
    <div id="paymentStatus" class="label" style="margin-top:12px"></div>
    <p class="label">To enable real payments: add Razorpay Key and Secret in Admin → Settings.</p>
  </div>
</div>

<script>
function startPayment() {
  const credits = document.getElementById('credits').value;
  if (!credits || credits < 1) return;

  fetch('/payments/create_order.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ credits })
  })
  .then(res => res.json())
  .then(data => {
    if (!data.order_id || !data.key) {
      document.getElementById('paymentStatus').textContent = 'Error creating Razorpay order.';
      return;
    }

    const options = {
      key: data.key,
      amount: data.amount,
      currency: "INR",
      name: "ZAG DEBATE",
      description: credits + " credits",
      order_id: data.order_id,
      handler: function (response) {
        fetch('/payments/confirm_payment.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({
            razorpay_payment_id: response.razorpay_payment_id,
            razorpay_order_id: response.razorpay_order_id,
            razorpay_signature: response.razorpay_signature,
            credits: credits
          })
        })
        .then(res => res.json())
        .then(result => {
          document.getElementById('paymentStatus').textContent = result.message;
          if (result.success) location.reload();
        });
      }
    };

    const rzp = new Razorpay(options);
    rzp.open();
  });
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>
