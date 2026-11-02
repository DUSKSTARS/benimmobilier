$(function () {
    const $feedback = $('<div class="favorite-ajax-feedback" role="status" aria-live="polite"></div>').appendTo('body');

    $(document).on('submit', '.favorite-form', function (event) {
        event.preventDefault();

        const $form = $(this);
        const propertyId = $form.find('[name="property_id"]').val();
        const $buttons = $('.favorite-form').filter(function () {
            return $(this).find('[name="property_id"]').val() === propertyId;
        }).find('button[type="submit"]');

        $buttons.prop('disabled', true);
        $feedback.text('');

        $.ajax({
            url: $form.attr('action'),
            method: 'POST',
            data: $form.serialize(),
            dataType: 'json',
            headers: { Accept: 'application/json' }
        }).done(function (response) {
            if (!response || response.success !== true || response.property_id !== Number(propertyId)) {
                $feedback.text('Le like n’a pas pu être mis à jour. Réessayez.');
                return;
            }

            const liked = response.liked === true;
            const count = Number(response.count);
            $buttons
                .toggleClass('is-favorite', liked)
                .attr('aria-pressed', liked ? 'true' : 'false')
                .attr('aria-label', liked ? 'Retirer des favoris' : 'Aimer cet article')
                .attr('title', liked ? 'Retirer des favoris' : 'Aimer cet article');
            $buttons.filter('.favorite-toggle-button')
                .toggleClass('button-dark', !liked)
                .toggleClass('button-outline', liked);

            $buttons.each(function () {
                const $button = $(this);
                $button.text($button.closest('.property-card').length
                    ? (liked ? '♥' : '♡')
                    : (liked ? '♥  Ne plus aimer' : '♡  J’aime cet article'));
            });

            $('[data-favorite-count]').filter(function () {
                return $(this).attr('data-favorite-count') === String(propertyId);
            }).text(count);

            $('[data-favorite-summary]').filter(function () {
                return $(this).attr('data-favorite-summary') === String(propertyId);
            }).find('[data-favorite-label]')
                .text(` personne${count === 1 ? '' : 's'} aime${count === 1 ? '' : 'nt'} ce bien`);

            if (!liked && $form.find('[name="return_to"]').val() === 'favoris.php') {
                const $card = $form.closest('.property-card');
                const $grid = $card.closest('.property-grid');
                $card.remove();
                if ($grid.length && $grid.children('.property-card').length === 0) {
                    $grid.replaceWith(
                        '<div class="empty-state"><span class="empty-icon">♡</span>' +
                        '<h3>Vos prochains coups de cœur sont ici.</h3>' +
                        '<p>Enregistrez une annonce pour la retrouver facilement.</p>' +
                        '<a class="button button-dark" href="index.php#annonces">Explorer les annonces</a></div>'
                    );
                }
            }
        }).fail(function () {
            $feedback.text('Une erreur est survenue. Vérifiez votre connexion et réessayez.');
        }).always(function () {
            $buttons.prop('disabled', false);
        });
    });
});
