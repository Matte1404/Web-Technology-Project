<?php
/** @var mysqli $conn */
include '../db/db_config.php';

header('Content-Type: application/json');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

if (isset($_SESSION['user_role']) && strtolower($_SESSION['user_role']) === 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Admins cannot play']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$userId = $_SESSION['user_id'];



$prizes = [0.10, 0.20, 0.50, 1.00, 2.00, 5.00];
$randomIndex = array_rand($prizes);
$amount = $prizes[$randomIndex];

mysqli_begin_transaction($conn);

try {
    $updateStmt = mysqli_prepare($conn, "UPDATE users SET credit = credit + ? WHERE id = ?");
    mysqli_stmt_bind_param($updateStmt, "di", $amount, $userId);
    mysqli_stmt_execute($updateStmt);
    mysqli_stmt_close($updateStmt);

    $balanceStmt = mysqli_prepare($conn, "SELECT credit FROM users WHERE id = ?");
    mysqli_stmt_bind_param($balanceStmt, "i", $userId);
    mysqli_stmt_execute($balanceStmt);
    $result = mysqli_stmt_get_result($balanceStmt);
    $row = mysqli_fetch_assoc($result);
    $newBalance = $row['credit'];
    mysqli_stmt_close($balanceStmt);

    $desc = "Wheel of Fortune Bonus";
    $type = 'topup';
    $logStmt = mysqli_prepare($conn, "INSERT INTO transactions (user_id, type, amount, balance_after, description) VALUES (?, ?, ?, ?, ?)");
    mysqli_stmt_bind_param($logStmt, "isdds", $userId, $type, $amount, $newBalance, $desc);
    mysqli_stmt_execute($logStmt);
    mysqli_stmt_close($logStmt);

    mysqli_commit($conn);

    echo json_encode([
        'success' => true,
        'amount' => $amount,
        'new_balance' => $newBalance,
        'message' => 'You won €' . number_format($amount, 2)
    ]);

} catch (Exception $e) {
    mysqli_rollback($conn);
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error']);
}
