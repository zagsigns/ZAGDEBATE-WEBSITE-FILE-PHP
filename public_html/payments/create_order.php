<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/payments.php';
require_once __DIR__ . '/../config/app.php';

if (session_status() === PHP_SESSION_NONE) { 
    session_start(); 
}
if (empty($_SESSION['user']['id'])) { 
    http_response_code(401); 
    echo json_encode(['success' => false, 'message' => 'Login required']); 
    exit; 
}

header('Content-Type: application/json');

$userId = (int)$_SESSION['user']['id'];

// Read JSON body
$input = json_decode(file_get_contents('php://input'), true);
$creditsToBuy = max(1, (int)($input['credits'] ?? 0));
if ($creditsToBuy < 1) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid credits quantity']);
    exit;
}

// Fetch admin rate (INR per credit)
$settings = $pdo->query("SELECT credit_rate_inr_per_credit 
                         FROM settings 
                         ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$rate = (float)($settings['credit_rate_inr_per_credit'] ?? 10);

// Calculate amount in paise (Razorpay expects integer paise)
$amountInPaise = (int)(($rate * $creditsToBuy) * 100);

// Create Razorpay order payload
$orderData = [
    'amount' => $amountInPaise,
    'currency' => 'INR',
    'receipt' => 'rcpt_' . uniqid(),
    'payment_capture' => 1
];

$ch = curl_init('https://api.razorpay.com/v1/orders');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => http_build_query($orderData),
    CURLOPT_USERPWD => RAZORPAY_KEY_ID . ':' . RAZORPAY_KEY_SECRET
]);
$response = curl_exec($ch);
if ($response === false) { 
    http_response_code(500); 
    echo json_encode([
        'success' => false, 
        'message' => 'Razorpay API error', 
        'error_detail' => curl_error($ch)
    ]); 
    exit; 
}
$data = json_decode($response, true);
curl_close($ch);

if (empty($data['id'])) { 
    http_response_code(500); 
    echo json_encode([
        'success' => false, 
        'message' => 'Order creation failed', 
        'error_detail' => $data
    ]); 
    exit; 
}

// Store transaction in DB
$stmt = $pdo->prepare("INSERT INTO transactions 
    (user_id, razorpay_order_id, amount_inr, credits, status, created_at) 
    VALUES (?,?,?,?, 'created', NOW())");
$stmt->execute([$userId, $data['id'], $amountInPaise/100, $creditsToBuy]);

// Return JSON response to frontend
echo json_encode([
    'success' => true,
    'order_id' => $data['id'],
    'amount' => $amountInPaise,
    'credits' => $creditsToBuy,
    'key' => RAZORPAY_KEY_ID, // only public key exposed
    'receipt' => $orderData['receipt']
]);
