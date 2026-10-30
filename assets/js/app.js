/**
 * Kree To Pla - Frontend jQuery & AJAX Module (loaded on every page by includes/footer.php)
 * All AJAX calls go to api/actions.php, which answers with JSON { success, message, ... }.
 *
 * Security Controls:
 *  - Automatically attaches the X-CSRF-Token header (from <meta name="csrf-token">) to every jQuery AJAX request
 *  - Uses .text() instead of .html() for server/user strings to prevent DOM XSS
 *  - Every AJAX feature has a plain form/link fallback, and the server re-validates everything
 */

$(function () {
    const API_URL = 'api/actions.php';
    const csrfToken = $('meta[name="csrf-token"]').attr('content');

    // Automatically attach CSRF token to every AJAX request (CSRF Mitigation)
    $.ajaxSetup({
        headers: {
            'X-CSRF-Token': csrfToken
        }
    });

    let toastTimer = null;
    function showToast(message, isError = false) {
        const $alert = $('#kp-ajax-alert');
        $alert
            .removeClass('d-none alert-dark alert-danger')
            .addClass(isError ? 'alert-danger' : 'alert-dark')
            .text(message); // Safe DOM manipulation (.text prevents XSS)
        clearTimeout(toastTimer);
        toastTimer = setTimeout(() => $alert.addClass('d-none'), 3200);
    }

    /** POST an action to the API. Resolves with the JSON body; shows a toast and rejects on any failure. */
    function api(action, data) {
        return $.post(API_URL, $.extend({ action: action }, data), null, 'json')
            .then(function (res) {
                if (res.require_login) {
                    window.location.href = 'auth.php';
                    return $.Deferred().reject(res);
                }
                if (!res.success) {
                    showToast(res.message || 'Something went wrong. Please try again.', true);
                    return $.Deferred().reject(res);
                }
                return res;
            }, function (xhr) {
                const res = xhr.responseJSON || {};
                showToast(res.message || 'Network error. Please check your connection and try again.', true);
                return $.Deferred().reject(res);
            });
    }

    /** Same format as kp_money() in PHP: "Rs 1,250" or "Rs 99.50". */
    function formatRs(amount) {
        const n = Number(amount) || 0;
        const decimals = n % 1 === 0 ? 0 : 2;
        return 'Rs ' + n.toLocaleString('en-US', { minimumFractionDigits: decimals, maximumFractionDigits: decimals });
    }

    function setCartBadge(count) {
        $('#kp-cart-badge').text(count);
    }

    // 1. Add Standard Dish to Cart via AJAX (home, menu, dish details, find food, favourites)
    $(document).on('click', '.js-add-to-cart', function (e) {
        e.preventDefault();
        const $btn = $(this);
        if ($btn.prop('disabled')) return;

        const qtyInput = $btn.closest('.kp-qty-scope').find('.js-dish-qty');
        const quantity = qtyInput.length ? parseInt(qtyInput.val(), 10) || 1 : 1;

        $btn.prop('disabled', true);
        api('add_to_cart', { dish_id: $btn.data('dish-id'), quantity: quantity })
            .done(function (res) {
                setCartBadge(res.cart_count);
                showToast(res.message || 'Dish added to your order!');
            })
            .always(function () {
                $btn.prop('disabled', false);
            });
    });

    // 2. Toggle Favourite Dish Heart via AJAX (menu, dish details, find food, favourites)
    $(document).on('click', '.js-toggle-fav', function (e) {
        e.preventDefault();
        const $btn = $(this);

        api('toggle_favourite', { dish_id: $btn.data('dish-id') }).done(function (res) {
            const $icon = $btn.find('i');
            if (res.favourited) {
                $icon.removeClass('bi-heart text-secondary').addClass('bi-heart-fill text-danger');
            } else {
                $icon.removeClass('bi-heart-fill text-danger').addClass('bi-heart text-secondary');
                if ($btn.data('remove-card')) {
                    $btn.closest('.js-fav-card-col').fadeOut(250, function () {
                        $(this).remove();
                    });
                }
            }
            showToast(res.message);
        });
    });

    // 3. Quantity +/- Buttons on Dish Detail
    $(document).on('click', '.js-qty-step', function () {
        const step = parseInt($(this).data('step'), 10);
        const $input = $(this).closest('.kp-qty-box').find('.js-dish-qty');
        let current = parseInt($input.val(), 10) || 1;
        current = Math.max(1, Math.min(20, current + step));
        $input.val(current);
    });

    // 4. Live Search Filter on Find Food Ingredient Checkboxes
    $('#js-ingredient-filter-input').on('keyup', function () {
        const query = $(this).val().toLowerCase().trim();
        $('.js-ingredient-item').each(function () {
            const name = String($(this).data('name')).toLowerCase();
            $(this).toggle(name.indexOf(query) !== -1);
        });
    });

    // 5. Build a Custom Dish Live Price & Summary Calculator
    function updateCustomDishSummary() {
        const $form = $('#js-build-dish-form');
        if (!$form.length) return;

        const basePrice = parseFloat($form.data('base-price')) || 45.0;
        let total = basePrice;
        let bases = [], proteins = [], veggies = [], sauces = [];

        $form.find('input[name="ingredients[]"]:checked').each(function () {
            const iName = $(this).data('name');
            const iCat  = $(this).data('category');
            const iPrice = parseFloat($(this).data('price')) || 0;
            total += iPrice;

            if (iCat === 'Base') bases.push(iName);
            else if (iCat === 'Protein') proteins.push(iName);
            else if (iCat === 'Sauce') sauces.push(iName);
            else veggies.push(iName);
        });

        $('#summary-base').text(bases.length ? bases.join(', ') : 'None selected');
        $('#summary-protein').text(proteins.length ? proteins.join(', ') : 'None selected');
        $('#summary-veggies').text(veggies.length ? veggies.join(', ') : 'None selected');
        $('#summary-sauce').text(sauces.length ? sauces.join(', ') : 'None selected');
        $('#summary-price').text('Rs ' + total.toFixed(0));
    }

    $(document).on('change', '#js-build-dish-form input[name="ingredients[]"]', updateCustomDishSummary);
    updateCustomDishSummary();

    // 6. Cart page (cart.php): − / + / remove without reloading, live Order Summary totals
    const $checkoutForm = $('#kp-checkout-form');
    let cartSubtotal = null; // set from the server after the first cart change

    function deliveryFee() {
        return $('#kp-pickup').is(':checked') ? 0 : Number($checkoutForm.data('delivery-fee')) || 0;
    }

    function renderCartTotals() {
        if (!$checkoutForm.length) return;
        const subtotal = cartSubtotal !== null
            ? cartSubtotal
            : Number($('#kp-subtotal').text().replace(/[^0-9.]/g, '')) || 0;
        $('#kp-subtotal').text(formatRs(subtotal));
        $('#kp-delivery-fee').text(formatRs(deliveryFee()));
        $('#kp-total').text(formatRs(subtotal + deliveryFee()));
    }

    function applyCartChange($row, res) {
        cartSubtotal = Number(res.subtotal) || 0;
        setCartBadge(res.cart_count);

        if (res.cart_count === 0) {
            window.location.reload(); // show the empty-cart state
            return;
        }
        if (res.item) {
            $row.find('.js-cart-qty').text(res.item.quantity);
            $row.find('.js-line-total').text(formatRs(res.item.line_total));
            $row.find('.js-cart-step[data-step="1"]').prop('disabled', res.item.quantity >= 20);
        } else {
            $row.slideUp(200, function () {
                $(this).remove();
            });
            showToast(res.message);
        }
        renderCartTotals();
    }

    $(document).on('click', '.js-cart-step', function (e) {
        e.preventDefault();
        const $row = $(this).closest('.js-cart-row');
        const current = parseInt($row.find('.js-cart-qty').text(), 10) || 0;
        const quantity = current + parseInt($(this).data('step'), 10);

        $row.addClass('is-busy');
        api('update_cart_qty', { cart_key: $row.data('cart-key'), quantity: quantity })
            .done(function (res) { applyCartChange($row, res); })
            .always(function () { $row.removeClass('is-busy'); });
    });

    $(document).on('click', '.js-cart-remove', function (e) {
        e.preventDefault();
        const $row = $(this).closest('.js-cart-row');

        $row.addClass('is-busy');
        api('remove_cart_item', { cart_key: $row.data('cart-key') })
            .done(function (res) { applyCartChange($row, res); })
            .always(function () { $row.removeClass('is-busy'); });
    });

    // Pickup switch: hide/disable the address field and drop the delivery fee from the summary
    $('#kp-pickup').on('change', function () {
        const pickup = this.checked;
        $('#kp-address-group').toggleClass('d-none', pickup);
        $('#kp-delivery-address').prop({ disabled: pickup, required: !pickup });
        renderCartTotals();
    });

    // Prevent double orders from repeated clicks on "Place Order"
    $checkoutForm.on('submit', function () {
        $(this).find('.js-place-order').prop('disabled', true).text('Placing order…');
    });

    // 7. Confirm destructive form submissions (e.g. Cancel Order on orders.php)
    $(document).on('submit', 'form[data-confirm]', function (e) {
        if (!window.confirm($(this).data('confirm'))) {
            e.preventDefault();
        }
    });

    // 8. Live order tracking (orders.php): poll the selected order's status until it is final
    const $tracker = $('#js-order-tracker');
    const FINAL_STATUSES = ['delivered', 'completed', 'cancelled'];

    function renderOrderProgress(res) {
        $('.js-order-status[data-order-id="' + res.order_id + '"]').text(res.label);
        $('#js-order-eta').text(res.eta);
        $tracker.find('.js-timeline-step').each(function () {
            const rank = parseInt($(this).data('rank'), 10);
            $(this)
                .toggleClass('completed', rank <= res.rank)
                .toggleClass('active', rank === res.rank)
                .toggleClass('upcoming', rank > res.rank);
        });
        if (!res.can_modify) {
            $('#js-order-actions').remove();
        }
    }

    if ($tracker.length && FINAL_STATUSES.indexOf($tracker.data('status')) === -1) {
        const orderId = $tracker.data('order-id');
        let lastStatus = $tracker.data('status');

        const poll = setInterval(function () {
            if (document.hidden) return; // don't poll from background tabs

            $.post(API_URL, { action: 'check_order_status', order_id: orderId }, null, 'json').done(function (res) {
                if (!res.success) return;
                if (res.status === 'cancelled') {
                    window.location.reload(); // cancelled orders use a different panel layout
                    return;
                }
                renderOrderProgress(res); // also hides Edit/Cancel once the cut-off passes
                if (res.status !== lastStatus) {
                    lastStatus = res.status;
                    showToast('Order #' + res.order_id + ' is now: ' + res.label);
                }
                if (res.is_final) clearInterval(poll);
            });
        }, 15000);
    }
});
