<?php
/**
 * Kree To Pla - Home Page
 * Visitor Requirements #1, #2, #3: Browse weekly highlights & popular dishes without registering.
 */

declare(strict_types=1);
require_once __DIR__ . '/includes/security.php';

// Fetch top 4 highlighted dishes from the currently published weekly menu
$stmt = $pdo->query('
    SELECT d.dish_id, d.dish_name, d.description, d.type, d.bestseller, d.image_url,
           COALESCE(mi.price, d.base_price) AS display_price
    FROM Dish d
    LEFT JOIN Menu_item mi ON d.dish_id = mi.dish_id
    LEFT JOIN Menu m ON mi.menu_id = m.menu_id AND m.is_published = TRUE
    WHERE d.is_active = TRUE
    ORDER BY d.bestseller DESC, d.dish_id ASC
    LIMIT 4
');
$highlights = $stmt->fetchAll();

$pageTitle = 'Home';
require_once __DIR__ . '/includes/header.php';
?>

<!-- Hero Section -->
<section class="kp-hero">
    <div class="container">
        <div class="row align-items-center">
            <div class="col-lg-6 col-xl-5">
                <div class="kp-hero-card">
                    <h1 class="kp-hero-title mb-3">Good Food.<br>Made For You.</h1>
                    <p class="kp-hero-subtitle mb-4">Fresh meals, your choice, delivered with care</p>
                    <div class="d-flex flex-wrap gap-3">
                        <a href="menu.php" class="btn kp-btn-hero-dark">View Weekly Menu</a>
                        <a href="build_dish.php" class="btn kp-btn-hero-light">Create Your Own Dish</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- This Week's Highlights Section -->
<section class="py-5">
    <div class="container">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h3 class="fw-bold mb-0">This Week’s Highlights</h3>
            <a href="menu.php" class="text-dark fw-bold text-decoration-none">View All &rarr;</a>
        </div>

        <div class="row g-4">
            <?php foreach ($highlights as $dish): ?>
                <div class="col-sm-6 col-lg-3">
                    <div class="kp-dish-card h-100 d-flex flex-column">
                        <a href="dish.php?id=<?= (int) $dish['dish_id'] ?>" class="kp-dish-img-wrap d-block">
                            <?php if ($dish['bestseller']): ?>
                                <span class="kp-bestseller-badge">BESTSELLER</span>
                            <?php endif; ?>
                            <img src="<?= e($dish['image_url']) ?>" alt="<?= e($dish['dish_name']) ?>">
                        </a>
                        <div class="p-3 d-flex flex-column flex-grow-1 justify-content-between">
                            <div>
                                <a href="dish.php?id=<?= (int) $dish['dish_id'] ?>" class="text-decoration-none text-dark">
                                    <h6 class="fw-bold mb-1"><?= e($dish['dish_name']) ?></h6>
                                </a>
                                <p class="text-muted small mb-2">Rs <?= number_format((float) $dish['display_price'], 0) ?></p>
                            </div>
                            <div class="d-flex gap-2 mt-2">
                                <form method="POST" action="process_action.php" class="flex-grow-1">
                                    <?= csrfInput() ?>
                                    <input type="hidden" name="action" value="add_to_cart">
                                    <input type="hidden" name="dish_id" value="<?= (int) $dish['dish_id'] ?>">
                                    <input type="hidden" name="quantity" value="1">
                                    <button type="submit" class="btn btn-dark btn-sm w-100">
                                        Add to Order
                                    </button>
                                </form>
                                <a href="dish.php?id=<?= (int) $dish['dish_id'] ?>" class="btn btn-outline-secondary btn-sm" title="Dish Details">
                                    <i class="bi bi-info-circle"></i>
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<?php require_once __DIR__ . '/includes/footer.php'; ?>