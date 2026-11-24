/**
 * Lead Forms screen: add/remove forms and fields, choose or change a form's type.
 *
 * Every type's settings stay in the page and are saved, so changing the type and
 * changing it back never loses what was entered. A saved form shows its type as a
 * badge; the type cards only open after "Change type", so a click cannot convert
 * a form by accident.
 */
jQuery(function ($) {
    'use strict';

    var $list = $('#wp-ai-forms-container');
    var formTpl = $('#akdk-form-template').html() || '';
    var fieldTpl = $('#akdk-field-template').html() || '';
    var nextIndex = $list.children('.wp-ai-form-block').length + 100;

    function syncType($card) {
        var $checked = $card.find('.akdk-source-radio:checked');
        var source = $checked.val() || 'builtin';
        $card.attr('data-source', source);
        $card.find('.akdk-source-panel').each(function () {
            var show = (' ' + $(this).data('for') + ' ').indexOf(' ' + source + ' ') !== -1;
            $(this).prop('hidden', !show);
            // A hidden box must not be "required", or the browser would block saving without
            // showing why. The flag comes back when its type is chosen again.
            $(this).find('input, select, textarea').each(function () {
                if (!show && this.required) { $(this).attr('data-was-required', '1').prop('required', false); }
                if (show && $(this).attr('data-was-required')) { $(this).removeAttr('data-was-required').prop('required', true); }
            });
        });
        $card.find('.akdk-only').each(function () { $(this).prop('hidden', $(this).data('for') !== source); });
        $card.find('.akdk-type-option').removeClass('is-active').has('.akdk-source-radio:checked').addClass('is-active');
        $card.find('.akdk-type-badge').text($checked.closest('.akdk-type-option').find('strong').text() || 'Built-in form');
        syncEmailWarning($card);
        syncSummary($card);
    }

    // One-line summary shown in the card header, e.g. "Built-in form · 3 fields".
    function syncSummary($card) {
        var source = $card.attr('data-source') || 'builtin';
        var type = $card.find('.akdk-source-radio:checked').closest('.akdk-type-option').find('strong').text() || 'Built-in form';
        var extra = '';
        if (source === 'builtin') {
            var n = $card.find('.wp-ai-fields-table tbody tr').length;
            extra = ' · ' + n + (n === 1 ? ' field' : ' fields');
        }
        $card.find('.akdk-form-summary').text(type + extra);
    }

    function setCollapsed($card, collapsed) {
        $card.toggleClass('is-collapsed', collapsed);
        $card.find('.akdk-collapse-toggle').attr('aria-expanded', collapsed ? 'false' : 'true');
    }

    // Built-in form without an "Email address" box: no thank-you email is possible.
    function syncEmailWarning($card) {
        var builtin = ($card.attr('data-source') || 'builtin') === 'builtin';
        var hasEmail = $card.find('.ai-field-type-select').filter(function () { return $(this).val() === 'email'; }).length > 0;
        $card.find('.akdk-email-warning').prop('hidden', !builtin || hasEmail);
    }

    function prepareCard($card) {
        // Saved forms: type cards stay closed until "Change type" is clicked.
        if ($card.find('.akdk-form-type').hasClass('is-locked')) {
            $card.find('.akdk-type-options').prop('hidden', true);
            setCollapsed($card, true); // Saved forms start folded: name and summary only.
        }
        syncType($card);
    }

    $list.children('.wp-ai-form-block').each(function () { prepareCard($(this)); });

    $(document).on('click', '.akdk-collapse-toggle', function (e) {
        e.preventDefault();
        var $card = $(this).closest('.wp-ai-form-block');
        setCollapsed($card, !$card.hasClass('is-collapsed'));
    });

    $(document).on('click', '.akdk-collapse-all [data-collapse]', function (e) {
        e.preventDefault();
        var collapse = $(this).data('collapse') === 1 || $(this).data('collapse') === '1';
        $list.children('.wp-ai-form-block').each(function () { setCollapsed($(this), collapse); });
    });

    // A required box inside a folded form: open that form so the browser can point at it.
    var pageForm = document.querySelector('.akdk-forms-page');
    if (pageForm) {
        pageForm.addEventListener('invalid', function (ev) {
            var card = ev.target.closest('.wp-ai-form-block');
            if (card && card.classList.contains('is-collapsed')) {
                setCollapsed($(card), false);
                setTimeout(function () { ev.target.reportValidity(); }, 0); // Show the message now that it is visible.
            }
        }, true);
    }

    $(document).on('click', '.akdk-change-type', function (e) {
        e.preventDefault();
        var $type = $(this).closest('.akdk-form-type');
        var open = $type.find('.akdk-type-options').prop('hidden');
        $type.find('.akdk-type-options').prop('hidden', !open);
        $type.find('.akdk-type-note').prop('hidden', !open);
        $(this).text(open ? 'Done' : 'Change type');
    });

    $(document).on('change', '.akdk-source-radio', function () { syncType($(this).closest('.wp-ai-form-block')); });

    $('#add-ai-multi-form').on('click', function (e) {
        e.preventDefault();
        var i = nextIndex++;
        var $card = $(formTpl.replace(/__i__/g, i).replace(/__f__/g, function () { return Math.floor(Math.random() * 1e6); }));
        $list.append($card);
        $('.akdk-empty-forms').remove();
        prepareCard($card);
        $card.find('.akdk-form-title').trigger('focus');
    });

    $(document).on('click', '.remove-ai-form', function (e) {
        e.preventDefault();
        if (window.confirm('Delete this form? Leads already saved are kept.')) $(this).closest('.wp-ai-form-block').remove();
    });

    $(document).on('click', '.add-ai-field', function (e) {
        e.preventDefault();
        var i = $(this).data('form-index');
        var row = fieldTpl.replace(/__i__/g, i).replace(/__f__/g, Math.floor(Math.random() * 1e6));
        $(this).siblings('.wp-ai-fields-table').find('tbody').append(row);
        syncEmailWarning($(this).closest('.wp-ai-form-block'));
        syncSummary($(this).closest('.wp-ai-form-block'));
    });

    $(document).on('click', '.remove-ai-field', function (e) {
        e.preventDefault();
        var $card = $(this).closest('.wp-ai-form-block');
        $(this).closest('tr').remove();
        syncEmailWarning($card);
        syncSummary($card);
    });

    $(document).on('change', '.ai-field-type-select', function () {
        var needsOptions = /^(select|multiselect)$/.test($(this).val());
        $(this).closest('tr').find('.ai-field-options').prop('hidden', !needsOptions).prop('required', needsOptions);
        syncEmailWarning($(this).closest('.wp-ai-form-block'));
    });
});
