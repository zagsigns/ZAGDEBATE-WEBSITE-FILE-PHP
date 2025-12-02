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

// Read JSON body
$data = json_decode(file_get_contents('php://input'), true);

$paymentId = $data['razorpay_payment_id'] ?? '';
$orderId   = $data['razorpay_order_id'] ?? '';
$signature = $data['razorpay_signature'] ?? '';

if (!$paymentId || !$orderId || !$signature) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid payload']);
    exit;
}

// Verify signature: hmac_sha256(order_id|payment_id, key_secret)
$expected = hash_hmac('sha256', $orderId . '|' . $paymentId, RAZORPAY_KEY_SECRET);
if (!hash_equals($expected, $signature)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Signature mismatch']);
    exit;
}

try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare("SELECT id, user_id, credits, status 
                           FROM transactions 
                           WHERE razorpay_order_id=? FOR UPDATE");
    $stmt->execute([$orderId]);
    $txn = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$txn) {
        $pdo->rollBack();
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Transaction not found']);
        exit;
    }
    if ($txn['status'] !== 'created') {
        $pdo->rollBack();
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Transaction already processed']);
        exit;
    }

    // Mark transaction as paid
    $pdo->prepare("UPDATE transactions 
                   SET razorpay_payment_id=?, status='paid', updated_at=NOW() 
                   WHERE id=?")
        ->execute([$paymentId, $txn['id']]);

    // Ensure wallet exists for this user
    if (function_exists('ensure_wallet')) {
        ensure_wallet($pdo, (int)$txn['user_id']);
    }

    // Credit wallet instead of users
    $pdo->prepare("UPDATE wallets SET credits = credits + ? WHERE user_id=?")
        ->execute([(int)$txn['credits'], (int)$txn['user_id']]);

    $pdo->commit();

    echo json_encode([
        'success' => true,
        'message' => "Purchased {$txn['credits']} credits successfully.",
        'credits_added' => (int)$txn['credits'],
        'transaction_id' => $txn['id'],
        'user_id' => (int)$txn['user_id']
    ]);
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}
