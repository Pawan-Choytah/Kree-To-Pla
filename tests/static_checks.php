<?php
/**
 * Kree To Pla - Automated Static & Health Checks Test Suite
 * Verifies database connectivity, schema integrity, and core security modules.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

$results = [];

function recordTest(string $category, string $testName, bool $passed, string $details): void
{
    global $results;
    $results[] = [
        'category' => $category,
        'name'     => $testName,
        'passed'   => $passed,
        'details'  => $details,
    ];
}

// ==========================================
// 1. FILE & ENVIRONMENT CHECKS
// ==========================================
$requiredFiles = [
    'config/database.php'  => 'Database Configuration & Connection',
    'includes/security.php' => 'Security Core (CSRF, XSS, Session Hardening)',
    'includes/header.php'   => 'Header Layout & Flash Messages',
    'includes/footer.php'   => 'Footer Layout & Client Assets',
    'process_action.php'    => 'Form Action Handler (POST + Redirect)',
    'sql/kree_to_pla_db.sql'=> 'Database Migration & Schema Source',
];

foreach ($requiredFiles as $relPath => $desc) {
    $fullPath = dirname(__DIR__) . '/' . $relPath;
    $exists = file_exists($fullPath);
    recordTest('Filesystem', "File: $relPath", $exists, $exists ? "$desc found." : "Missing $relPath!");
}

// ==========================================
// 2. DATABASE CONNECTIVITY & EMULATION CHECK
// ==========================================
try {
    $dbName = $pdo->query('SELECT DATABASE()')->fetchColumn();
    $passed = ($dbName === 'kree_to_pla_db');
    recordTest('Database Connection', 'Active Database', $passed, "Connected to '$dbName'.");

    // Verify emulation is disabled (Strict Prepared Statements for SQLi Defence)
    $emulate = $pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES);
    recordTest('Security Controls', 'Native Prepared Statements (SQLi)', !$emulate, !$emulate ? 'PDO::ATTR_EMULATE_PREPARES is FALSE (Secure).' : 'Emulation is active (Risk).');
} catch (Throwable $e) {
    recordTest('Database Connection', 'PDO Connection Test', false, $e->getMessage());
}

// ==========================================
// 3. TABLE INTEGRITY CHECKS (ALL 15 TABLES)
// ==========================================
$expectedTables = [
    'User', 'Admin', 'Customer', 'Menu', 'Dish',
    'Ingredient', 'Menu_item', 'Dish_ingredient',
    'Custom_dish', 'Custom_dish_ingredient', 'Order',
    'Order_item', 'Payment', 'Review', 'Favourite'
];

try {
    $existingTables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    $existingLower  = array_map('strtolower', $existingTables);

    foreach ($expectedTables as $table) {
        $found = in_array(strtolower($table), $existingLower, true);
        recordTest('Database Schema', "Table: `$table`", $found, $found ? "Table exists in kree_to_pla_db." : "Missing table `$table`!");
    }
} catch (Throwable $e) {
    recordTest('Database Schema', 'Schema Inspection', false, $e->getMessage());
}

// ==========================================
// 4. SEED DATA & RECORD VERIFICATION
// ==========================================
try {
    $dishesCount = (int) $pdo->query('SELECT COUNT(*) FROM Dish')->fetchColumn();
    recordTest('Data Integrity', 'Dishes Seeded', $dishesCount >= 10, "$dishesCount dishes available in catalogue.");

    $ingredientsCount = (int) $pdo->query('SELECT COUNT(*) FROM Ingredient')->fetchColumn();
    recordTest('Data Integrity', 'Ingredients Seeded', $ingredientsCount >= 20, "$ingredientsCount ingredients available.");

    $adminCount = (int) $pdo->query('SELECT COUNT(*) FROM Admin')->fetchColumn();
    recordTest('Data Integrity', 'Admin Account Configured', $adminCount >= 1, "$adminCount administrator profile(s) found.");
} catch (Throwable $e) {
    recordTest('Data Integrity', 'Seed Query Checks', false, $e->getMessage());
}

// Calculate Summary Statistics
$totalTests = count($results);
$passedTests = count(array_filter($results, fn($r) => $r['passed']));
$failedTests = $totalTests - $passedTests;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Health &amp; Static Checks | Kree To Pla</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; background-color: #f8f9fa; }
        .test-card { border-radius: 12px; border: 1px solid #e4e4e7; background: #fff; }
    </style>
</head>
<body class="py-5">
<div class="container" style="max-width: 900px;">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h2 class="fw-extrabold mb-1">System Health &amp; Static Checks</h2>
            <p class="text-secondary mb-0">Automated Integrity Test Suite &bull; ICT 2213Y</p>
        </div>
        <a href="../index.php" class="btn btn-outline-dark btn-sm">
            <i class="bi bi-arrow-left me-1"></i> Back to Site
        </a>
    </div>

    <!-- Overall Summary Card -->
    <div class="test-card p-4 mb-4 shadow-sm">
        <div class="row text-center g-3">
            <div class="col-4">
                <div class="text-muted small">Total Checks</div>
                <div class="display-6 fw-bold"><?= $totalTests ?></div>
            </div>
            <div class="col-4">
                <div class="text-muted small">Passed</div>
                <div class="display-6 fw-bold text-success"><?= $passedTests ?></div>
            </div>
            <div class="col-4">
                <div class="text-muted small">Failed</div>
                <div class="display-6 fw-bold <?= $failedTests === 0 ? 'text-secondary' : 'text-danger' ?>">
                    <?= $failedTests ?>
                </div>
            </div>
        </div>
        <?php if ($failedTests === 0): ?>
            <div class="alert alert-success d-flex align-items-center mb-0 mt-3 py-2 small" role="alert">
                <i class="bi bi-check-circle-fill fs-5 me-2"></i>
                <div>All system components, database tables, and security settings are verified.</div>
            </div>
        <?php endif; ?>
    </div>

    <!-- Detailed Results Table -->
    <div class="test-card p-4 shadow-sm">
        <table class="table align-middle mb-0">
            <thead>
                <tr>
                    <th style="width: 25%;">Category</th>
                    <th style="width: 35%;">Test Assertion</th>
                    <th style="width: 15%;">Status</th>
                    <th style="width: 25%;">Details</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($results as $r): ?>
                    <tr>
                        <td class="small fw-semibold text-secondary"><?= htmlspecialchars($r['category']) ?></td>
                        <td class="fw-bold small"><?= htmlspecialchars($r['name']) ?></td>
                        <td>
                            <?php if ($r['passed']): ?>
                                <span class="badge bg-success-subtle text-success border border-success-subtle px-2 py-1">
                                    <i class="bi bi-check2"></i> PASS
                                </span>
                            <?php else: ?>
                                <span class="badge bg-danger-subtle text-danger border border-danger-subtle px-2 py-1">
                                    <i class="bi bi-x"></i> FAIL
                                </span>
                            <?php endif; ?>
                        </td>
                        <td class="small text-muted"><?= htmlspecialchars($r['details']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
</body>
</html>