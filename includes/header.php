<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$isLoggedIn = isset($_SESSION['user_id']);
$isAdmin = isset($_SESSION['user_role']) && strtolower((string) $_SESSION['user_role']) === 'admin';
$userName = $isLoggedIn ? $_SESSION['user_name'] : '';
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Unibo Mobility</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="CSS/theme.css">
    <link rel="stylesheet" href="CSS/style.css">
</head>
<body>
<nav class="navbar navbar-expand-lg navbar-light bg-white shadow-sm sticky-top">
    <div class="container">
        <a class="navbar-brand fw-bold d-flex align-items-center" href="index.php">
            <div class="bg-unibo text-white rounded-2 d-flex justify-content-center align-items-center me-2" style="width: 32px; height: 32px;">U</div>
            <span style="color: #2D2D2D;">Unibo Mobility</span>
        </a>
        <div class="ms-auto d-flex align-items-center gap-2">
            <?php if ($isLoggedIn): ?>
                <span class="small text-muted d-none d-md-inline">Hi, <?php echo htmlspecialchars($userName); ?></span>
                <?php if (!$isAdmin): ?>
                    <a href="wallet.php" class="btn btn-outline-secondary btn-sm">Wallet</a>
                <?php endif; ?>
                <a href="profile.php" class="btn btn-light rounded-circle"><i class="fas fa-user"></i></a>
                <?php if ($isAdmin): ?>
                    <a href="admin.php" class="btn btn-unibo btn-sm">Admin Panel</a>
                <?php endif; ?>
                <a href="logout.php" class="btn btn-outline-secondary btn-sm">Logout</a>
            <?php else: ?>
                <a href="login.php" class="btn btn-outline-secondary btn-sm">Login</a>
                <a href="register.php" class="btn btn-unibo btn-sm">Sign Up</a>
            <?php endif; ?>
        </div>
    </div>
</nav>
