<?php
/**
 * Kree To Pla - Weekly Rotating Menu Page
 * Supports browsing the published weekly menu, filtering by Bestsellers or Dietary Type,
 * toggling Favourites, and navigating to Dish Details & Reviews.
 */

declare(strict_types=1);
require_once __DIR__ . '/includes/security.php';

// Fetch active published menu
$menuStmt = $pdo->query('SELECT * FROM Menu WHERE is_published = TRUE ORDER BY week_start DESC LIMIT 1');
$activeMenu = $menuStmt->fetch();

$filterBestseller = isset($_GET['bestsellers']) && $_GET['bestsellers'] === '1';
$filterType       = trim((string) ($_GET['type'] ?? ''));

$allowedTypes = ['Vegetarian', 'Vegan', 'Non-Vegetarian'];
if (!in_array($filterType, $allowedTypes, true)) {
    $filterType = '';
}

// Fetch customer's favourited dish IDs if logged in
$favIds = [];
if (isLoggedIn()) {
    $fStmt = $pdo->prepare('SELECT dish_id FROM Favourite WHERE customer_id = :cid');
    $fStmt->execute([':cid' => $_SESSION['user_id']]);
    $favIds = array_map('intval', array_column($fStmt->fetchAll(), 'dish_id'));
}

// Build parameterized query for weekly menu dishes
$sql = '
    SELECT d.dish_id, d.dish_name, d.description, d.type, d.bestseller, d.image_url,
           COALESCE(mi.price, d.base_price) AS menu_price,
           COALESCE(mi.availability, TRUE) AS is_available,
           ROUND(AVG(CASE WHEN r.is_visible = TRUE THEN r.rating END), 1) AS avg_rating
    FROM Dish d
    LEFT JOIN Menu_item mi ON d.dish_id = mi.dish_id AND mi.menu_id = :menu_id
    LEFT JOIN Review r ON d.dish_id = r.dish_id
    WHERE d.is_active = TRUE
';
$params = [':menu_id' => $activeMenu['menu_id'] ?? 1];

if ($filterBestseller) {
    $sql .= ' AND d.bestseller = TRUE';
}
if ($filterType !== '') {
    $sql .= ' AND d.type = :type';
    $params[':type'] = $filterType;
}

$sql .= ' GROUP BY d.dish_id, mi.price, mi.availability ORDER BY d.bestseller DESC, d.dish_id ASC';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$dishes = $stmt->fetchAll();

$pageTitle = "This Week's Menu";
require_once __DIR__ . '/includes/header.php';
?>

<div class="container py-5">
    <!-- Header & Active Week Subtitle -->
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-end mb-4">
        <div>
            <h1 class="fw-extrabold text-uppercase mb-1">THIS WEEK’S MENU</h1>
            <?php if ($activeMenu): ?>
                <h4 class="fw-bold text-secondary mb-0">
                    <?= e($activeMenu['description'] ?: ('Week (' . $activeMenu['week_start'] . ' - ' . $activeMenu['week_end'] . ')')) ?>
                </h4>
            <?php endif; ?>
        </div>

        <!-- Dietary & Bestseller Filter Pills -->
        <div class="d-flex flex-wrap gap-2 mt-3 mt-md-0">
            <a href="menu.php" class="btn btn-sm <?= (!$filterBestseller && $filterType === '') ? 'btn-dark' : 'btn-outline-dark' ?>">All Dishes</a>
            <a href="menu.php?bestsellers=1" class="btn btn-sm <?= $filterBestseller ? 'btn-danger' : 'btn-outline-danger' ?>">
                <i class="bi bi-fire"></i> Bestsellers
            </a>
            <?php foreach ($allowedTypes as $t): ?>
                <a href="menu.php?type=<?= urlencode($t) ?>" class="btn btn-sm <?= $filterType === $t ? 'btn-dark' : 'btn-outline-secondary' ?>">
                    <?= e($t) ?>
                </a>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Dish Grid -->
    <div class="row row-cols-1 row-cols-sm-2 row-cols-md-3 row-cols-lg-5 g-4 mb-5">
        <?php foreach ($dishes as $dish):
            $id    = (int) $dish['dish_id'];
            $isFav = in_array($id, $favIds, true);
            $avail = (bool) $dish['is_available'];
        ?>
            <div class="col">
                <div class="kp-dish-card h-100 d-flex flex-column">
                    <div class="kp-dish-img-wrap">
                        <!-- Top-left Star Rating Badge -->
                        <span class="position-absolute top-0 start-0 m-2 badge bg-white text-dark shadow-sm">
                            <i class="bi bi-star-fill text-warning me-1"></i><?= $dish['avg_rating'] ? e($dish['avg_rating']) : 'New' ?>
                        </span>

                        <!-- Top-right Favourite Heart Button -->
                        <form method="POST" action="process_action.php">
                            <?= csrfInput() ?>
                            <input type="hidden" name="action" value="toggle_favourite">
                            <input type="hidden" name="dish_id" value="<?= $id ?>">
                            <button type="submit" class="kp-fav-btn" title="Save to Favourites" aria-label="Toggle favourite">
                                <i class="bi <?= $isFav ? 'bi-heart-fill text-danger' : 'bi-heart text-secondary' ?>"></i>
                            </button>
                        </form>

                        <a href="dish.php?id=<?= $id ?>">
                            <img src="<?= e($dish['image_url']) ?>" alt="<?= e($dish['dish_name']) ?>">
                        </a>
                    </div>

                    <div class="p-3 bg-light flex-grow-1 d-flex flex-column justify-content-between">
                        <div class="d-flex justify-content-between align-items-start mb-2">
                            <div class="text-center w-100">
                                <a href="dish.php?id=<?= $id ?>" class="text-decoration-none text-dark">
                                    <h6 class="fw-bold mb-0"><?= e($dish['dish_name']) ?></h6>
                                </a>
                                <div class="fw-semibold small text-secondary">Rs <?= number_format((float) $dish['menu_price'], 0) ?></div>
                            </div>
                            <!-- Info Icon (i) -->
                            <a href="dish.php?id=<?= $id ?>" class="btn btn-sm btn-white rounded-circle border shadow-sm ms-1" title="View Ingredients & Reviews">
                                <i class="bi bi-info-lg fw-bold"></i>
                            </a>
                        </div>

                        <?php if ($avail): ?>
                            <form method="POST" action="process_action.php">
                                <?= csrfInput() ?>
                                <input type="hidden" name="action" value="add_to_cart">
                                <input type="hidden" name="dish_id" value="<?= $id ?>">
                                <input type="hidden" name="quantity" value="1">
                                <button type="submit" class="btn btn-white bg-white border border-dark w-100 fw-semibold btn-sm py-2">
                                    Add
                                </button>
                            </form>
                        <?php else: ?>
                            <button type="button" class="btn btn-secondary w-100 btn-sm py-2" disabled>Unavailable</button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- Bottom Quick-Links Bar -->
    <div class="row text-center pt-3 border-top g-3">
        <div class="col-md-4">
            <a href="dish.php?id=1#reviews" class="text-dark fw-bold fs-5 text-decoration-underline">
                <i class="bi bi-star-fill text-warning me-2"></i>Read &amp; write Reviews
            </a>
        </div>
        <div class="col-md-4">
            <a href="favourites.php" class="text-dark fw-bold fs-5 text-decoration-underline">
                <i class="bi bi-heart-fill text-danger me-2"></i>Add to your Favourites
            </a>
        </div>
        <div class="col-md-4">
            <a href="menu.php?bestsellers=1" class="text-dark fw-bold fs-5 text-decoration-underline">
                <i class="bi bi-fire text-danger me-2"></i>Check our Bestsellers
            </a>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>