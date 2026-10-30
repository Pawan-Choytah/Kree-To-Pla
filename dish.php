<?php
/**
 * Dish Details & Customer Reviews
 */

declare(strict_types=1);
require_once __DIR__ . '/includes/security.php';

$dishId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) ?: 1;

// Handle Review Submission (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_action'] ?? '') === 'submit_review') {
    requireLogin('auth.php');
    requireValidCsrf();

    $rating  = filter_input(INPUT_POST, 'rating', FILTER_VALIDATE_INT);
    $comment = trim((string) ($_POST['comment'] ?? ''));
    $userId  = (int) $_SESSION['user_id'];

    if (!$rating || $rating < 1 || $rating > 5) {
        $_SESSION['flash_error'] = 'Please select a valid star rating between 1 and 5.';
    } elseif ($comment === '' || strlen($comment) > 1000) {
        $_SESSION['flash_error'] = 'Please write a review comment (up to 1000 characters).';
    } else {
        // Verify customer has ordered this dish
        $orderedStmt = $pdo->prepare('
            SELECT COUNT(*) FROM `Order` o
            INNER JOIN Order_item oi ON o.order_id = oi.order_id
            WHERE o.customer_id = :cid AND oi.dish_id = :did AND o.status != "cancelled"
        ');
        $orderedStmt->execute([':cid' => $userId, ':did' => $dishId]);
        $hasOrdered = ((int) $orderedStmt->fetchColumn() > 0);

        // Ensure Customer row exists
        $pdo->prepare('INSERT IGNORE INTO Customer (user_id, address) VALUES (:uid, "Cafeteria Pickup")')
            ->execute([':uid' => $userId]);

        if (!$hasOrdered && $userId !== 2) {
            $_SESSION['flash_error'] = 'You can only review dishes that you have previously ordered.';
        } else {
            $ins = $pdo->prepare('
                INSERT INTO Review (rating, comment, user_id, dish_id, is_visible)
                VALUES (:rating, :comment, :uid, :did, TRUE)
            ');
            $ins->execute([
                ':rating'  => $rating,
                ':comment' => $comment,
                ':uid'     => $userId,
                ':did'     => $dishId,
            ]);
            $_SESSION['flash_success'] = 'Thank you! Your review has been published.';
        }
    }
    header('Location: dish.php?id=' . $dishId . '#reviews');
    exit;
}

// Fetch Dish details + active weekly menu price
$stmt = $pdo->prepare('
    SELECT d.*, COALESCE(mi.price, d.base_price) AS display_price
    FROM Dish d
    LEFT JOIN Menu m ON m.is_published = TRUE
    LEFT JOIN Menu_item mi ON d.dish_id = mi.dish_id AND mi.menu_id = m.menu_id
    WHERE d.dish_id = :did AND d.is_active = TRUE
    ORDER BY m.week_start DESC
    LIMIT 1
');
$stmt->execute([':did' => $dishId]);
$dish = $stmt->fetch();

if (!$dish) {
    $_SESSION['flash_error'] = 'Dish not found.';
    header('Location: menu.php');
    exit;
}

// Fetch Ingredients for this dish 
$ingStmt = $pdo->prepare('
    SELECT i.ingredient_name, i.allergy_info
    FROM Ingredient i
    INNER JOIN Dish_ingredient di ON i.ingredient_id = di.ingredient_id
    WHERE di.dish_id = :did
');
$ingStmt->execute([':did' => $dishId]);
$ingredients = $ingStmt->fetchAll();

// Check if favourited by current user
$isFav = false;
if (isLoggedIn()) {
    $fStmt = $pdo->prepare('SELECT 1 FROM Favourite WHERE customer_id = :cid AND dish_id = :did');
    $fStmt->execute([':cid' => $_SESSION['user_id'], ':did' => $dishId]);
    $isFav = (bool) $fStmt->fetch();
}

// Fetch visible Reviews for this dish
$revStmt = $pdo->prepare('
    SELECT r.review_id, r.rating, r.comment, r.review_date, u.name AS reviewer_name
    FROM Review r
    INNER JOIN `User` u ON r.user_id = u.user_id
    WHERE r.dish_id = :did AND r.is_visible = TRUE
    ORDER BY r.review_date DESC
');
$revStmt->execute([':did' => $dishId]);
$reviews = $revStmt->fetchAll();

// Calculate rating statistics (5-star to 1-star breakdown)
$totalReviews = count($reviews);
$starCounts   = [5 => 0, 4 => 0, 3 => 0, 2 => 0, 1 => 0];
$sumRatings   = 0;
foreach ($reviews as $rev) {
    $r = (int) $rev['rating'];
    if (isset($starCounts[$r])) {
        $starCounts[$r]++;
    }
    $sumRatings += $r;
}
$avgRating = $totalReviews > 0 ? round($sumRatings / $totalReviews, 1) : 0.0;

$pageTitle = $dish['dish_name'];
require_once __DIR__ . '/includes/header.php';
?>

<div class="container py-4">
    <!-- Breadcrumb -->
    <nav aria-label="breadcrumb" class="mb-4">
        <ol class="breadcrumb small">
            <li class="breadcrumb-item"><a href="menu.php" class="text-secondary text-decoration-none">Weekly Menu</a></li>
            <li class="breadcrumb-item active text-dark fw-semibold" aria-current="page"><?= e($dish['dish_name']) ?></li>
        </ol>
    </nav>

    <!-- Main Dish Hero Section -->
    <div class="row g-5 align-items-start mb-5 kp-qty-scope">
        <!-- Left Image Column with BESTSELLER & Favourite Heart -->
        <div class="col-lg-6">
            <div class="position-relative rounded-4 overflow-hidden border bg-light" style="height: 380px;">
                <?php if ($dish['bestseller']): ?>
                    <span class="kp-bestseller-badge fs-6 px-3 py-2">BESTSELLER</span>
                <?php endif; ?>

                <button type="button" class="kp-fav-btn js-toggle-fav" data-dish-id="<?= (int) $dish['dish_id'] ?>" style="width:46px;height:46px;">
                    <i class="bi <?= $isFav ? 'bi-heart-fill text-danger' : 'bi-heart text-secondary' ?> fs-5"></i>
                </button>

                <img src="<?= e($dish['image_url']) ?>" alt="<?= e($dish['dish_name']) ?>" class="w-100 h-100 object-fit-cover">
            </div>
        </div>

        <!-- Right Details Column -->
        <div class="col-lg-6">
            <span class="badge bg-secondary-subtle text-dark mb-2"><?= e($dish['type']) ?></span>
            <h1 class="fw-extrabold display-5 mb-2"><?= e($dish['dish_name']) ?></h1>
            <h2 class="fw-bold mb-3">Rs <?= number_format((float) $dish['display_price'], 0) ?></h2>

            <p class="fs-5 text-secondary mb-3"><?= e($dish['description']) ?></p>

            <div class="d-flex align-items-center gap-2 mb-4">
                <i class="bi bi-star-fill text-warning fs-5"></i>
                <span class="fw-bold fs-5 text-warning"><?= number_format($avgRating, 1) ?></span>
                <a href="#reviews" class="text-dark fw-bold text-decoration-none">(<?= $totalReviews ?> Reviews)</a>
            </div>

            <!-- Ingredients Pill Badges -->
            <h4 class="fw-bold mb-3">Ingredients</h4>
            <div class="d-flex flex-wrap gap-2 mb-4">
                <?php foreach ($ingredients as $ing): ?>
                    <span class="badge bg-dark text-white px-3 py-2 rounded-2 fw-medium">
                        <?= e($ing['ingredient_name']) ?>
                    </span>
                <?php endforeach; ?>
            </div>

            <!-- Quantity Selector (- 1 +) & Add to Order Button -->
            <div class="d-flex flex-wrap align-items-center gap-4 mt-4">
                <div class="input-group kp-qty-box" style="width: 160px;">
                    <button type="button" class="btn btn-secondary bg-secondary-subtle text-dark border-0 fw-bold fs-4 js-qty-step" data-step="-1">-</button>
                    <input type="text" class="form-control text-center fw-bold fs-4 bg-secondary-subtle border-0 js-dish-qty" value="1" readonly>
                    <button type="button" class="btn btn-secondary bg-secondary-subtle text-dark border-0 fw-bold fs-4 js-qty-step" data-step="1">+</button>
                </div>

                <button type="button" class="btn btn-dark btn-lg px-5 py-3 rounded-3 fw-bold js-add-to-cart" data-dish-id="<?= (int) $dish['dish_id'] ?>">
                    Add to Order
                </button>
            </div>
        </div>
    </div>

    <!-- Customer Reviews Section (Combines Rating Breakdown + Avatar Cards) -->
    <hr class="my-5" id="reviews">
    <h2 class="fw-bold mb-4">Customer Reviews</h2>

    <div class="row g-4">
        <!-- Left Rating Breakdown Card -->
        <div class="col-lg-4">
            <div class="card border rounded-4 p-4 h-100">
                <h6 class="fw-bold mb-2">Overall rating</h6>
                <div class="d-flex align-items-center gap-3 mb-1">
                    <span class="display-4 fw-extrabold"><?= number_format($avgRating, 1) ?></span>
                    <div class="text-warning fs-5">
                        <?php for ($i = 1; $i <= 5; $i++): ?>
                            <i class="bi <?= $i <= round($avgRating) ? 'bi-star-fill' : 'bi-star' ?>"></i>
                        <?php endfor; ?>
                    </div>
                </div>
                <p class="text-muted small mb-4">Based on <?= $totalReviews ?> reviews</p>

                <?php for ($star = 5; $star >= 1; $star--):
                    $cnt = $starCounts[$star];
                    $pct = $totalReviews > 0 ? round(($cnt / $totalReviews) * 100) : 0;
                ?>
                    <div class="d-flex align-items-center gap-2 mb-2 small">
                        <span style="width: 45px;"><?= $star ?> star</span>
                        <div class="progress flex-grow-1" style="height: 8px;">
                            <div class="progress-bar bg-warning" role="progressbar" style="width: <?= $pct ?>%"></div>
                        </div>
                        <span class="fw-bold text-secondary" style="width: 24px; text-align:right;"><?= $cnt ?></span>
                    </div>
                <?php endfor; ?>
            </div>
        </div>

        <!-- Right Write a Review Form + Review Feed -->
        <div class="col-lg-8">
            <div class="card border rounded-4 p-4 mb-4">
                <h5 class="fw-bold mb-3">Write a Review</h5>
                <?php if (isLoggedIn()): ?>
                    <form method="POST" action="dish.php?id=<?= (int) $dish['dish_id'] ?>">
                        <?= csrfInput() ?>
                        <input type="hidden" name="form_action" value="submit_review">

                        <div class="mb-3">
                            <label class="form-label small text-muted d-block">Your rating</label>
                            <div class="d-flex gap-3">
                                <?php for ($s = 5; $s >= 1; $s--): ?>
                                    <div class="form-check form-check-inline">
                                        <input class="form-check-input" type="radio" name="rating" id="star<?= $s ?>" value="<?= $s ?>" <?= $s === 5 ? 'checked' : '' ?>>
                                        <label class="form-check-label fw-semibold" for="star<?= $s ?>">
                                            <?= $s ?> <i class="bi bi-star-fill text-warning"></i>
                                        </label>
                                    </div>
                                <?php endfor; ?>
                            </div>
                        </div>

                        <div class="mb-3">
                            <textarea name="comment" rows="3" class="form-control" placeholder="Share your experience with this dish..." required></textarea>
                        </div>

                        <button type="submit" class="btn btn-dark px-4 py-2 fw-semibold">Submit Review</button>
                    </form>
                <?php else: ?>
                    <p class="text-muted mb-0">Please <a href="auth.php" class="fw-bold text-dark">log in</a> to rate and review this dish.</p>
                <?php endif; ?>
            </div>

            <!-- Reviews List -->
            <?php if (empty($reviews)): ?>
                <div class="text-muted">No reviews yet for this dish. Be the first to review!</div>
            <?php else: ?>
                <?php
                $avatarColors = ['#dc2626', '#2563eb', '#059669', '#7c3aed', '#d97706'];
                foreach ($reviews as $idx => $rev):
                    $initial = strtoupper(substr(trim($rev['reviewer_name']), 0, 1));
                    $bgColor = $avatarColors[$idx % count($avatarColors)];
                ?>
                    <div class="card border rounded-4 p-3 mb-3">
                        <div class="d-flex align-items-start gap-3">
                            <div class="kp-review-avatar" style="background-color: <?= $bgColor ?>;">
                                <?= e($initial) ?>
                            </div>
                            <div class="flex-grow-1">
                                <div class="d-flex justify-content-between align-items-center mb-1">
                                    <div>
                                        <strong class="me-2"><?= e($rev['reviewer_name']) ?></strong>
                                        <span class="text-warning small">
                                            <?php for ($i = 1; $i <= 5; $i++): ?>
                                                <i class="bi <?= $i <= (int) $rev['rating'] ? 'bi-star-fill' : 'bi-star' ?>"></i>
                                            <?php endfor; ?>
                                        </span>
                                    </div>
                                    <small class="text-muted"><?= e(date('d M Y', strtotime($rev['review_date']))) ?></small>
                                </div>
                                <p class="mb-0 fw-medium"><?= e($rev['comment']) ?></p>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>