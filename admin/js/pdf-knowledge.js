/**
 * PDF Documents section of the Vector RAG DB screen (1.8.0).
 * Adds PDFs from the Media Library, reads and indexes them part by part
 * (one short request per part, so long PDFs never hit server time limits).
 */
(function ($) {
    'use strict';

    if (typeof wpAiPdfData === 'undefined') {
        return;
    }

    var $table = $('#wp-ai-pdf-table');
    if (!$table.length) {
        return;
    }
    var $body = $table.find('tbody');
    var rows = {};
    var order = [];
    var queue = [];
    var busy = false;
    var frame = null;

    function esc(text) {
        return $('<div/>').text(text == null ? '' : String(text)).html();
    }

    function post(action, data) {
        return $.ajax({
            url: wpAiPdfData.ajaxUrl,
            method: 'POST',
            dataType: 'json',
            data: $.extend({ action: action, security: wpAiPdfData.nonce }, data || {})
        });
    }

    function failMessage(xhr, fallback) {
        if (xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
            return xhr.responseJSON.data.message;
        }
        if (xhr && (xhr.status === 504 || xhr.status === 502 || xhr.status === 500 || xhr.status === 0)) {
            return 'The server stopped the request (time or memory limit). Click Retry; finished parts are kept.';
        }
        return fallback || 'Something went wrong. Please try again.';
    }

    function statusHtml(row) {
        if (row.busyText) {
            return '<span class="akdk-pdf-status is-busy"><span class="spinner is-active"></span>' + esc(row.busyText) + '</span>';
        }
        if (row.errorText) {
            return '<span class="akdk-pdf-status is-error">' + esc(row.errorText) + '</span>';
        }
        if (row.state === 'error') {
            return '<span class="akdk-pdf-status is-error">' + esc(row.message || 'Could not read this PDF.') + '</span>';
        }
        if (row.state === 'no_text') {
            return '<span class="akdk-pdf-status is-warn">' + esc(row.message) + '</span>';
        }
        if (row.state === 'new' || row.pending > 0) {
            return '<span class="akdk-pdf-status is-todo">Not indexed yet</span>';
        }
        var parts = row.sections > 1 ? row.sections + ' parts, ' : '';
        var chunks = row.chunks === 1 ? '1 chunk' : row.chunks + ' chunks';
        var html = '<span class="akdk-pdf-status is-ok">&#10003; Ready (' + esc(parts) + esc(chunks) + ')</span>';
        if (row.truncated) {
            html += '<br><small>Very long PDF: only the first ' + esc(row.sections) + ' parts were learned. Split it into smaller PDFs to include the rest.</small>';
        }
        return html;
    }

    function rowHtml(row) {
        var title = row.url
            ? '<a href="' + esc(row.url) + '" target="_blank" rel="noopener">' + esc(row.title) + '</a>'
            : esc(row.title);
        var meta = [row.file, row.size].filter(Boolean).join(' · ');
        var needs = row.state !== 'error' && (row.state === 'new' || row.pending > 0);
        var actions = '';
        if (row.url) {
            actions += needs
                ? '<button type="button" class="button button-primary akdk-pdf-index">Index now</button> '
                : '<button type="button" class="button akdk-pdf-reindex">' + (row.state === 'error' || row.errorText ? 'Retry' : 'Re-index') + '</button> ';
        }
        actions += '<button type="button" class="button akdk-pdf-remove">Remove</button>';
        return '<tr data-id="' + esc(row.id) + '">' +
            '<td><strong>' + title + '</strong>' + (meta ? '<br><small>' + esc(meta) + '</small>' : '') + '</td>' +
            '<td>' + (row.pages ? esc(row.pages) : '&ndash;') + '</td>' +
            '<td>' + statusHtml(row) + '</td>' +
            '<td class="akdk-pdf-actions">' + actions + '</td>' +
            '</tr>';
    }

    function draw(id) {
        var row = rows[id];
        var $tr = $body.find('tr[data-id="' + id + '"]');
        var html = rowHtml(row);
        if ($tr.length) {
            $tr.replaceWith(html);
        } else {
            $body.append(html);
        }
        $body.find('tr[data-id="' + id + '"] button').prop('disabled', !!row.busyText);
        $('#wp-ai-pdf-empty').prop('hidden', order.length > 0);
    }

    function setRow(data, extra) {
        var id = String(data.id);
        if (!rows[id]) {
            order.push(id);
        }
        rows[id] = $.extend({}, data, extra || {});
        draw(id);
    }

    function patch(id, extra) {
        rows[id] = $.extend(rows[id], extra);
        draw(id);
    }

    function refreshCount() {
        var nonce = $('input[name="_wpnonce"]').first().val();
        if (!nonce || typeof ajaxurl === 'undefined') {
            return;
        }
        $.post(ajaxurl, { action: 'wp_ai_get_vector_count', security: nonce }, function (r) {
            if (r && r.success) {
                $('#live-vector-count').text(r.data.count);
            }
        });
    }

    /* ---------- Processing queue (one PDF, one part at a time) ---------- */

    function enqueue(id, force) {
        id = String(id);
        for (var i = 0; i < queue.length; i++) {
            if (queue[i].id === id) {
                queue[i].force = queue[i].force || force;
                return;
            }
        }
        queue.push({ id: id, force: !!force });
        patch(id, { busyText: 'Waiting…', errorText: '' });
        next();
    }

    function next() {
        if (busy || !queue.length) {
            return;
        }
        busy = true;
        var job = queue.shift();
        var id = job.id;
        patch(id, { busyText: 'Reading PDF…', errorText: '' });
        post('wp_ai_pdf_prepare', { id: id, force: job.force ? 1 : 0 })
            .done(function (r) {
                if (!r || !r.success) {
                    finish(id, null, (r && r.data && r.data.message) || 'Could not read this PDF.');
                    return;
                }
                rows[id] = $.extend({}, r.data.row);
                indexParts(id, r.data.pending || [], 0);
            })
            .fail(function (xhr) {
                finish(id, null, failMessage(xhr));
            });
    }

    function indexParts(id, parts, i) {
        if (i >= parts.length) {
            finish(id, rows[id], '');
            return;
        }
        var label = parts.length > 1 || rows[id].sections > 1
            ? 'Learning part ' + (parts[i] + 1) + ' of ' + rows[id].sections + '…'
            : 'Learning the PDF…';
        patch(id, { busyText: label });
        post('wp_ai_pdf_index_section', { id: id, section: parts[i] })
            .done(function (r) {
                if (!r || !r.success) {
                    finish(id, r && r.data && r.data.row, (r && r.data && r.data.message) || 'Indexing failed.');
                    return;
                }
                rows[id] = $.extend({}, r.data.row);
                refreshCount();
                indexParts(id, parts, i + 1);
            })
            .fail(function (xhr) {
                finish(id, null, failMessage(xhr));
            });
    }

    function finish(id, row, error) {
        if (row) {
            rows[id] = $.extend({}, row);
        }
        patch(id, { busyText: '', errorText: error || '' });
        busy = false;
        refreshCount();
        next();
    }

    /* ---------- Actions ---------- */

    $('#wp-ai-pdf-add').on('click', function (e) {
        e.preventDefault();
        if (typeof wp === 'undefined' || !wp.media) {
            window.alert('The Media Library could not be opened on this page.');
            return;
        }
        if (!frame) {
            frame = wp.media({
                title: 'Choose PDFs for the chatbot',
                button: { text: 'Add to chatbot' },
                library: { type: 'application/pdf' },
                multiple: 'add'
            });
            frame.on('select', function () {
                var ids = frame.state().get('selection').map(function (a) { return a.id; });
                if (!ids.length) {
                    return;
                }
                post('wp_ai_pdf_add', { ids: ids })
                    .done(function (r) {
                        if (!r || !r.success) {
                            window.alert((r && r.data && r.data.message) || 'Could not add these files.');
                            return;
                        }
                        if (r.data.skipped) {
                            window.alert(r.data.skipped + ' file(s) were skipped because they are not PDFs.');
                        }
                        $.each(r.data.rows, function (_, row) {
                            var exists = !!rows[String(row.id)];
                            setRow(row);
                            if (!exists && row.state !== 'error' && (row.state === 'new' || row.pending > 0)) {
                                enqueue(row.id, false);
                            }
                        });
                    })
                    .fail(function (xhr) {
                        window.alert(failMessage(xhr));
                    });
            });
        }
        frame.open();
    });

    $body.on('click', '.akdk-pdf-index', function () {
        enqueue($(this).closest('tr').data('id'), false);
    });

    $body.on('click', '.akdk-pdf-reindex', function () {
        enqueue($(this).closest('tr').data('id'), true);
    });

    $body.on('click', '.akdk-pdf-remove', function () {
        var $tr = $(this).closest('tr');
        var id = String($tr.data('id'));
        if (!window.confirm('Remove "' + rows[id].title + '" from the chatbot? The file stays in your Media Library.')) {
            return;
        }
        queue = queue.filter(function (j) { return j.id !== id; });
        patch(id, { busyText: 'Removing…' });
        post('wp_ai_pdf_remove', { id: id })
            .done(function () {
                $tr.remove();
                delete rows[id];
                order = order.filter(function (x) { return x !== id; });
                $('#wp-ai-pdf-empty').prop('hidden', order.length > 0);
                refreshCount();
            })
            .fail(function (xhr) {
                patch(id, { busyText: '', errorText: failMessage(xhr) });
            });
    });

    $('#wp-ai-pdf-share').on('change', function () {
        var $box = $(this);
        $box.prop('disabled', true);
        post('wp_ai_pdf_settings', { share_link: $box.is(':checked') ? 1 : 0 })
            .fail(function (xhr) {
                $box.prop('checked', !$box.is(':checked'));
                window.alert(failMessage(xhr));
            })
            .always(function () {
                $box.prop('disabled', false);
            });
    });

    window.addEventListener('beforeunload', function (e) {
        if (busy || queue.length) {
            e.preventDefault();
            e.returnValue = '';
        }
    });

    /* ---------- Initial rows ---------- */

    var initial = [];
    try {
        initial = JSON.parse($table.attr('data-rows') || '[]');
    } catch (err) {
        initial = [];
    }
    $.each(initial, function (_, row) {
        setRow(row);
    });
})(jQuery);
