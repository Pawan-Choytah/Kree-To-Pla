<?php
/**
 * Kree To Pla - Build a Personalised Dish
 */

declare(strict_types=1);
require_once __DIR__ . '/includes/security.php';


$stmt = $pdo->query('SELECT * FROM Ingredient WHERE is_available = TRUE ORDER BY category, ingredient_id ASC');
$grouped = ['Base' => [], 'Protein' => [], 'Vegetable' => [], 'Sauce' => []];
foreach ($stmt->fetchAll() as $ing) {
    $cat = $ing['category'];
    if ($cat === 'Other') {
        $grouped['Vegetable'][] = $ing;
    } else {
        $grouped[$cat][] = $ing;
    }
}


if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireLogin('auth.php');
    requireValidCsrf();

    $selectedIds  = isset($_POST['ingredients']) && is_array($_POST['ingredients'])
        ? array_values(array_unique(array_filter(array_map('intval', $_POST['ingredients']), fn($id) => $id > 0)))
        : [];
    $instructions = trim((string) ($_POST['instructions'] ?? ''));
    $customName   = trim((string) ($_POST['dish_name'] ?? ''));
    $saveFav      = isset($_POST['save_favourite']) ? 1 : 0;

    if (empty($selectedIds)) {
        $_SESSION['flash_error'] = 'Please select at least one base or ingredient for your custom dish.';
    } else {
       
        $placeholders = implode(',', array_fill(0, count($selectedIds), '?'));
        $priceStmt = $pdo->prepare("SELECT ingredient_id, ingredient_name, category, price FROM Ingredient WHERE ingredient_id IN ($placeholders) AND is_available = TRUE");
        $priceStmt->execute($selectedIds);
        $validIngredients = $priceStmt->fetchAll();

        if (empty($validIngredients)) {
            $_SESSION['flash_error'] = 'Selected ingredients are invalid or unavailable.';
        } else {
            $totalPrice = (float) CUSTOM_DISH_BASE_PRICE;
            $nameParts  = [];
            foreach ($validIngredients as $vi) {
                $totalPrice += (float) $vi['price'];
                if ($vi['category'] === 'Protein' || $vi['category'] === 'Base') {
                    $nameParts[] = $vi['ingredient_name'];
                }
            }

            if ($customName === '') {
                $customName = 'Custom ' . (!empty($nameParts) ? implode(' ', array_slice($nameParts, 0, 2)) : 'Chef Bowl');
            }

            $userId = (int) $_SESSION['user_id'];
            $pdo->beginTransaction();
            try {
                
                $pdo->prepare('INSERT IGNORE INTO Customer (user_id, address) VALUES (:uid, "Cafeteria Pickup")')
                    ->execute([':uid' => $userId]);

                $insCustom = $pdo->prepare('
                    INSERT INTO Custom_dish (customer_id, dish_name, instructions, total_price, is_favourite)
                    VALUES (:cid, :name, :inst, :price, :fav)
                ');
                $insCustom->execute([
                    ':cid'   => $userId,
                    ':name'  => substr($customName, 0, 150),
                    ':inst'  => $instructions !== '' ? $instructions : null,
                    ':price' => $totalPrice,
                    ':fav'   => $saveFav,
                ]);
                $customDishId = (int) $pdo->lastInsertId();

                $insIng = $pdo->prepare('
                    INSERT INTO Custom_dish_ingredient (custom_dish_id, ingredient_id, quantity)
                    VALUES (:cdid, :iid, 1)
                ');
                foreach ($validIngredients as $vi) {
                    $insIng->execute([
                        ':cdid' => $customDishId,
                        ':iid'  => (int) $vi['ingredient_id'],
                    ]);
                }

                $pdo->commit();

               
                if (!isset($_SESSION['cart']) || !is_array($_SESSION['cart'])) {
                    $_SESSION['cart'] = [];
                }
                $cartKey = 'custom_' . $customDishId;
                $_SESSION['cart'][$cartKey] = [
                    'type'           => 'custom',
                    'dish_id'        => null,
                    'custom_dish_id' => $customDishId,
                    'name'           => $customName,
                    'unit_price'     => $totalPrice,
                    'quantity'       => 1,
                    'instructions'   => $instructions,
                    'image_url'      => 'https://images.unsplash.com/photo-1546069901-ba9599a7e63c?auto=format&fit=crop&w=600&q=80',
                ];

                $_SESSION['flash_success'] = $customName . ' (Rs ' . number_format($totalPrice, 0) . ') created and added to your order!';
                header('Location: cart.php');
                exit;
            } catch (Throwable $e) {
                $pdo->rollBack();
                error_log('Custom Dish Error: ' . $e->getMessage());
                $_SESSION['flash_error'] = 'Could not save custom dish. Please try again.';
            }
        }
    }
}

$pageTitle = 'Build Your Own Dish';
require_once __DIR__ . '/includes/header.php';
?>

<div class="container py-5">
    <form method="POST" action="build_dish.php" id="js-build-dish-form" data-base-price="<?= (float) CUSTOM_DISH_BASE_PRICE ?>">
        <?= csrfInput() ?>

        <div class="row g-5 align-items-start">
            
            <div class="col-lg-8">
                
                <h3 class="fw-extrabold mb-3">1. Choose a base</h3>
                <div class="row row-cols-2 row-cols-md-4 g-3 mb-4">
                    <?php foreach ($grouped['Base'] as $idx => $ing): ?>
                        <div class="col">
                            <label class="kp-ing-pill w-100 justify-content-center">
                                <input type="checkbox" name="ingredients[]" value="<?= (int) $ing['ingredient_id'] ?>"
                                       class="form-check-input d-none"
                                       data-name="<?= e($ing['ingredient_name']) ?>"
                                       data-category="Base"
                                       data-price="<?= (float) $ing['price'] ?>"
                                       <?= $idx === 0 ? 'checked' : '' ?>>
                                <span><?= e($ing['ingredient_name']) ?> <small class="opacity-75">(+Rs <?= (int) $ing['price'] ?>)</small></span>
                            </label>
                        </div>
                    <?php endforeach; ?>
                </div>

                
                <h3 class="fw-extrabold mb-3">2. Choose a protein</h3>
                <div class="row row-cols-2 row-cols-md-4 g-3 mb-4">
                    <?php foreach ($grouped['Protein'] as $idx => $ing): ?>
                        <div class="col">
                            <label class="kp-ing-pill w-100 justify-content-center">
                                <input type="checkbox" name="ingredients[]" value="<?= (int) $ing['ingredient_id'] ?>"
                                       class="form-check-input d-none"
                                       data-name="<?= e($ing['ingredient_name']) ?>"
                                       data-category="Protein"
                                       data-price="<?= (float) $ing['price'] ?>"
                                       <?= $idx === 0 ? 'checked' : '' ?>>
                                <span><?= e($ing['ingredient_name']) ?> <small class="opacity-75">(+Rs <?= (int) $ing['price'] ?>)</small></span>
                            </label>
                        </div>
                    <?php endforeach; ?>
                </div>

                
                <h3 class="fw-extrabold mb-3">3. Add ingredients</h3>
                <div class="row row-cols-2 row-cols-md-4 g-3 mb-4">
                    <?php foreach ($grouped['Vegetable'] as $idx => $ing): ?>
                        <div class="col">
                            <label class="kp-ing-pill w-100 justify-content-center">
                                <input type="checkbox" name="ingredients[]" value="<?= (int) $ing['ingredient_id'] ?>"
                                       class="form-check-input d-none"
                                       data-name="<?= e($ing['ingredient_name']) ?>"
                                       data-category="Vegetable"
                                       data-price="<?= (float) $ing['price'] ?>"
                                       <?= in_array($idx, [0, 1, 2], true) ? 'checked' : '' ?>>
                                <span><?= e($ing['ingredient_name']) ?> <small class="opacity-75">(+Rs <?= (int) $ing['price'] ?>)</small></span>
                            </label>
                        </div>
                    <?php endforeach; ?>
                </div>

                
                <h3 class="fw-extrabold mb-3">4. Sauce type</h3>
                <div class="row row-cols-2 row-cols-md-4 g-3 mb-4">
                    <?php foreach ($grouped['Sauce'] as $idx => $ing): ?>
                        <div class="col">
                            <label class="kp-ing-pill w-100 justify-content-center">
                                <input type="checkbox" name="ingredients[]" value="<?= (int) $ing['ingredient_id'] ?>"
                                       class="form-check-input d-none"
                                       data-name="<?= e($ing['ingredient_name']) ?>"
                                       data-category="Sauce"
                                       data-price="<?= (float) $ing['price'] ?>"
                                       <?= $idx === 0 ? 'checked' : '' ?>>
                                <span><?= e($ing['ingredient_name']) ?> <small class="opacity-75">(+Rs <?= (int) $ing['price'] ?>)</small></span>
                            </label>
                        </div>
                    <?php endforeach; ?>
                </div>

                
                <h5 class="fw-bold mb-2">5. Any special Instructions/Allergies (Optional)</h5>
                <input type="text" name="instructions" class="form-control form-control-lg bg-secondary-subtle border-0 mb-3"
                       placeholder="E.g Less spicy, no onions, extra sauce">

                <div class="row g-3 align-items-center">
                    <div class="col-md-6">
                        <input type="text" name="dish_name" class="form-control bg-light border-0"
                               placeholder="Optional: Name your dish (e.g., Custom Chicken Rice)">
                    </div>
                    <div class="col-md-6">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="save_favourite" id="saveFavCheck" value="1">
                            <label class="form-check-label fw-medium" for="saveFavCheck">
                                <i class="bi bi-heart-fill text-danger me-1"></i>Save this custom dish to My Favourites
                            </label>
                        </div>
                    </div>
                </div>
            </div>

            
            <div class="col-lg-4">
                <div class="kp-summary-panel sticky-top" style="top: 100px; z-index: 10;">
                    <h4 class="fw-extrabold text-center mb-4">Your Dish</h4>

                    <div class="mb-3">
                        <div class="fw-bold">Base</div>
                        <div id="summary-base" class="text-secondary">Rice</div>
                    </div>

                    <div class="mb-3">
                        <div class="fw-bold">Protein</div>
                        <div id="summary-protein" class="text-secondary">Chicken</div>
                    </div>

                    <div class="mb-3">
                        <div class="fw-bold">Ingredients</div>
                        <div id="summary-veggies" class="text-secondary">Onion, Tomato, Capsicum</div>
                    </div>

                    <div class="mb-3">
                        <div class="fw-bold">Sauce</div>
                        <div id="summary-sauce" class="text-secondary">Sweet and Sour</div>
                    </div>

                    <div class="small text-muted mb-3">
                        Includes Rs <?= (int) CUSTOM_DISH_BASE_PRICE ?> base preparation fee
                    </div>

                    <div class="mb-4">
                        <div class="fw-extrabold fs-5">Estimated Price</div>
                        <div id="summary-price" class="fw-extrabold fs-3">Rs 200</div>
                    </div>

                    <button type="submit" class="btn btn-dark btn-lg w-100 fw-bold py-3">
                        Add to Order
                    </button>
                </div>
            </div>
        </div>
    </form>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>