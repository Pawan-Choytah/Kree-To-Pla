<?php
/**
 * My Favourites Page
 */

declare(strict_types=1);
require_once __DIR__ . '/includes/security.php';
requireLogin('auth.php');

$userId = (int) $_SESSION['user_id'];

// Handle Remove Custom Dish from Favourites
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_action'] ?? '') === 'remove_custom_fav') {
    requireValidCsrf();
    $cdId = filter_input(INPUT_POST, 'custom_dish_id', FILTER_VALIDATE_INT);
    if ($cdId) {
        $pdo->prepare('UPDATE Custom_dish SET is_favourite = FALSE WHERE custom_dish_id = :cdid AND customer_id = :cid')
            ->execute([':cdid' => $cdId, ':cid' => $userId]);
        $_SESSION['flash_success'] = 'Custom dish removed from favourites.';
    }
    header('Location: favourites.php');
    exit;
}

// Fetch saved standard dishes
$stmt = $pdo->prepare('
    SELECT d.dish_id, d.dish_name, d.image_url,
           COALESCE(mi.price, d.base_price) AS display_price
    FROM Favourite f
    INNER JOIN Dish d ON f.dish_id = d.dish_id
    LEFT JOIN Menu m ON m.is_published = TRUE
    LEFT JOIN Menu_item mi ON d.dish_id = mi.dish_id AND mi.menu_id = m.menu_id
    WHERE f.customer_id = :cid AND d.is_active = TRUE
    GROUP BY d.dish_id, mi.price
    ORDER BY f.created_at DESC
');
$stmt->execute([':cid' => $userId]);
$favDishes = $stmt->fetchAll();

// Fetch saved personalised dishes
$cStmt = $pdo->prepare('
    SELECT custom_dish_id, dish_name, instructions, total_price
    FROM Custom_dish
    WHERE customer_id = :cid AND is_favourite = TRUE
    ORDER BY created_at DESC
');
$cStmt->execute([':cid' => $userId]);
$favCustom = $cStmt->fetchAll();

$totalSaved = count($favDishes) + count($favCustom);

$pageTitle = 'My Favourites';
require_once __DIR__ . '/includes/header.php';
?>

<div class="container py-5">
    <!-- Header & Saved Count -->
    <div class="mb-4">
        <h2 class="fw-extrabold mb-1">My Favourites</h2>
        <p class="text-secondary mb-0"><?= $totalSaved ?> items saved</p>
    </div>

    <?php if ($totalSaved === 0): ?>
        <div class="alert alert-light border p-5 text-center">
            <i class="bi bi-heart fs-1 text-secondary d-block mb-2"></i>
            <h5>You haven't saved any favourites yet</h5>
            <p class="text-muted">Click the heart icon on any dish in the Weekly Menu to save it here.</p>
            <a href="menu.php" class="btn btn-dark mt-2">Browse Weekly Menu</a>
        </div>
    <?php else: ?>
        <!-- 4-Column Favourites Grid -->
        <div class="row row-cols-1 row-cols-sm-2 row-cols-lg-4 g-4">
            <?php foreach ($favDishes as $dish):
                $id = (int) $dish['dish_id'];
            ?>
                <div class="col js-fav-card-col">
                    <div class="kp-dish-card h-100 d-flex flex-column">
                        <div class="kp-dish-img-wrap">
                            <button type="button" class="kp-fav-btn js-toggle-fav" data-dish-id="<?= $id ?>" data-remove-card="true">
                                <i class="bi bi-heart-fill text-danger"></i>
                            </button>
                            <a href="dish.php?id=<?= $id ?>">
                                <img src="<?= e($dish['image_url']) ?>" alt="<?= e($dish['dish_name']) ?>">
                            </a>
                        </div>
                        <div class="p-3 d-flex flex-column flex-grow-1 justify-content-between">
                            <div>
                                <h6 class="fw-bold mb-1"><?= e($dish['dish_name']) ?></h6>
                                <p class="small text-secondary mb-3">Rs <?= number_format((float) $dish['display_price'], 0) ?></p>
                            </div>
                            <div class="text-center">
                                <button type="button" class="btn btn-dark btn-sm w-75 py-2 mb-2 js-add-to-cart" data-dish-id="<?= $id ?>">
                                    Add to Order
                                </button>
                                <div>
                                    <a href="#" class="small text-secondary text-decoration-underline js-toggle-fav"
                                       data-dish-id="<?= $id ?>" data-remove-card="true">Remove</a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>

            <!-- Saved Personalised Dishes -->
            <?php foreach ($favCustom as $cd): ?>
                <div class="col">
                    <div class="kp-dish-card h-100 d-flex flex-column">
                        <div class="kp-dish-img-wrap">
                            <span class="kp-bestseller-badge bg-dark">CUSTOM DISH</span>
                            <img src="https://images.unsplash.com/photo-1546069901-ba9599a7e63c?auto=format&fit=crop&w=600&q=80" alt="<?= e($cd['dish_name']) ?>">
                        </div>
                        <div class="p-3 d-flex flex-column flex-grow-1 justify-content-between">
                            <div>
                                <h6 class="fw-bold mb-1"><?= e($cd['dish_name']) ?></h6>
                                <p class="small text-secondary mb-1">Rs <?= number_format((float) $cd['total_price'], 0) ?></p>
                                <?php if (!empty($cd['instructions'])): ?>
                                    <p class="small text-muted fst-italic mb-2">"<?= e($cd['instructions']) ?>"</p>
                                <?php endif; ?>
                            </div>
                            <div class="text-center">
                                <a href="build_dish.php" class="btn btn-dark btn-sm w-75 py-2 mb-2">Customise Again</a>
                                <form method="POST" action="favourites.php">
                                    <?= csrfInput() ?>
                                    <input type="hidden" name="form_action" value="remove_custom_fav">
                                    <input type="hidden" name="custom_dish_id" value="<?= (int) $cd['custom_dish_id'] ?>">
                                    <button type="submit" class="btn btn-link btn-sm text-secondary text-decoration-underline p-0">Remove</button>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>