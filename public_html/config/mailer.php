<?php
function send_email_smtp($to_email, $subject, $body_html) {
  // Gmail SMTP
  $smtp_host = 'smtp.gmail.com';
  $smtp_port = 587;
  $smtp_user = 'zagdebate@gmail.com';
  $smtp_pass = 'hmfq bnwt lxdr zqpg'; // Gmail app password
  $from_name = 'ZAG DEBATE';

  $context = stream_context_create([
    'ssl' => ['verify_peer' => true, 'verify_peer_name' => true]
  ]);
  $fp = stream_socket_client("tcp://$smtp_host:$smtp_port", $errno, $errstr, 30, STREAM_CLIENT_CONNECT, $context);
  if (!$fp) return false;

  $read = function() use ($fp) { return fgets($fp, 515); };
  $write = function($cmd) use ($fp) { fwrite($fp, $cmd . "\r\n"); };

  $read();
  $write("EHLO zagdebate.com");
  while (($line = $read()) && substr($line, 3, 1) !== ' ') {}

  $write("STARTTLS");
  $read();

  // Enable crypto
  stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);

  $write("EHLO zagdebate.com");
  while (($line = $read()) && substr($line, 3, 1) !== ' ') {}

  $write("AUTH LOGIN");
  $read();
  $write(base64_encode($smtp_user));
  $read();
  $write(base64_encode($smtp_pass));
  $read();

  $write("MAIL FROM:<$smtp_user>");
  $read();
  $write("RCPT TO:<$to_email>");
  $read();
  $write("DATA");
  $read();

  $headers = "From: $from_name <$smtp_user>\r\n";
  $headers .= "Reply-To: $smtp_user\r\n";
  $headers .= "MIME-Version: 1.0\r\n";
  $headers .= "Content-Type: text/html; charset=UTF-8\r\n";

  $message = $headers . "\r\n" . $body_html . "\r\n.";
  fwrite($fp, $message . "\r\n");
  $write(".");
  $read();

  $write("QUIT");
  fclose($fp);
  return true;
}

function send_otp_email($to_email, $otp, $purpose='verification') {
  $subject = ($purpose==='password_reset') ? 'Your Password Reset OTP' : 'Verify Your Email - ZAG DEBATE';
  $body = '<div style="font-family:Arial;padding:20px;background:#0b0f17;color:#fff">
    <h2 style="margin:0 0 10px">ZAG DEBATE</h2>
    <p>Your OTP is:</p>
    <div style="font-size:28px;letter-spacing:4px;background:#111826;border:1px solid #222;padding:12px;display:inline-block">'
    . htmlspecialchars($otp) . '</div>
    <p style="margin-top:10px">This OTP expires in 10 minutes.</p>
    <p style="color:#f33">Do not share this code.</p>
  </div>';
  return send_email_smtp($to_email, $subject, $body);
}
