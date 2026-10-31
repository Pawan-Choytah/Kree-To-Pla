/**
 * Kree To Pla - Frontend jQuery UI Module (Semester 1 scope)
 *
 * Visual UI behaviour ONLY - this file makes NO network requests.
 * All data submission (cart, favourites) is done with standard HTML <form method="POST">
 * submissions handled by process_action.php, followed by a PHP redirect.
 *
 * Security: untrusted strings are written with .text() (never .html()) to prevent DOM XSS.
 */

$(function () {

    // 1. Quantity +/- Buttons on Dish Detail (visual only - value is submitted by the surrounding <form>)
    $(document).on('click', '.js-qty-step', function () {
        const step = parseInt($(this).data('step'), 10);
        const $input = $(this).closest('.kp-qty-box').find('.js-dish-qty');
        let current = parseInt($input.val(), 10) || 1;
        current = Math.max(1, Math.min(20, current + step));
        $input.val(current);
    });

    // 2. Live Search Filter on Find Food Ingredient Checkboxes
    $('#js-ingredient-filter-input').on('keyup', function () {
        const query = $(this).val().toLowerCase().trim();
        $('.js-ingredient-item').each(function () {
            const name = $(this).data('name').toLowerCase();
            $(this).toggle(name.indexOf(query) !== -1);
        });
    });

    // 3. Build a Custom Dish Live Price & Summary Calculator
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
});

