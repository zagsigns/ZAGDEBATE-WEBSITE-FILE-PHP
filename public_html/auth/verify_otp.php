<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/app.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$error = '';

$admin_pending_id = $_SESSION['admin_pending_user_id'] ?? null;
$pending_user_id = $_SESSION['pending_verify_user_id'] ?? null;
$reset_user_id = $_SESSION['reset_user_id'] ?? null;

if (!$admin_pending_id && !$pending_user_id && !$reset_user_id) {
    header('Location: /auth/login.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $otp_posted = trim((string)($_POST['otp'] ?? ''));

    if ($admin_pending_id) {
        $stmt = $pdo->prepare("SELECT id,user_id,otp_code,purpose,expires_at,NOW() AS db_now FROM otps WHERE user_id=? AND purpose='admin_login' ORDER BY id DESC LIMIT 1");
        $stmt->execute([$admin_pending_id]);
        $o = $stmt->fetch(PDO::FETCH_ASSOC);

        $dbCode = isset($o['otp_code']) ? trim((string)$o['otp_code']) : '';

        if ($o && $dbCode === $otp_posted && strtotime($o['expires_at']) > time()) {
            $u = $pdo->prepare("SELECT * FROM users WHERE id=? LIMIT 1");
            $u->execute([$admin_pending_id]);
            $user = $u->fetch();

            if ($user && $user['role'] === 'admin') {
                $_SESSION['user'] = $user;
                $_SESSION['is_admin_verified'] = true;
                $pdo->prepare("DELETE FROM otps WHERE user_id=? AND purpose='admin_login'")->execute([$admin_pending_id]);
                unset($_SESSION['admin_pending_user_id'], $_SESSION['admin_otp_sent']);
                header('Location: /admin/dashboard.php');
                exit;
            } else {
                $error = 'Invalid session. Please log in again.';
            }
        } else {
            // Debug log for failed admin OTP verification (safe to keep temporarily)
            error_log("VERIFY_OTP DEBUG admin_posted='{$otp_posted}' db_row=" . json_encode($o) . " server_time=" . date('Y-m-d H:i:s'));
            $error = 'Invalid or expired code.';
        }
    } elseif ($pending_user_id) {
        $stmt = $pdo->prepare("SELECT id,user_id,otp_code,purpose,expires_at FROM otps WHERE user_id=? AND purpose='email_verify' ORDER BY id DESC LIMIT 1");
        $stmt->execute([$pending_user_id]);
        $o = $stmt->fetch(PDO::FETCH_ASSOC);

        $dbCode = isset($o['otp_code']) ? trim((string)$o['otp_code']) : '';

        if ($o && $dbCode === $otp_posted && strtotime($o['expires_at']) > time()) {
            $pdo->prepare("UPDATE users SET is_verified=1 WHERE id=?")->execute([$pending_user_id]);
            $pdo->prepare("DELETE FROM otps WHERE user_id=? AND purpose='email_verify'")->execute([$pending_user_id]);
            $user = $pdo->prepare("SELECT * FROM users WHERE id=? LIMIT 1");
            $user->execute([$pending_user_id]);
            $_SESSION['user'] = $user->fetch();
            unset($_SESSION['pending_verify_user_id']);
            header('Location: /user/dashboard.php');
            exit;
        } else {
            error_log("VERIFY_OTP DEBUG email_posted='{$otp_posted}' db_row=" . json_encode($o) . " server_time=" . date('Y-m-d H:i:s'));
            $error = 'Invalid or expired OTP.';
        }
    } elseif ($reset_user_id) {
        $stmt = $pdo->prepare("SELECT id,user_id,otp_code,purpose,expires_at FROM otps WHERE user_id=? AND purpose='password_reset' ORDER BY id DESC LIMIT 1");
        $stmt->execute([$reset_user_id]);
        $o = $stmt->fetch(PDO::FETCH_ASSOC);

        $dbCode = isset($o['otp_code']) ? trim((string)$o['otp_code']) : '';

        if ($o && $dbCode === $otp_posted && strtotime($o['expires_at']) > time()) {
            header('Location: /auth/reset_password_otp.php');
            exit;
        } else {
            error_log("VERIFY_OTP DEBUG reset_posted='{$otp_posted}' db_row=" . json_encode($o) . " server_time=" . date('Y-m-d H:i:s'));
            $error = 'Invalid or expired OTP.';
        }
    }
}
?>
<!DOCTYPE html>
<html>
<head>
  <?php $meta_title='Verify OTP • ZAG DEBATE'; include __DIR__ . '/../seo/meta.php'; ?>
  <link rel="stylesheet" href="/assets/css/style.css">
  <style>.verify-card { max-width:420px; margin:20px auto; padding:18px; }</style>
</head>
<body>
<?php include __DIR__ . '/../includes/header.php'; ?>
<div class="container">
  <div class="card verify-card">
    <h2>Enter verification code</h2>
    <?php if (!empty($error)): ?>
      <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <form method="post" novalidate>
      <label class="label" for="otp">6-digit code</label>
      <input id="otp" class="input" type="text" name="otp" maxlength="6" inputmode="numeric" required placeholder="123456" style="letter-spacing:4px;">
      <button class="btn" type="submit" style="margin-top:12px">Verify</button>
    </form>
  </div>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>
