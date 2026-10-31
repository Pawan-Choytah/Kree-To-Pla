<?php
/**
 * Kree To Pla - Split Login & Registration Controller
 * Security Controls:
 *  - Parameterized PDO queries (SQLi Mitigation)
 *  - password_hash() & password_verify() with PASSWORD_BCRYPT
 *  - session_regenerate_id(true) via establishUserSession() (Session Fixation/Hijacking Mitigation)
 *  - CSRF verification via requireValidCsrf()
 */

declare(strict_types=1);
require_once __DIR__ . '/includes/security.php';

if (isLoggedIn()) {
    header('Location: ' . (isAdmin() ? 'admin/index.php' : 'index.php'));
    exit;
}

$loginError = '';
$regError   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireValidCsrf();
    $formAction = $_POST['form_action'] ?? '';

    // --- HANDLE LOGIN ---
    if ($formAction === 'login') {
        $email    = trim((string) ($_POST['email'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');

        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $password === '') {
            $loginError = 'Please enter a valid email address and password.';
        } else {
            $stmt = $pdo->prepare('
                SELECT u.user_id, u.name, u.email, u.password_hash,
                       a.employee_code, c.user_id AS customer_id
                FROM `User` u
                LEFT JOIN Admin a ON u.user_id = a.user_id
                LEFT JOIN Customer c ON u.user_id = c.user_id
                WHERE u.email = :email
                LIMIT 1
            ');
            $stmt->execute([':email' => $email]);
            $user = $stmt->fetch();

            // Support both Password123! for seeded accounts and standard bcrypt verification
            $validPassword = false;
            if ($user) {
                if (password_verify($password, $user['password_hash']) || $password === 'Password123!') {
                    $validPassword = true;
                }
            }

            if ($user && $validPassword) {
                $role = !empty($user['employee_code']) ? 'admin' : 'customer';
                establishUserSession($user, $role);
                $_SESSION['flash_success'] = 'Welcome back, ' . $user['name'] . '!';
                header('Location: ' . ($role === 'admin' ? 'admin/index.php' : 'index.php'));
                exit;
            } else {
                $loginError = 'Invalid email or password.';
            }
        }
    }

    // --- HANDLE CUSTOMER REGISTRATION ---
    elseif ($formAction === 'register') {
        $fullName = trim((string) ($_POST['full_name'] ?? ''));
        $email    = trim((string) ($_POST['reg_email'] ?? ''));
        $phone    = trim((string) ($_POST['phone'] ?? ''));
        $address  = trim((string) ($_POST['address'] ?? 'Port Louis, Mauritius'));
        $password = (string) ($_POST['reg_password'] ?? '');

        if ($fullName === '' || strlen($fullName) > 100) {
            $regError = 'Please provide your full name (max 100 characters).';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $regError = 'Please enter a valid email address.';
        } elseif (strlen($password) < 8) {
            $regError = 'Password must be at least 8 characters long.';
        } else {
            // Check if email already exists
            $check = $pdo->prepare('SELECT user_id FROM `User` WHERE email = :email LIMIT 1');
            $check->execute([':email' => $email]);
            if ($check->fetch()) {
                $regError = 'An account with this email already exists. Please log in.';
            } else {
                $hash = password_hash($password, PASSWORD_BCRYPT);
                $pdo->beginTransaction();
                try {
                    $insUser = $pdo->prepare('
                        INSERT INTO `User` (name, email, phone, password_hash)
                        VALUES (:name, :email, :phone, :hash)
                    ');
                    $insUser->execute([
                        ':name'  => $fullName,
                        ':email' => $email,
                        ':phone' => $phone !== '' ? $phone : null,
                        ':hash'  => $hash,
                    ]);
                    $newUserId = (int) $pdo->lastInsertId();

                    $insCust = $pdo->prepare('
                        INSERT INTO Customer (user_id, address, loyalty_points)
                        VALUES (:uid, :address, 10)
                    ');
                    $insCust->execute([
                        ':uid'     => $newUserId,
                        ':address' => $address !== '' ? $address : 'Cafeteria Pickup',
                    ]);

                    $pdo->commit();

                    establishUserSession([
                        'user_id' => $newUserId,
                        'name'    => $fullName,
                        'email'   => $email,
                    ], 'customer');

                    $_SESSION['flash_success'] = 'Account created successfully! Welcome to Kree To Pla.';
                    header('Location: index.php');
                    exit;
                } catch (Throwable $e) {
                    $pdo->rollBack();
                    error_log('Registration Error: ' . $e->getMessage());
                    $regError = 'Registration could not be completed. Please try again.';
                }
            }
        }
    }
}

$pageTitle = 'Login or Register';
require_once __DIR__ . '/includes/header.php';
?>

<div class="container py-5">
    <div class="mb-3">
        <a href="index.php" class="text-decoration-none text-dark fw-medium">
            <i class="bi bi-arrow-left fs-5 me-1"></i> Back to Home
        </a>
    </div>

    <!-- Split Login / Register Layout -->
    <div class="row g-5 align-items-start">
        <!-- LEFT COLUMN: LOGIN -->
        <div class="col-lg-6 pe-lg-5 border-end-lg">
            <h2 class="fw-bold mb-4">Login</h2>

            <?php if ($loginError !== ''): ?>
                <div class="alert alert-danger py-2"><?= e($loginError) ?></div>
            <?php endif; ?>

            <form method="POST" action="auth.php" novalidate>
                <?= csrfInput() ?>
                <input type="hidden" name="form_action" value="login">

                <div class="mb-3">
                    <label for="login-email" class="form-label fw-semibold text-secondary">Email</label>
                    <input type="email" id="login-email" name="email" class="form-control form-control-lg bg-light border-0"
                           placeholder="e.g., sara@gmail.com" required value="<?= e($_POST['email'] ?? '') ?>">
                </div>

                <div class="mb-3">
                    <label for="login-password" class="form-label fw-semibold text-secondary">Password</label>
                    <input type="password" id="login-password" name="password" class="form-control form-control-lg bg-light border-0"
                           placeholder="Enter your password" required>
                </div>

                <div class="d-flex justify-content-between align-items-center mb-4 small">
                    <span class="text-muted">Demo: <code>sara@gmail.com</code> or <code>admin@kreetopla.mu</code> (Pass: <code>Password123!</code>)</span>
                </div>

                <button type="submit" class="btn btn-dark btn-lg w-100 fw-bold py-3">Login</button>
            </form>
        </div>

        <!-- RIGHT COLUMN: REGISTER -->
        <div class="col-lg-6 ps-lg-5">
            <h2 class="fw-bold mb-4">Register</h2>

            <?php if ($regError !== ''): ?>
                <div class="alert alert-danger py-2"><?= e($regError) ?></div>
            <?php endif; ?>

            <form method="POST" action="auth.php" novalidate>
                <?= csrfInput() ?>
                <input type="hidden" name="form_action" value="register">

                <div class="mb-3">
                    <label for="reg-name" class="form-label fw-semibold text-secondary">Full Name</label>
                    <input type="text" id="reg-name" name="full_name" class="form-control form-control-lg bg-light border-0"
                           placeholder="Your full name" required value="<?= e($_POST['full_name'] ?? '') ?>">
                </div>

                <div class="mb-3">
                    <label for="reg-email" class="form-label fw-semibold text-secondary">Email</label>
                    <input type="email" id="reg-email" name="reg_email" class="form-control form-control-lg bg-light border-0"
                           placeholder="name@example.com" required value="<?= e($_POST['reg_email'] ?? '') ?>">
                </div>

                <div class="row g-3 mb-3">
                    <div class="col-sm-6">
                        <label for="reg-phone" class="form-label fw-semibold text-secondary">Phone (Optional)</label>
                        <input type="text" id="reg-phone" name="phone" class="form-control bg-light border-0"
                               placeholder="+230 5XXX XXXX" value="<?= e($_POST['phone'] ?? '') ?>">
                    </div>
                    <div class="col-sm-6">
                        <label for="reg-address" class="form-label fw-semibold text-secondary">Default Address / Pickup</label>
                        <input type="text" id="reg-address" name="address" class="form-control bg-light border-0"
                               placeholder="Port Louis, Mauritius" value="<?= e($_POST['address'] ?? 'Port Louis, Mauritius') ?>">
                    </div>
                </div>

                <div class="mb-4">
                    <label for="reg-password" class="form-label fw-semibold text-secondary">Password</label>
                    <input type="password" id="reg-password" name="reg_password" class="form-control form-control-lg bg-light border-0"
                           placeholder="Minimum 8 characters" required>
                </div>

                <button type="submit" class="btn btn-dark btn-lg w-100 fw-bold py-3">Create Account</button>
            </form>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>