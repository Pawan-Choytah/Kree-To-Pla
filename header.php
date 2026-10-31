<?php
require_once __DIR__ . '/security.php';
$currentPage = basename($_SERVER['PHP_SELF']);
$cartCount   = getCartCount();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= isset($pageTitle) ? e($pageTitle) . ' | Kree To Pla' : 'Kree To Pla – Online Cafeteria Ordering' ?></title>
    <!-- Google Fonts: Inter / Poppins -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <!-- Bootstrap 5.3 CSS & Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <!-- Custom Stylesheet -->
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="d-flex flex-column min-vh-100">

<!-- Top Navigation Bar -->
<nav class="navbar navbar-expand-lg kp-navbar sticky-top bg-white border-bottom py-3">
    <div class="container">
        <a class="navbar-brand d-flex align-items-center gap-2 fw-bold fs-4 text-dark" href="index.php">
            <img src="assets/images/logo.svg" alt="Kree To Pla Logo" class="kp-logo-img">
            <span>Kree To Pla</span>
        </a>

        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#kpNavbar">
            <span class="navbar-toggler-icon"></span>
        </button>

        <div class="collapse navbar-collapse" id="kpNavbar">
            <ul class="navbar-nav mx-auto mb-2 mb-lg-0 gap-lg-3">
                <li class="nav-item">
                    <a class="nav-link <?= $currentPage === 'index.php' ? 'active fw-semibold' : '' ?>" href="index.php">Home</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= $currentPage === 'menu.php' ? 'active fw-semibold' : '' ?>" href="menu.php">Menu</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= $currentPage === 'find_food.php' ? 'active fw-semibold' : '' ?>" href="find_food.php">Find Food</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= $currentPage === 'build_dish.php' ? 'active fw-semibold' : '' ?>" href="build_dish.php">Build a Dish</a>
                </li>
                <?php if (isLoggedIn()): ?>
                    <li class="nav-item">
                        <a class="nav-link <?= $currentPage === 'orders.php' ? 'active fw-semibold' : '' ?>" href="orders.php">My Orders</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?= $currentPage === 'favourites.php' ? 'active fw-semibold' : '' ?>" href="favourites.php">Favourites</a>
                    </li>
                <?php endif; ?>
                <?php if (isAdmin()): ?>
                    <li class="nav-item">
                        <a class="nav-link text-danger fw-bold" href="admin/index.php"><i class="bi bi-speedometer2 me-1"></i>Admin Panel</a>
                    </li>
                <?php endif; ?>
            </ul>

            <div class="d-flex align-items-center gap-3">
                <!-- Cart Icon with Live Badge -->
                <a href="cart.php" class="btn btn-light position-relative rounded-circle p-2 border" title="View Order Cart">
                    <i class="bi bi-cart3 fs-5"></i>
                    <span id="kp-cart-badge" class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-dark">
                        <?= (int) $cartCount ?>
                    </span>
                </a>

                <?php if (isLoggedIn()): ?>
                    <div class="dropdown">
                        <button class="btn btn-dark rounded-pill px-3 py-2 dropdown-toggle d-flex align-items-center gap-2" type="button" data-bs-toggle="dropdown">
                            <i class="bi bi-person-circle"></i>
                            <span><?= e($_SESSION['user_name']) ?></span>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                            <li class="dropdown-header small text-muted"><?= e($_SESSION['user_email']) ?></li>
                            <?php if (isAdmin()): ?>
                                <li><a class="dropdown-item fw-semibold" href="admin/index.php"><i class="bi bi-grid-1x2 me-2"></i>Admin Dashboard</a></li>
                            <?php endif; ?>
                            <li><a class="dropdown-item" href="orders.php"><i class="bi bi-bag-check me-2"></i>My Orders</a></li>
                            <li><a class="dropdown-item" href="favourites.php"><i class="bi bi-heart me-2"></i>My Favourites</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item text-danger" href="logout.php"><i class="bi bi-box-arrow-right me-2"></i>Logout</a></li>
                        </ul>
                    </div>
                <?php else: ?>
                    <a href="auth.php" class="btn btn-dark px-4 py-2 rounded-3 fw-medium">Login</a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</nav>

<!-- Flash Notification Messages -->
<div class="container mt-3">
    <?php if (!empty($_SESSION['flash_success'])): ?>
        <div class="alert alert-success alert-dismissible fade show shadow-sm" role="alert">
            <i class="bi bi-check-circle-fill me-2"></i><?= e($_SESSION['flash_success']) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php unset($_SESSION['flash_success']); ?>
    <?php endif; ?>

    <?php if (!empty($_SESSION['flash_error'])): ?>
        <div class="alert alert-danger alert-dismissible fade show shadow-sm" role="alert">
            <i class="bi bi-exclamation-triangle-fill me-2"></i><?= e($_SESSION['flash_error']) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php unset($_SESSION['flash_error']); ?>
    <?php endif; ?>

</div>

<main class="flex-grow-1">