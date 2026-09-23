/* Events Booking Calendar — Frontend JS v2.2 */
jQuery(function ($) {

    var $content   = $('#ebc-calendar-content');
    var $modal     = $('#ebc-modal');
    var modalMode  = 'booking'; // 'booking' | 'waitlist'

    // ── FIX #2: Move modal to <html> root — escapes ALL theme stacking contexts
    // (position:fixed breaks when ANY ancestor has transform/filter/will-change)
    if ($modal.length) {
        $modal.detach().appendTo('html');
    }

    // ── FIX #3: Seat counts — always refresh via AJAX on page load ────────────
    refreshSeats();

    // ── FIX #1: Month navigation — pure AJAX, no page reload needed ──────────
    // Brand filter change
    $(document).on('change', '#ebc-brand-filter', function () {
        var brand = parseInt($(this).val() || 0, 10);
        var year  = parseInt($content.data('year'),  10);
        var month = parseInt($content.data('month'), 10);
        $content.data('brand', brand);
        loadMonth(year, month, brand);
    });

    // Prev / Next buttons
    $(document).on('click', '.ebc-month-nav', function (e) {
        e.preventDefault();
        e.stopImmediatePropagation(); // prevent any theme JS from interfering
        var monthStr = String($(this).data('month'));
        var parts    = monthStr.split('-');
        var year     = parseInt(parts[0], 10);
        var month    = parseInt(parts[1], 10);
        var brand    = parseInt($content.data('brand') || 0, 10);
        loadMonth(year, month, brand);
    });

    function loadMonth(year, month, brand) {
        $content.css({ opacity: '0.4', pointerEvents: 'none' });

        $.post(ebc_ajax.ajax_url, {
            action: 'ebc_load_month',
            year:   year,
            month:  month,
            brand:  brand,
            nonce:  ebc_ajax.nonce,
        })
        .done(function (res) {
            $content.css({ opacity: '1', pointerEvents: '' });
            if (!res.success) return;

            // Replace calendar + event list
            $content.html(res.data.html);

            // Update month title
            $('#ebc-month-title').text(res.data.title);

            // Update nav buttons' data-month
            var $navBtns = $('.ebc-month-nav');
            $navBtns.first().data('month', res.data.prev_month);
            $navBtns.last().data('month',  res.data.next_month);

            // Store current state on content div
            $content.data('year', year).data('month', month);

            // Refresh seat counts for newly rendered events
            refreshSeats();
        })
        .fail(function () {
            $content.css({ opacity: '1', pointerEvents: '' });
        });
    }

    // ── Seat count refresh via AJAX ───────────────────────────────────────────
    function refreshSeats() {
        var ids = [];
        $content.find('[data-event-id]').each(function () {
            var id = parseInt($(this).data('event-id'), 10);
            if (id && ids.indexOf(id) === -1) ids.push(id);
        });
        if (!ids.length) return;

        $.post(ebc_ajax.ajax_url, { action: 'ebc_get_seats', ids: ids, nonce: ebc_ajax.nonce })
        .done(function (res) {
            if (!res.success) return;
            $.each(res.data, function (id, seats) {
                id = parseInt(id, 10);
                var fillPct  = seats.max > 0 ? Math.round((seats.max - seats.avail) / seats.max * 100) : 100;
                var barClass = seats.avail <= 0 ? 'ebc-bar-full'
                             : (seats.avail / Math.max(1, seats.max) > 0.5 ? 'ebc-bar-ok'
                             : (seats.avail / Math.max(1, seats.max) > 0.1 ? 'ebc-bar-low'
                             : 'ebc-bar-critical'));
                var barHtml  = '<div class="ebc-seats-bar ' + barClass + '">'
                             + '<div class="ebc-seats-bar-fill" style="width:' + fillPct + '%"></div></div>';

                // Update seats-info div
                var $si = $content.find('.ebc-seats-info[data-event-id="' + id + '"]');
                if (seats.avail > 0) {
                    $si.html(barHtml + '<span class="ebc-seats-avail">' + seats.avail + '&nbsp;/&nbsp;' + seats.max + ' wolnych</span>');
                } else {
                    $si.html(barHtml + '<span class="ebc-seats-full">Brak miejsc</span>');
                }

                // Update book button
                var $btn = $content.find('.ebc-btn-book[data-event-id="' + id + '"]');
                // Update / show waitlist button
                var $wBtn = $content.find('.ebc-btn-waitlist[data-event-id="' + id + '"]');
                if (seats.avail > 0) {
                    $btn.prop('disabled', false)
                        .removeClass('ebc-btn-no-seats')
                        .text('Zarezerwuj miejsce')
                        .data('max-seats', seats.avail)
                        .show();
                    $wBtn.hide();
                } else {
                    $btn.prop('disabled', true)
                        .addClass('ebc-btn-no-seats')
                        .text('Brak wolnych miejsc');
                    $wBtn.show();
                }

                // Update calendar grid cell
                var $calEv = $content.find('.ebc-calendar [data-event-id="' + id + '"]');
                if (seats.avail <= 0) {
                    $calEv.addClass('ebc-sold-out').find('.ebc-tag-full').remove();
                    if (!$calEv.find('.ebc-tag-full').length) {
                        $calEv.append('<span class="ebc-tag-full">pełne</span>');
                    }
                } else {
                    $calEv.removeClass('ebc-sold-out').find('.ebc-tag-full').remove();
                }
            });
        });
    }

    // ── Open modal from calendar ──────────────────────────────────────────────
    $(document).on('click', '.ebc-event:not(.ebc-sold-out)', function () {
        fetchAndOpen($(this).data('event-id'));
    });

    // ── Open modal from list button ───────────────────────────────────────────
    $(document).on('click', '.ebc-btn-book:not(:disabled)', function () {
        var $b = $(this);
        openModal({
            id:       $b.data('event-id'),
            title:    $b.data('event-title'),
            date:     $b.data('event-date'),
            time:     $b.data('event-time'),
            location: $b.data('event-location'),
            avail:    $b.data('max-seats'),
        }, 'booking');
    });

    // ── Open waitlist modal ───────────────────────────────────────────────────
    $(document).on('click', '.ebc-btn-waitlist', function () {
        var $b = $(this);
        openModal({
            id:       $b.data('event-id'),
            title:    $b.data('event-title'),
            date:     $b.data('event-date'),
            time:     $b.data('event-time'),
            location: $b.data('event-location'),
        }, 'waitlist');
    });

    function fetchAndOpen(id) {
        $.post(ebc_ajax.ajax_url, { action: 'ebc_get_event', event_id: id, nonce: ebc_ajax.nonce })
        .done(function (res) {
            if (res.success) {
                var mode = res.data.avail > 0 ? 'booking' : 'waitlist';
                openModal($.extend({ id: id }, res.data), mode);
            }
        });
    }

    function openModal(ev, mode) {
        mode = mode || 'booking';
        resetForm();        // resets modalMode to 'booking' — then we override below
        modalMode = mode;
        $('#ebc-event-id').val(ev.id);

        var info = '';
        if (ev.date)     info += '<strong>📅 ' + ev.date + '</strong>';
        if (ev.time)     info += '&nbsp;&nbsp;🕐 ' + ev.time;
        if (ev.location) info += '&nbsp;&nbsp;📍 ' + ev.location;

        if (mode === 'waitlist') {
            $('#ebc-modal-title').text('Lista oczekujących: ' + ev.title);
            info += '<br><span style="color:#f59e0b;font-weight:700">📋 Dołącz do listy — powiadomimy Cię o wolnym miejscu</span>';
            $('#ebc-booking-form').find('.ebc-form-row-seats, #ebc-extra-guests, .ebc-form-row-notes, .ebc-form-row-2col-contact').hide();
            $modal.find('.ebc-btn-submit').text('Dołącz do listy');
        } else {
            $('#ebc-modal-title').text('Zgłoszenie: ' + ev.title);
            $('#ebc-booking-form').find('.ebc-form-row-seats, .ebc-form-row-notes, .ebc-form-row-2col-contact').show();
            $modal.find('.ebc-btn-submit').text('Wyślij zgłoszenie');
            if (ev.avail !== undefined) {
                info += '<br>Dostępne miejsca: <strong>' + ev.avail + '</strong>';
                $('#ebc-seats').attr('max', ev.avail);
                $('#ebc-seats-hint').text('Możesz zarezerwować maks. ' + ev.avail + ' miejsc.');
            }
        }

        $('#ebc-modal-info').html(info);
        if (ev.color) $('#ebc-modal-info').css('border-left-color', ev.color);

        $modal.addClass('ebc-open');
        document.documentElement.style.overflow = 'hidden';
        setTimeout(function () { $('#ebc-first-name').focus(); }, 100);
    }

    // ── Close modal ───────────────────────────────────────────────────────────
    function closeModal() {
        $modal.removeClass('ebc-open');
        document.documentElement.style.overflow = '';
    }

    $(document).on('click', '.ebc-modal-close', closeModal);
    $(document).on('click', '#ebc-modal', function (e) {
        if ($(e.target).is('#ebc-modal')) closeModal();
    });
    $(document).on('keydown', function (e) {
        if (e.key === 'Escape' && $modal.hasClass('ebc-open')) closeModal();
    });

    function resetForm() {
        modalMode = 'booking';
        var $form = $('#ebc-booking-form');
        $form[0].reset();
        $form.show();
        $form.find('.ebc-form-row-seats, .ebc-form-row-notes, .ebc-form-row-2col-contact').show();
        $('#ebc-booking-result').html('');
        $('#ebc-modal-info').html('').css('border-left-color','#1a1a2e');
        $('#ebc-seats-hint').text('');
        $form.find('.ebc-btn-submit').prop('disabled', false).text('Wyślij zgłoszenie');
        updateGuestFields(1);
    }

    function updateGuestFields(n) {
        var $container = $('#ebc-extra-guests');
        $container.empty();
        for (var i = 0; i < n - 1; i++) {
            var num = i + 2;
            $container.append(
                '<div class="ebc-guest-block" style="border-top:1px solid #e5e7eb;margin-top:12px;padding-top:12px">' +
                '<p style="margin:0 0 8px;font-size:13px;font-weight:700;color:#555">Uczestnik ' + num + '</p>' +
                '<div class="ebc-form-row ebc-form-row-2col">' +
                '<div><label>Imię *</label>' +
                '<input type="text" name="guests[' + i + '][first_name]" required autocomplete="off"></div>' +
                '<div><label>Nazwisko *</label>' +
                '<input type="text" name="guests[' + i + '][last_name]" required autocomplete="off"></div>' +
                '</div></div>'
            );
        }
    }

    $(document).on('input change', '#ebc-seats', function () {
        var $el = $(this);
        var max = parseInt($el.attr('max'), 10) || 99;
        var n   = Math.min(max, Math.max(1, parseInt($el.val(), 10) || 1));
        updateGuestFields(n);
    });

    // ── Submit booking / waitlist ─────────────────────────────────────────────
    $(document).on('submit', '#ebc-booking-form', function (e) {
        e.preventDefault();
        var $form   = $(this);
        var $btn    = $form.find('.ebc-btn-submit');
        var $result = $('#ebc-booking-result');

        if (!$('#ebc-rodo').is(':checked')) {
            $result.html('<div class="ebc-alert-error">Proszę zaakceptować zgodę na przetwarzanie danych.</div>');
            return;
        }

        var action     = modalMode === 'waitlist' ? 'ebc_join_waitlist' : 'ebc_book';
        var btnDefault = modalMode === 'waitlist' ? 'Dołącz do listy' : 'Wyślij zgłoszenie';

        $btn.prop('disabled', true).text('Wysyłanie…');
        $result.html('');

        $.post(ebc_ajax.ajax_url, $form.serialize() + '&action=' + action + '&nonce=' + ebc_ajax.nonce)
        .done(function (res) {
            if (res.success) {
                $result.html('<div class="ebc-alert-success">' + res.data.message + '</div>');
                $form.hide();
                if (modalMode === 'booking') setTimeout(refreshSeats, 600);
            } else {
                $result.html('<div class="ebc-alert-error">' + res.data.message + '</div>');
                $btn.prop('disabled', false).text(btnDefault);
            }
        })
        .fail(function () {
            $result.html('<div class="ebc-alert-error">Błąd połączenia. Spróbuj ponownie.</div>');
            $btn.prop('disabled', false).text(btnDefault);
        });
    });

});
