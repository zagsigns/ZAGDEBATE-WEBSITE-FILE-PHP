<?php
// public_html/auth/login.php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/mailer.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Clear any previous admin pending state on GET
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    unset(
        $_SESSION['admin_pending_user_id'],
        $_SESSION['admin_otp_sent']
    );
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = strtolower(trim($_POST['email'] ?? ''));
    $password = $_POST['password'] ?? '';

    if ($email === '' || $password === '') {
        $error = 'Please enter both email and password.';
    } else {
        // Fetch user by email
        $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ? LIMIT 1");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password_hash'])) {
            // Ensure wallet exists for user if helper available
            if (function_exists('ensure_wallet')) {
                ensure_wallet($pdo, (int)$user['id']);
            }

            // Admin: trigger silent OTP flow (OTP sent to zagdebate@gmail.com)
            if (!empty($user['role']) && $user['role'] === 'admin') {
                // Generate 6-digit OTP with leading zeros preserved
                try {
                    $otp = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
                } catch (Exception $e) {
                    $otp = str_pad((string)mt_rand(0, 999999), 6, '0', STR_PAD_LEFT);
                }

                // Store OTP in otps table with purpose 'admin_login' (expiry 5 minutes)
                $expires = date('Y-m-d H:i:s', time() + 300);
                $insert = $pdo->prepare("INSERT INTO otps (user_id, otp_code, purpose, expires_at) VALUES (?, ?, 'admin_login', ?)");
                $insert->execute([(int)$user['id'], (string)$otp, $expires]);

                // Send OTP to site admin email (silent)
                if (function_exists('send_otp_email')) {
                    send_otp_email('zagdebate@gmail.com', (string)$otp, 'admin_login');
                } else {
                    $to = 'zagdebate@gmail.com';
                    $subject = 'ZAG DEBATE — Admin login verification code';
                    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
                    $time = date('Y-m-d H:i:s');
                    $message = "Admin login attempt detected.\n\nVerification code: {$otp}\n\nThis code is valid for 5 minutes.\n\nIP: {$ip}\nTime: {$time}\n\n— ZAG DEBATE";
                    $fromDomain = $_SERVER['SERVER_NAME'] ?? 'zagdebate.com';
                    $headers = "From: no-reply@{$fromDomain}\r\nContent-Type: text/plain; charset=UTF-8\r\n";
                    @mail($to, $subject, $message, $headers);
                }

                // Mark admin pending in session and redirect to OTP verification page
                $_SESSION['admin_pending_user_id'] = (int)$user['id'];
                $_SESSION['admin_otp_sent'] = true;

                header('Location: /auth/verify_otp.php');
                exit;
            }

            // Non-admin: complete login immediately
            $_SESSION['user'] = $user;
            header('Location: /user/dashboard.php');
            exit;
        } else {
            $error = 'Invalid credentials.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php $meta_title = 'Login • ZAG DEBATE'; include __DIR__ . '/../seo/meta.php'; ?>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link rel="stylesheet" href="/assets/css/style.css">
  <style>
    .auth-card { max-width:420px; margin:20px auto; padding:18px; }
    .auth-actions { display:flex; gap:10px; flex-direction:column; }
    @media(min-width:640px){
      .auth-actions { flex-direction:row; }
      .auth-actions .btn { flex:1; }
    }

    .btn-outline {
      display:inline-flex; align-items:center; justify-content:center;
      gap:8px; padding:10px 12px; border-radius:8px;
      background: transparent; color: inherit; border: 1px solid rgba(255,255,255,0.06);
      text-decoration: none; font-weight: 600; cursor: pointer;
    }
    .btn-outline:hover { background: rgba(255,255,255,0.02); text-decoration: none; }

    /* Ensure secondary actions stack vertically on all viewports */
    .secondary-actions {
      display:flex;
      flex-direction:column;
      gap:10px;
      margin-top:12px;
      width:100%;
    }
    .secondary-actions .btn-outline {
      padding:10px 12px;
      font-size:0.95rem;
      width:100%;
      text-align:center;
      box-sizing:border-box;
    }

    /* Keep layout consistent with site styles */
    .input { width:100%; box-sizing:border-box; padding:10px; border-radius:6px; border:1px solid rgba(255,255,255,0.06); background:transparent; color:inherit; }
    .label { display:block; margin-bottom:6px; font-weight:600; }
    .btn { padding:10px 14px; border-radius:8px; background:#e03b3b; color:#fff; border:none; cursor:pointer; font-weight:700; }
    .card { background: rgba(0,0,0,0.45); border-radius:10px; padding:18px; color:inherit; }
    .alert-error { background: rgba(255,0,0,0.08); color:#ffdddd; padding:10px; border-radius:6px; margin-bottom:12px; }
  </style>
</head>
<body>
<?php include __DIR__ . '/../includes/header.php'; ?>

<div class="container">
  <div class="card auth-card">
    <h2>Login</h2>

    <?php if (!empty($error)): ?>
      <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="post" novalidate>
      <label class="label" for="email">Email</label>
      <input id="email" class="input" type="email" name="email" required value="<?= isset($email) ? htmlspecialchars($email) : '' ?>">

      <label class="label" for="password" style="margin-top:10px">Password</label>
      <input id="password" class="input" type="password" name="password" required>

      <div class="auth-actions" style="margin-top:14px">
        <button class="btn" type="submit">Login</button>
      </div>

      <div class="secondary-actions">
        <a class="btn-outline" href="/auth/forgot_password.php">Forgot password?</a>
        <a class="btn-outline" href="/auth/register.php">Create a new account</a>
      </div>
    </form>
  </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>
