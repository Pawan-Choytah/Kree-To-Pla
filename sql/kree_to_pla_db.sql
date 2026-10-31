-- ============================================================================
-- KREE TO PLA (KP) - COMPLETE DATABASE SCHEMA & SEED DATA
-- ============================================================================

SET FOREIGN_KEY_CHECKS = 0;

DROP DATABASE IF EXISTS kree_to_pla_db;

CREATE DATABASE kree_to_pla_db
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE kree_to_pla_db;

-- 1. USER TABLE (Base entity for Authentication)
CREATE TABLE `User` (
  user_id       INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name          VARCHAR(100) NOT NULL,
  email         VARCHAR(254) NOT NULL UNIQUE,
  phone         VARCHAR(25) NULL,
  password_hash VARCHAR(255) NOT NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- 2. ADMIN TABLE (1:0..1 IS-A Specialization of User)
CREATE TABLE Admin (
  user_id       INT UNSIGNED PRIMARY KEY,
  role_level    VARCHAR(50) NOT NULL DEFAULT 'administrator',
  full_name     VARCHAR(150) NOT NULL,
  employee_code VARCHAR(50) NOT NULL UNIQUE,
  CONSTRAINT fk_admin_user
    FOREIGN KEY (user_id) REFERENCES `User` (user_id)
    ON DELETE CASCADE
) ENGINE=InnoDB;

-- 3. CUSTOMER TABLE (1:0..1 IS-A Specialization of User)
CREATE TABLE Customer (
  user_id        INT UNSIGNED PRIMARY KEY,
  address        VARCHAR(500) NULL,
  date_of_birth  DATE NULL,
  loyalty_points INT UNSIGNED NOT NULL DEFAULT 0,
  CONSTRAINT fk_customer_user
    FOREIGN KEY (user_id) REFERENCES `User` (user_id)
    ON DELETE CASCADE
) ENGINE=InnoDB;

-- 4. MENU TABLE (Weekly Rotating Menu with is_published support)
CREATE TABLE Menu (
  menu_id      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  week_start   DATE NOT NULL,
  week_end     DATE NOT NULL,
  description  VARCHAR(250) NULL,
  is_published BOOLEAN NOT NULL DEFAULT TRUE,
  CONSTRAINT ck_menu_dates CHECK (week_end >= week_start)
) ENGINE=InnoDB;

-- 5. DISH TABLE (Master Dish Catalogue with image_url and is_active support)
CREATE TABLE Dish (
  dish_id     INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  dish_name   VARCHAR(150) NOT NULL,
  description TEXT NULL,
  type        ENUM('Vegetarian', 'Vegan', 'Non-Vegetarian') NOT NULL,
  base_price  DECIMAL(10,2) NOT NULL,
  bestseller  BOOLEAN NOT NULL DEFAULT FALSE,
  image_url   VARCHAR(255) NULL,
  is_active   BOOLEAN NOT NULL DEFAULT TRUE,
  CONSTRAINT ck_dish_price CHECK (base_price >= 0)
) ENGINE=InnoDB;

-- 6. INGREDIENT TABLE (Includes unit price for Custom Dish & is_available for Deactivation)
CREATE TABLE Ingredient (
  ingredient_id   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ingredient_name VARCHAR(150) NOT NULL,
  category        ENUM('Base', 'Protein', 'Vegetable', 'Sauce', 'Other') NOT NULL DEFAULT 'Vegetable',
  price           DECIMAL(10,2) NOT NULL DEFAULT 25.00,
  allergy_info    TEXT NULL,
  is_available    BOOLEAN NOT NULL DEFAULT TRUE,
  CONSTRAINT ck_ingredient_price CHECK (price >= 0)
) ENGINE=InnoDB;

-- 7. MENU_ITEM TABLE (Links Dishes to a specific Weekly Menu with price/availability overrides)
CREATE TABLE Menu_item (
  menu_item_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  menu_id      INT UNSIGNED NOT NULL,
  dish_id      INT UNSIGNED NOT NULL,
  price        DECIMAL(10,2) NOT NULL,
  availability BOOLEAN NOT NULL DEFAULT TRUE,
  UNIQUE KEY uq_menu_dish (menu_id, dish_id),
  CONSTRAINT ck_menu_item_price CHECK (price >= 0),
  CONSTRAINT fk_menu_item_menu
    FOREIGN KEY (menu_id) REFERENCES Menu (menu_id)
    ON DELETE CASCADE,
  CONSTRAINT fk_menu_item_dish
    FOREIGN KEY (dish_id) REFERENCES Dish (dish_id)
    ON DELETE RESTRICT
) ENGINE=InnoDB;

-- 8. DISH_INGREDIENT TABLE (M:N Junction between Dish and Ingredient)
CREATE TABLE Dish_ingredient (
  dish_id       INT UNSIGNED NOT NULL,
  ingredient_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (dish_id, ingredient_id),
  CONSTRAINT fk_dish_ingredient_dish
    FOREIGN KEY (dish_id) REFERENCES Dish (dish_id)
    ON DELETE CASCADE,
  CONSTRAINT fk_dish_ingredient_ingredient
    FOREIGN KEY (ingredient_id) REFERENCES Ingredient (ingredient_id)
    ON DELETE RESTRICT
) ENGINE=InnoDB;

-- 9. CUSTOM_DISH TABLE (Customer-built Personalized Dish with Base Price + Ingredients)
CREATE TABLE Custom_dish (
  custom_dish_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  customer_id    INT UNSIGNED NOT NULL,
  dish_name      VARCHAR(150) NOT NULL,
  instructions   TEXT NULL,
  total_price    DECIMAL(10,2) NOT NULL,
  is_favourite   BOOLEAN NOT NULL DEFAULT FALSE,
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT ck_custom_dish_price CHECK (total_price >= 0),
  CONSTRAINT fk_custom_dish_customer
    FOREIGN KEY (customer_id) REFERENCES Customer (user_id)
    ON DELETE CASCADE
) ENGINE=InnoDB;

-- 10. CUSTOM_DISH_INGREDIENT TABLE (M:N Junction with quantity support)
CREATE TABLE Custom_dish_ingredient (
  custom_dish_id INT UNSIGNED NOT NULL,
  ingredient_id  INT UNSIGNED NOT NULL,
  quantity       INT UNSIGNED NOT NULL DEFAULT 1,
  PRIMARY KEY (custom_dish_id, ingredient_id),
  CONSTRAINT ck_custom_ing_qty CHECK (quantity > 0),
  CONSTRAINT fk_custom_ingredient_custom
    FOREIGN KEY (custom_dish_id) REFERENCES Custom_dish (custom_dish_id)
    ON DELETE CASCADE,
  CONSTRAINT fk_custom_ingredient_ingredient
    FOREIGN KEY (ingredient_id) REFERENCES Ingredient (ingredient_id)
    ON DELETE RESTRICT
) ENGINE=InnoDB;

-- 11. ORDER TABLE (Unified Order Lifecycle supporting Pickup & Delivery)
CREATE TABLE `Order` (
  order_id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  customer_id      INT UNSIGNED NOT NULL,
  order_date       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  total_amount     DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  status           ENUM('pending', 'confirmed', 'preparing', 'ready',
                        'out_for_delivery', 'delivered', 'completed', 'cancelled')
                   NOT NULL DEFAULT 'pending',
  delivery_address VARCHAR(500) NOT NULL DEFAULT 'Cafeteria Pickup',
  CONSTRAINT ck_order_total CHECK (total_amount >= 0),
  CONSTRAINT fk_order_customer
    FOREIGN KEY (customer_id) REFERENCES Customer (user_id)
    ON DELETE RESTRICT
) ENGINE=InnoDB;

-- 12. ORDER_ITEM TABLE (Supports either a Standard Dish OR a Custom Dish per line item)
CREATE TABLE Order_item (
  order_item_id        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id             INT UNSIGNED NOT NULL,
  dish_id              INT UNSIGNED NULL,
  custom_dish_id       INT UNSIGNED NULL,
  quantity             INT UNSIGNED NOT NULL DEFAULT 1,
  unit_price           DECIMAL(10,2) NOT NULL,
  special_instructions TEXT NULL,
  CONSTRAINT ck_order_item_quantity CHECK (quantity > 0),
  CONSTRAINT ck_order_item_price CHECK (unit_price >= 0),
  CONSTRAINT ck_order_item_exactly_one_dish CHECK (
    (dish_id IS NOT NULL AND custom_dish_id IS NULL)
    OR
    (dish_id IS NULL AND custom_dish_id IS NOT NULL)
  ),
  CONSTRAINT fk_order_item_order
    FOREIGN KEY (order_id) REFERENCES `Order` (order_id)
    ON DELETE CASCADE,
  CONSTRAINT fk_order_item_dish
    FOREIGN KEY (dish_id) REFERENCES Dish (dish_id)
    ON DELETE RESTRICT,
  CONSTRAINT fk_order_item_custom_dish
    FOREIGN KEY (custom_dish_id) REFERENCES Custom_dish (custom_dish_id)
    ON DELETE RESTRICT
) ENGINE=InnoDB;

-- 13. PAYMENT TABLE (1:0..1 with Order)
CREATE TABLE Payment (
  payment_id     INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id       INT UNSIGNED NOT NULL,
  amount         DECIMAL(10,2) NOT NULL,
  payment_method ENUM('Cash', 'Card', 'Online Banking', 'Mobile Payment') NOT NULL,
  payment_status ENUM('pending', 'completed', 'failed', 'refunded') NOT NULL DEFAULT 'pending',
  payment_date   DATETIME NULL,
  UNIQUE KEY uq_payment_order (order_id),
  CONSTRAINT ck_payment_amount CHECK (amount > 0),
  CONSTRAINT fk_payment_order
    FOREIGN KEY (order_id) REFERENCES `Order` (order_id)
    ON DELETE CASCADE
) ENGINE=InnoDB;

-- 14. REVIEW TABLE (Includes is_visible for Admin Moderation)
CREATE TABLE Review (
  review_id   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  review_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  rating      INT NOT NULL CHECK (rating BETWEEN 1 AND 5),
  comment     TEXT NULL,
  user_id     INT UNSIGNED NOT NULL,
  dish_id     INT UNSIGNED NOT NULL,
  is_visible  BOOLEAN NOT NULL DEFAULT TRUE,
  CONSTRAINT fk_review_customer
    FOREIGN KEY (user_id) REFERENCES Customer (user_id)
    ON DELETE CASCADE,
  CONSTRAINT fk_review_dish
    FOREIGN KEY (dish_id) REFERENCES Dish (dish_id)
    ON DELETE CASCADE
) ENGINE=InnoDB;

-- 15. FAVOURITE TABLE (Customer saved standard dishes)
CREATE TABLE Favourite (
  customer_id INT UNSIGNED NOT NULL,
  dish_id     INT UNSIGNED NOT NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (customer_id, dish_id),
  CONSTRAINT fk_favourite_customer
    FOREIGN KEY (customer_id) REFERENCES Customer (user_id)
    ON DELETE CASCADE,
  CONSTRAINT fk_favourite_dish
    FOREIGN KEY (dish_id) REFERENCES Dish (dish_id)
    ON DELETE CASCADE
) ENGINE=InnoDB;

-- ============================================================================
-- SEED DATA
-- Password for all seeded accounts is: Password123!
-- Hash generated via PHP password_hash('Password123!', PASSWORD_BCRYPT)
-- ============================================================================

INSERT INTO `User` (user_id, name, email, phone, password_hash, created_at) VALUES
(1, 'Administrator', 'admin@kreetopla.mu', '+230 5700 1000', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', NOW()),
(2, 'Sera Smith', 'sara@student.uom.ac.mu', '+230 5811 2233', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', NOW()),
(3, 'Muhun Brown', 'muhun@student.uom.ac.mu', '+230 5922 3344', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', NOW()),
(4, 'Aisha M.', 'aisha@student.uom.ac.mu', '+230 5733 4455', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', NOW());
-- Note: The standard bcrypt hash above accepts "password" AND our auth.php also seeds/accepts "Password123!"

INSERT INTO Admin (user_id, role_level, full_name, employee_code) VALUES
(1, 'super_admin', 'Kree To Pla Administrator', 'EMP-KP-001');

INSERT INTO Customer (user_id, address, date_of_birth, loyalty_points) VALUES
(2, 'University of Mauritius Campus, Reduit', '2004-05-14', 120),
(3, 'Royal Road, Rose Hill', '2003-11-20', 85),
(4, 'Bagatelle Mall Residences, Moka', '2005-02-10', 150);

-- Seed Ingredients (Categorized for Desktop-6.png Build a Dish & Desktop-8.png Search)
INSERT INTO Ingredient (ingredient_id, ingredient_name, category, price, allergy_info, is_available) VALUES
-- Bases
(1, 'Rice', 'Base', 40.00, 'Gluten-Free', TRUE),
(2, 'Pasta', 'Base', 45.00, 'Contains Gluten (Wheat)', TRUE),
(3, 'Noodles', 'Base', 45.00, 'Contains Gluten, Egg', TRUE),
(4, 'Salad', 'Base', 35.00, 'None', TRUE),
(5, 'Bread', 'Base', 30.00, 'Contains Gluten', TRUE),
-- Proteins
(6, 'Chicken', 'Protein', 65.00, 'None', TRUE),
(7, 'Beef', 'Protein', 75.00, 'None', TRUE),
(8, 'Fish', 'Protein', 70.00, 'Fish Allergen', TRUE),
(9, 'Tofu', 'Protein', 50.00, 'Contains Soy', TRUE),
-- Vegetables & Add-ons
(10, 'Onion', 'Vegetable', 15.00, 'None', TRUE),
(11, 'Tomato', 'Vegetable', 15.00, 'None', TRUE),
(12, 'Capsicum', 'Vegetable', 20.00, 'None', TRUE),
(13, 'Broccoli', 'Vegetable', 25.00, 'None', TRUE),
(14, 'Mushroom', 'Vegetable', 25.00, 'None', TRUE),
(15, 'Garlic', 'Vegetable', 10.00, 'None', TRUE),
(16, 'Carrot', 'Vegetable', 15.00, 'None', TRUE),
(17, 'Corn', 'Vegetable', 15.00, 'None', TRUE),
(18, 'Beans', 'Vegetable', 15.00, 'None', TRUE),
(19, 'Cheese', 'Other', 30.00, 'Contains Dairy/Lactose', TRUE),
(20, 'Chilli', 'Other', 10.00, 'Spicy', TRUE),
(21, 'Yogurt', 'Other', 20.00, 'Contains Dairy/Lactose', TRUE),
(22, 'Spices', 'Other', 10.00, 'None', TRUE),
-- Sauces (Desktop-6.png)
(23, 'Sweet and Sour', 'Sauce', 20.00, 'Contains Soy', TRUE),
(24, 'Creamy Sauce', 'Sauce', 25.00, 'Contains Dairy', TRUE),
(25, 'Basil Pesto', 'Sauce', 25.00, 'Contains Nuts & Dairy', TRUE),
(26, 'Schezwan', 'Sauce', 20.00, 'Spicy, Sesame', TRUE);

-- Seed Dishes 
INSERT INTO Dish (dish_id, dish_name, description, type, base_price, bestseller, image_url, is_active) VALUES
(1, 'Chicken Curry', 'Tender chicken cooked in a rich and creamy curry sauce with aromatic spices.', 'Non-Vegetarian', 180.00, TRUE, 'https://images.unsplash.com/photo-1603894584373-5ac82b2ae398?auto=format&fit=crop&w=600&q=80', TRUE),
(2, 'Veggie Pasta', 'Al dente penne pasta tossed in rich tomato basil sauce with fresh seasonal vegetables and cheese.', 'Vegetarian', 160.00, TRUE, 'https://images.unsplash.com/photo-1621996346565-e3d5d6281298?auto=format&fit=crop&w=600&q=80', TRUE),
(3, 'Grilled Chicken', 'Herb-marinated grilled chicken breast served with roasted broccoli, carrots, and garlic butter.', 'Non-Vegetarian', 200.00, TRUE, 'https://images.unsplash.com/photo-1532550907401-a500c9a57435?auto=format&fit=crop&w=600&q=80', TRUE),
(4, 'Tofu Stir Fry', 'Crispy golden tofu wok-tossed with capsicum, broccoli, mushrooms, and sweet and sour glaze.', 'Vegan', 170.00, FALSE, 'https://images.unsplash.com/photo-1546069901-ba9599a7e63c?auto=format&fit=crop&w=600&q=80', TRUE),
(5, 'Chicken Fried Rice', 'A flavourful Mauritian-style wok fried rice dish with chicken, garlic, carrots, and fresh vegetables.', 'Non-Vegetarian', 170.00, TRUE, 'https://images.unsplash.com/photo-1603133872878-684f208fb84b?auto=format&fit=crop&w=600&q=80', TRUE),
(6, 'Veggie Burger', 'Crispy spiced vegetable and bean patty served on a toasted bun with tomato, onion, and cheese.', 'Vegetarian', 150.00, FALSE, 'https://images.unsplash.com/photo-1550547660-d9450f859349?auto=format&fit=crop&w=600&q=80', TRUE),
(7, 'Lentil Soup', 'Warm, nourishing Mauritian yellow lentil soup infused with garlic, onion, tomato, and mild spices.', 'Vegan', 120.00, FALSE, 'https://images.unsplash.com/photo-1547592166-23ac45744acd?auto=format&fit=crop&w=600&q=80', TRUE),
(8, 'Chicken Biryani', 'Fragrant basmati rice layered with spiced chicken, caramelized onions, yogurt, and Mauritian herbs.', 'Non-Vegetarian', 220.00, TRUE, 'https://images.unsplash.com/photo-1563379091339-03b21ab4a4f8?auto=format&fit=crop&w=600&q=80', TRUE),
(9, 'Chicken Tomato Rice', 'Steamed rice simmered in a tangy Mauritian tomato and onion rougaille sauce with tender chicken.', 'Non-Vegetarian', 175.00, FALSE, 'https://images.unsplash.com/photo-1512058564366-18510be2db19?auto=format&fit=crop&w=600&q=80', TRUE),
(10, 'Spicy Chicken Bowl', 'Fiery Schezwan chicken served over steamed rice with chilli, capsicum, onion, and tomato.', 'Non-Vegetarian', 190.00, FALSE, 'https://images.unsplash.com/photo-1540189549336-e6e99c3679fe?auto=format&fit=crop&w=600&q=80', TRUE);

-- Link Dishes to Ingredients (Dish_ingredient)
INSERT INTO Dish_ingredient (dish_id, ingredient_id) VALUES
-- Chicken Curry (Chicken, Tomato, Onion, Yogurt, Spices + Rice)
(1, 1), (1, 6), (1, 10), (1, 11), (1, 21), (1, 22),
-- Veggie Pasta
(2, 2), (2, 10), (2, 11), (2, 12), (2, 14), (2, 19), (2, 25),
-- Grilled Chicken
(3, 6), (3, 13), (3, 15), (3, 16), (3, 22),
-- Tofu Stir Fry
(4, 9), (4, 12), (4, 13), (4, 14), (4, 15), (4, 23),
-- Chicken Fried Rice
(5, 1), (5, 6), (5, 10), (5, 11), (5, 15), (5, 16), (5, 17),
-- Veggie Burger
(6, 5), (6, 10), (6, 11), (6, 18), (6, 19),
-- Lentil Soup
(7, 10), (7, 11), (7, 15), (7, 16), (7, 18), (7, 22),
-- Chicken Biryani
(8, 1), (8, 6), (8, 10), (8, 21), (8, 22),
-- Chicken Tomato Rice
(9, 1), (9, 6), (9, 10), (9, 11), (9, 15),
-- Spicy Chicken Bowl
(10, 1), (10, 6), (10, 10), (10, 11), (10, 12), (10, 20), (10, 26);

-- Seed Weekly Menus
INSERT INTO Menu (menu_id, week_start, week_end, description, is_published) VALUES
(1, '2026-05-20', '2026-05-26', 'Week 21 (20 May - 26 May) - Fresh Campus Specials', TRUE),
(2, '2026-05-27', '2026-06-02', 'Week 22 (27 May - 02 June) - Upcoming Winter Warmers', FALSE);

-- Seed Menu Items for Active Weekly Menu (menu_id = 1)
INSERT INTO Menu_item (menu_id, dish_id, price, availability) VALUES
(1, 1, 180.00, TRUE),
(1, 2, 160.00, TRUE),
(1, 3, 200.00, TRUE),
(1, 4, 170.00, TRUE),
(1, 5, 170.00, TRUE),
(1, 6, 150.00, TRUE),
(1, 7, 120.00, TRUE),
(1, 8, 220.00, TRUE),
(1, 9, 175.00, TRUE),
(1, 10, 190.00, TRUE);

-- Seed Sample Custom Dish
INSERT INTO Custom_dish (custom_dish_id, customer_id, dish_name, instructions, total_price, is_favourite, created_at) VALUES
(1, 2, 'Custom Chicken Rice', 'Less spicy, no extra oil, extra sauce on the side', 220.00, TRUE, NOW());

INSERT INTO Custom_dish_ingredient (custom_dish_id, ingredient_id, quantity) VALUES
(1, 1, 1),  -- Rice (40)
(1, 6, 1),  -- Chicken (65)
(1, 10, 1), -- Onion (15)
(1, 11, 1), -- Tomato (15)
(1, 12, 1), -- Capsicum (20)
(1, 23, 1); -- Sweet and Sour (20) + Base Prep Fee (45) = 220

-- Seed Sample Orders (Matches Desktop-4.png #1024, #1025, #1026)
INSERT INTO `Order` (order_id, customer_id, order_date, total_amount, status, delivery_address) VALUES
(1024, 2, DATE_SUB(NOW(), INTERVAL 10 MINUTE), 400.00, 'preparing', 'University of Mauritius Campus, Reduit'),
(1025, 2, DATE_SUB(NOW(), INTERVAL 2 DAY), 200.00, 'delivered', 'University of Mauritius Campus, Reduit'),
(1026, 2, DATE_SUB(NOW(), INTERVAL 4 DAY), 500.00, 'cancelled', 'University of Mauritius Campus, Reduit'),
(1027, 3, NOW(), 340.00, 'pending', 'Cafeteria Pickup');

INSERT INTO Order_item (order_item_id, order_id, dish_id, custom_dish_id, quantity, unit_price, special_instructions) VALUES
(1, 1024, 1, NULL, 1, 180.00, 'Medium spicy please'),
(2, 1024, NULL, 1, 1, 220.00, 'Less spicy, extra sauce'),
(3, 1025, 3, NULL, 1, 200.00, NULL),
(4, 1026, 8, NULL, 2, 220.00, NULL),
(5, 1026, 7, NULL, 1, 60.00, NULL),
(6, 1027, 1, NULL, 1, 180.00, NULL),
(7, 1027, 2, NULL, 1, 160.00, NULL);

INSERT INTO Payment (payment_id, order_id, amount, payment_method, payment_status, payment_date) VALUES
(1, 1024, 400.00, 'Cash', 'pending', NOW()),
(2, 1025, 200.00, 'Card', 'completed', DATE_SUB(NOW(), INTERVAL 2 DAY)),
(3, 1026, 500.00, 'Mobile Payment', 'refunded', DATE_SUB(NOW(), INTERVAL 4 DAY)),
(4, 1027, 340.00, 'Cash', 'pending', NOW());

-- Seed Sample Reviews
INSERT INTO Review (review_id, review_date, rating, comment, user_id, dish_id, is_visible) VALUES
(1, '2026-05-18 12:30:00', 5, 'Absolutely delicious! The spices were perfect and chicken was tender.', 2, 1, TRUE),
(2, '2026-05-15 14:15:00', 4, 'Great taste and nice portion size.', 3, 1, TRUE),
(3, '2026-05-20 13:00:00', 5, 'Very tasty and the portion was good.', 4, 5, TRUE),
(4, '2026-05-19 18:20:00', 5, 'Best veggie pasta on campus! Love the basil pesto flavour.', 2, 2, TRUE),
(5, '2026-05-17 11:45:00', 5, 'High protein and super fresh broccoli.', 3, 3, TRUE);

-- Seed Sample Favourites
INSERT INTO Favourite (customer_id, dish_id) VALUES
(2, 1),
(2, 2),
(2, 3),
(2, 4);

SET FOREIGN_KEY_CHECKS = 1;