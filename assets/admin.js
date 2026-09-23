/* Events Booking Calendar — Admin JS */
jQuery(function ($) {

    // ── Color picker ─────────────────────────────────────────────────────────
    $('.ebc-color-picker').wpColorPicker();

    // ── Media library logo picker ─────────────────────────────────────────────
    $(document).on('click', '.ebc-upload-logo', function (e) {
        e.preventDefault();
        var $btn     = $(this);
        var $input   = $btn.siblings('.ebc-logo-url-input');
        var $preview = $btn.closest('td, .ebc-form-box, tr').find('.ebc-logo-preview');

        var frame = wp.media({
            title:   'Wybierz logo marki',
            button:  { text: 'Użyj tego obrazu' },
            multiple: false,
            library: { type: 'image' },
        });

        frame.on('select', function () {
            var attachment = frame.state().get('selection').first().toJSON();
            $input.val(attachment.url);
            $preview.attr('src', attachment.url).show();
        });

        frame.open();
    });

    // Update preview when URL is typed manually
    $(document).on('input', '.ebc-logo-url-input', function () {
        var url      = $(this).val();
        var $preview = $(this).closest('td, .ebc-form-box, tr').find('.ebc-logo-preview');
        if (url) { $preview.attr('src', url).show(); }
        else     { $preview.hide(); }
    });

});
