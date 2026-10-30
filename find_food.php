<?php
/**
 * Kree To Pla - Find Dishes by Ingredient
 */

declare(strict_types=1);
require_once __DIR__ . '/includes/security.php';

// Fetch all available ingredients for the 4-column checkbox grid
$ingStmt = $pdo->query('SELECT ingredient_id, ingredient_name, category, allergy_info FROM Ingredient WHERE is_available = TRUE ORDER BY ingredient_id ASC');
$allIngredients = $ingStmt->fetchAll();

// Read selected ingredient IDs or text search query
$selectedIds = isset($_GET['ingredients']) && is_array($_GET['ingredients'])
    ? array_values(array_filter(array_map('intval', $_GET['ingredients']), fn($id) => $id > 0))
    : [];
$searchQuery = trim((string) ($_GET['q'] ?? ''));

$favIds = [];
if (isLoggedIn()) {
    $fStmt = $pdo->prepare('SELECT dish_id FROM Favourite WHERE customer_id = :cid');
    $fStmt->execute([':cid' => $_SESSION['user_id']]);
    $favIds = array_map('intval', array_column($fStmt->fetchAll(), 'dish_id'));
}

// Execute parameterized search query when ingredients or text search are provided
$results          = [];
$selectedNames    = [];
$hasSearched      = !empty($selectedIds) || $searchQuery !== '';

if ($hasSearched) {
    $params = [];
    $sql = '
        SELECT DISTINCT d.dish_id, d.dish_name, d.description, d.type, d.bestseller, d.image_url,
               COALESCE(mi.price, d.base_price) AS display_price
        FROM Dish d
        LEFT JOIN Menu m ON m.is_published = TRUE
        LEFT JOIN Menu_item mi ON d.dish_id = mi.dish_id AND mi.menu_id = m.menu_id
        INNER JOIN Dish_ingredient di ON d.dish_id = di.dish_id
        INNER JOIN Ingredient i ON di.ingredient_id = i.ingredient_id
        WHERE d.is_active = TRUE
    ';

    if (!empty($selectedIds)) {
        $placeholders = implode(',', array_fill(0, count($selectedIds), '?'));
        $sql .= " AND di.ingredient_id IN ($placeholders)";
        $params = array_merge($params, $selectedIds);
    }

    if ($searchQuery !== '') {
        $sql .= ' AND (d.dish_name LIKE ? OR i.ingredient_name LIKE ?)';
        $params[] = '%' . $searchQuery . '%';
        $params[] = '%' . $searchQuery . '%';
    }

    $sql .= ' ORDER BY d.bestseller DESC, d.dish_id ASC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $results = $stmt->fetchAll();

    foreach ($allIngredients as $ing) {
        if (in_array((int) $ing['ingredient_id'], $selectedIds, true)) {
            $selectedNames[] = $ing['ingredient_name'];
        }
    }
    if ($searchQuery !== '') {
        $selectedNames[] = $searchQuery;
    }
}

$pageTitle = 'Find Food by Ingredients';
require_once __DIR__ . '/includes/header.php';
?>

<div class="container py-5">
    <!-- Ingredient Selector Form -->
    <form method="GET" action="find_food.php" class="mb-5">
        <h2 class="fw-extrabold mb-3">Find dishes with your ingredients</h2>

        <!-- Search Bar with Magnifying Glass Icon -->
        <div class="input-group input-group-lg mb-4 bg-light rounded-3 border">
            <input type="text" id="js-ingredient-filter-input" name="q"
                   class="form-control bg-transparent border-0"
                   placeholder="Search ingredient (e.g., chicken, rice, tomato)"
                   value="<?= e($searchQuery) ?>">
            <button class="btn btn-link text-dark px-4" type="submit" title="Search">
                <i class="bi bi-search fs-4"></i>
            </button>
        </div>

        <h4 class="fw-bold mb-3">Select your ingredients</h4>

        <!-- 4-Column Ingredient Checkbox Grid -->
        <div class="row row-cols-2 row-cols-md-4 g-3 mb-4">
            <?php foreach ($allIngredients as $ing):
                $id      = (int) $ing['ingredient_id'];
                $checked = in_array($id, $selectedIds, true);
            ?>
                <div class="col js-ingredient-item" data-name="<?= e($ing['ingredient_name']) ?>">
                    <label class="kp-ing-pill w-100 <?= $checked ? 'selected' : '' ?>">
                        <input type="checkbox" name="ingredients[]" value="<?= $id ?>"
                               class="form-check-input mt-0" <?= $checked ? 'checked' : '' ?>>
                        <span><?= e($ing['ingredient_name']) ?></span>
                    </label>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="text-center mt-4">
            <button type="submit" class="btn btn-dark btn-lg px-5 py-2 fw-bold">Find Dishes</button>
            <?php if ($hasSearched): ?>
                <a href="find_food.php" class="btn btn-outline-secondary btn-lg ms-2 px-4 py-2">Reset</a>
            <?php endif; ?>
        </div>
    </form>

    <!-- Filtered Results Grid -->
    <?php if ($hasSearched): ?>
        <hr class="my-5">
        <div class="mb-4">
            <h2 class="fw-extrabold mb-1">
                Results for: <?= e(implode(', ', $selectedNames)) ?>
            </h2>
            <p class="text-secondary fw-semibold mb-0"><?= count($results) ?> Dishes Found</p>
        </div>

        <?php if (empty($results)): ?>
            <div class="alert alert-light border p-4 text-center">
                <p class="mb-2 fw-semibold">No standard dishes matched that exact combination.</p>
                <a href="build_dish.php" class="btn btn-dark btn-sm">Build It as a Custom Dish Instead &rarr;</a>
            </div>
        <?php else: ?>
            <div class="row row-cols-1 row-cols-sm-2 row-cols-lg-4 g-4">
                <?php foreach ($results as $dish):
                    $id    = (int) $dish['dish_id'];
                    $isFav = in_array($id, $favIds, true);
                ?>
                    <div class="col">
                        <div class="kp-dish-card h-100 d-flex flex-column">
                            <div class="kp-dish-img-wrap">
                                <button type="button" class="kp-fav-btn js-toggle-fav" data-dish-id="<?= $id ?>">
                                    <i class="bi <?= $isFav ? 'bi-heart-fill text-danger' : 'bi-heart text-secondary' ?>"></i>
                                </button>
                                <a href="dish.php?id=<?= $id ?>">
                                    <img src="<?= e($dish['image_url']) ?>" alt="<?= e($dish['dish_name']) ?>">
                                </a>
                            </div>
                            <div class="p-3 d-flex flex-column flex-grow-1 justify-content-between">
                                <div>
                                    <a href="dish.php?id=<?= $id ?>" class="text-decoration-none text-dark">
                                        <h6 class="fw-bold mb-1"><?= e($dish['dish_name']) ?></h6>
                                    </a>
                                    <p class="fw-bold small mb-3">Rs <?= number_format((float) $dish['display_price'], 0) ?></p>
                                </div>
                                <button type="button" class="btn btn-outline-dark w-100 fw-bold js-add-to-cart" data-dish-id="<?= $id ?>">
                                    Add
                                </button>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>