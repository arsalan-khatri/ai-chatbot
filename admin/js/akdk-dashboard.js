/**
 * AK Deep Knowledge AI Chatbot - dashboard.
 * Small SVG chart helpers (no external libraries), the Overview screen,
 * the Data Analyst chat and the Design tab.
 */
(function ($) {
    'use strict';

    var CFG = window.akdkDash || {};
    var design = $.extend({}, CFG.defaults || {}, CFG.design || {});
    var NS = 'http://www.w3.org/2000/svg';
    var INK = '#1d1d1f', MUTED = '#71717a', GRID = '#ececef', SURFACE = '#ffffff';

    /* ==================================================================
     * Helpers
     * ================================================================== */
    function el(tag, attrs, parent) {
        var n = document.createElementNS(NS, tag);
        Object.keys(attrs || {}).forEach(function (k) { n.setAttribute(k, attrs[k]); });
        if (parent) parent.appendChild(n);
        return n;
    }
    function esc(s) { return String(s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
    function fmt(n, dec) {
        n = Number(n) || 0;
        if (Math.abs(n) >= 1e6) return (n / 1e6).toFixed(1).replace(/\.0$/, '') + 'M';
        if (Math.abs(n) >= 1e4) return (n / 1e3).toFixed(1).replace(/\.0$/, '') + 'k';
        return dec ? n.toFixed(dec) : Math.round(n).toLocaleString();
    }
    function niceScale(max, ticks) {
        if (!max || max <= 0) return { max: 4, step: 1 };
        var raw = max / (ticks || 4);
        var mag = Math.pow(10, Math.floor(Math.log10(raw)));
        var norm = raw / mag;
        var step = (norm <= 1 ? 1 : norm <= 2 ? 2 : norm <= 2.5 ? 2.5 : norm <= 5 ? 5 : 10) * mag;
        return { max: Math.ceil(max / step) * step, step: step };
    }
    function hexA(hex, a) {
        var h = String(hex).replace('#', '');
        if (h.length === 3) h = h.split('').map(function (c) { return c + c; }).join('');
        var n = parseInt(h, 16);
        return 'rgba(' + (n >> 16 & 255) + ',' + (n >> 8 & 255) + ',' + (n & 255) + ',' + a + ')';
    }
    function isEmpty(series) { return series.every(function (s) { return s.data.every(function (v) { return !v; }); }); }

    // Monotone cubic path (no overshoot below zero or above peaks).
    function linePath(pts, smooth) {
        if (!pts.length) return '';
        if (!smooth || pts.length < 3) return pts.map(function (p, i) { return (i ? 'L' : 'M') + p[0] + ',' + p[1]; }).join('');
        var n = pts.length, dx = [], dy = [], m = [], t = [];
        for (var i = 0; i < n - 1; i++) { dx[i] = pts[i + 1][0] - pts[i][0]; dy[i] = pts[i + 1][1] - pts[i][1]; m[i] = dy[i] / dx[i]; }
        t[0] = m[0]; t[n - 1] = m[n - 2];
        for (i = 1; i < n - 1; i++) t[i] = (m[i - 1] * m[i] <= 0) ? 0 : (m[i - 1] + m[i]) / 2;
        for (i = 0; i < n - 1; i++) {
            if (m[i] === 0) { t[i] = 0; t[i + 1] = 0; continue; }
            var a = t[i] / m[i], b = t[i + 1] / m[i], s = a * a + b * b;
            if (s > 9) { var k = 3 / Math.sqrt(s); t[i] = k * a * m[i]; t[i + 1] = k * b * m[i]; }
        }
        var d = 'M' + pts[0][0] + ',' + pts[0][1];
        for (i = 0; i < n - 1; i++) {
            var h = dx[i] / 3;
            d += 'C' + (pts[i][0] + h) + ',' + (pts[i][1] + h * t[i]) + ' ' + (pts[i + 1][0] - h) + ',' + (pts[i + 1][1] - h * t[i + 1]) + ' ' + pts[i + 1][0] + ',' + pts[i + 1][1];
        }
        return d;
    }

    function legend(container, series) {
        if (series.length < 2) return;
        var lg = document.createElement('div');
        lg.className = 'akdk-legend';
        lg.innerHTML = series.map(function (s) { return '<span><i style="background:' + esc(s.color) + '"></i>' + esc(s.name) + '</span>'; }).join('');
        container.appendChild(lg);
    }

    function tooltip(container) {
        var tip = document.createElement('div');
        tip.className = 'akdk-tip';
        tip.hidden = true;
        container.appendChild(tip);
        return {
            show: function (html, x, y) {
                tip.innerHTML = html; tip.hidden = false;
                var cw = container.clientWidth, tw = tip.offsetWidth;
                var left = x + 14; if (left + tw > cw) left = x - tw - 14; if (left < 0) left = 0;
                tip.style.left = left + 'px'; tip.style.top = Math.max(0, y - 10) + 'px';
            },
            hide: function () { tip.hidden = true; }
        };
    }

    function prepare(node, series) {
        node.innerHTML = '';
        node.classList.add('akdk-chart-ready');
        legend(node, series || []);
        var wrap = document.createElement('div');
        wrap.className = 'akdk-plot';
        node.appendChild(wrap);
        return wrap;
    }

    function emptyState(node, text) {
        node.innerHTML = '<div class="akdk-empty">' + esc(text || 'No data for this period yet.') + '</div>';
    }

    function watch(node, draw) {
        if (node._akdkObs) node._akdkObs.disconnect();
        if (window.ResizeObserver) {
            var lastW = node.clientWidth;
            node._akdkObs = new ResizeObserver(function () { if (Math.abs(node.clientWidth - lastW) > 4) { lastW = node.clientWidth; draw(); } });
            node._akdkObs.observe(node);
        }
    }

    /* ==================================================================
     * Charts
     * ================================================================== */

    /** Cartesian chart: type 'line' or 'bar' (grouped or stacked). One y-axis only. */
    function xyChart(node, opt) {
        if (!node) return;
        var draw = function () {
            if (isEmpty(opt.series)) { emptyState(node, opt.empty); return; }
            var plot = prepare(node, opt.series);
            var W = plot.clientWidth || 600, H = Math.max(140, (parseInt(node.style.height, 10) || 240) - (opt.series.length > 1 ? 30 : 0));
            var M = { t: 10, r: 10, b: 26, l: 42 };
            var iw = W - M.l - M.r, ih = H - M.t - M.b, n = opt.labels.length;
            var stacked = !!opt.stacked;
            var maxV = 0;
            for (var i = 0; i < n; i++) {
                var sum = 0;
                opt.series.forEach(function (s) { var v = +s.data[i] || 0; sum += v; maxV = Math.max(maxV, v); });
                if (stacked) maxV = Math.max(maxV, sum);
            }
            var sc = niceScale(maxV, 4);
            var y = function (v) { return M.t + ih - (v / sc.max) * ih; };
            var band = iw / Math.max(1, n);
            var x = opt.type === 'bar' ? function (i) { return M.l + band * i + band / 2; } : function (i) { return M.l + (n === 1 ? iw / 2 : (iw * i) / (n - 1)); };

            var svg = el('svg', { width: W, height: H, viewBox: '0 0 ' + W + ' ' + H, role: 'img', 'aria-label': opt.title || 'Chart' }, plot);

            // Axes & grid (recessive).
            for (var v = 0; v <= sc.max + 1e-9; v += sc.step) {
                if (design.grid === '1' || v === 0) el('line', { x1: M.l, x2: W - M.r, y1: y(v), y2: y(v), stroke: v === 0 ? '#d4d4d8' : GRID, 'stroke-width': 1 }, svg);
                var t = el('text', { x: M.l - 8, y: y(v) + 4, 'text-anchor': 'end', fill: MUTED, 'font-size': 11 }, svg);
                t.textContent = opt.yFormat ? opt.yFormat(v) : fmt(v);
            }
            var every = opt.allLabels ? 1 : Math.max(1, Math.ceil(n / Math.max(1, Math.floor(iw / 58))));
            opt.labels.forEach(function (lab, i) {
                if (i % every !== 0 && i !== n - 1) return;
                if (i === n - 1 && i % every !== 0 && (i % every) < every / 2) return;
                var tx = el('text', { x: x(i), y: H - 8, 'text-anchor': 'middle', fill: MUTED, 'font-size': 11 }, svg);
                tx.textContent = lab;
            });

            // Marks.
            if (opt.type === 'bar') {
                var k = opt.series.length, gap = 2;
                var groupW = Math.min(band * 0.72, 46 * (stacked ? 1 : k));
                var bw = stacked ? groupW : (groupW - gap * (k - 1)) / k;
                for (i = 0; i < n; i++) {
                    var base = 0;
                    opt.series.forEach(function (s, si) {
                        var val = +s.data[i] || 0; if (!val) return;
                        var x0 = stacked ? x(i) - groupW / 2 : x(i) - groupW / 2 + si * (bw + gap);
                        var y0 = y(base + (stacked ? val : val)), y1 = y(stacked ? base : 0);
                        var h = Math.max(1, y1 - y0 - (stacked && base ? gap : 0));
                        var r = Math.min(4, bw / 2, h);
                        var topY = y0;
                        var d = 'M' + x0 + ',' + (topY + h) + 'V' + (topY + r) + 'Q' + x0 + ',' + topY + ' ' + (x0 + r) + ',' + topY + 'H' + (x0 + bw - r) + 'Q' + (x0 + bw) + ',' + topY + ' ' + (x0 + bw) + ',' + (topY + r) + 'V' + (topY + h) + 'Z';
                        el('path', { d: d, fill: s.color }, svg);
                        if (stacked) base += val;
                    });
                }
            } else {
                opt.series.forEach(function (s) {
                    var pts = s.data.map(function (val, i) { return [x(i), y(+val || 0)]; });
                    var d = linePath(pts, design.smooth === '1');
                    if (design.fill === '1' && opt.fill !== false) {
                        var gid = 'g' + Math.random().toString(36).slice(2);
                        var defs = el('defs', {}, svg), g = el('linearGradient', { id: gid, x1: 0, y1: 0, x2: 0, y2: 1 }, defs);
                        el('stop', { offset: '0%', 'stop-color': s.color, 'stop-opacity': 0.22 }, g);
                        el('stop', { offset: '100%', 'stop-color': s.color, 'stop-opacity': 0 }, g);
                        el('path', { d: d + 'L' + pts[pts.length - 1][0] + ',' + y(0) + 'L' + pts[0][0] + ',' + y(0) + 'Z', fill: 'url(#' + gid + ')' }, svg);
                    }
                    el('path', { d: d, fill: 'none', stroke: s.color, 'stroke-width': 2, 'stroke-linejoin': 'round', 'stroke-linecap': 'round' }, svg);
                    if (n === 1) el('circle', { cx: pts[0][0], cy: pts[0][1], r: 4, fill: s.color, stroke: SURFACE, 'stroke-width': 2 }, svg);
                });
            }

            // Hover: one tooltip listing every series at that x.
            var tip = tooltip(plot);
            var cross = el('line', { y1: M.t, y2: M.t + ih, stroke: '#a1a1aa', 'stroke-width': 1, 'stroke-dasharray': '3 3', visibility: 'hidden' }, svg);
            var dots = opt.type === 'line' ? opt.series.map(function (s) { return el('circle', { r: 4.5, fill: s.color, stroke: SURFACE, 'stroke-width': 2, visibility: 'hidden' }, svg); }) : [];
            var hit = el('rect', { x: M.l, y: M.t, width: iw, height: ih, fill: 'transparent' }, svg);
            var move = function (ev) {
                var rect = svg.getBoundingClientRect();
                var mx = ev.clientX - rect.left;
                var idx = opt.type === 'bar' ? Math.floor((mx - M.l) / band) : Math.round(((mx - M.l) / iw) * (n - 1));
                idx = Math.max(0, Math.min(n - 1, idx));
                cross.setAttribute('x1', x(idx)); cross.setAttribute('x2', x(idx)); cross.setAttribute('visibility', 'visible');
                dots.forEach(function (c, si) { c.setAttribute('cx', x(idx)); c.setAttribute('cy', y(+opt.series[si].data[idx] || 0)); c.setAttribute('visibility', 'visible'); });
                var html = '<strong>' + esc((opt.tipLabels || opt.labels)[idx]) + '</strong>' + opt.series.map(function (s) {
                    var val = +s.data[idx] || 0;
                    return '<span><i style="background:' + esc(s.color) + '"></i>' + esc(s.name) + '<b>' + esc(opt.tipFormat ? opt.tipFormat(val) : fmt(val)) + '</b></span>';
                }).join('');
                tip.show(html, x(idx), ev.clientY - rect.top);
            };
            hit.addEventListener('pointermove', move);
            hit.addEventListener('pointerdown', move);
            hit.addEventListener('pointerleave', function () { tip.hide(); cross.setAttribute('visibility', 'hidden'); dots.forEach(function (c) { c.setAttribute('visibility', 'hidden'); }); });
        };
        draw();
        watch(node, draw);
    }

    /** Donut with total in the middle and a legend with values and shares. */
    function donut(node, items, opt) {
        if (!node) return;
        opt = opt || {};
        var total = items.reduce(function (a, b) { return a + (+b.value || 0); }, 0);
        if (!total) { emptyState(node, opt.empty || 'No leads in this period yet.'); return; }
        node.innerHTML = '';
        node.classList.add('akdk-chart-ready', 'akdk-donut');
        var size = Math.min(170, (parseInt(node.style.height, 10) || 200) - 10);
        var R = size / 2, r = R * 0.64, cx = R, cy = R;
        var box = document.createElement('div'); box.className = 'akdk-donut-svg'; node.appendChild(box);
        var svg = el('svg', { width: size, height: size, viewBox: '0 0 ' + size + ' ' + size, role: 'img', 'aria-label': opt.title || 'Share' }, box);
        var tip = tooltip(box);
        var a0 = -Math.PI / 2;
        items.forEach(function (it) {
            var v = +it.value || 0; if (!v) return;
            var a1 = a0 + (v / total) * Math.PI * 2;
            var large = a1 - a0 > Math.PI ? 1 : 0;
            var p = function (a, rad) { return [cx + rad * Math.cos(a), cy + rad * Math.sin(a)]; };
            var d;
            if (v === total) {
                d = 'M' + (cx) + ',' + (cy - R) + 'A' + R + ',' + R + ' 0 1 1 ' + (cx - 0.01) + ',' + (cy - R) + 'Z M' + cx + ',' + (cy - r) + 'A' + r + ',' + r + ' 0 1 0 ' + (cx + 0.01) + ',' + (cy - r) + 'Z';
            } else {
                var o0 = p(a0, R), o1 = p(a1, R), i1 = p(a1, r), i0 = p(a0, r);
                d = 'M' + o0 + 'A' + R + ',' + R + ' 0 ' + large + ' 1 ' + o1 + 'L' + i1 + 'A' + r + ',' + r + ' 0 ' + large + ' 0 ' + i0 + 'Z';
            }
            var path = el('path', { d: d, fill: it.color, stroke: SURFACE, 'stroke-width': 2, 'fill-rule': 'evenodd' }, svg);
            path.addEventListener('pointermove', function (ev) {
                var rect = box.getBoundingClientRect();
                tip.show('<strong>' + esc(it.label) + '</strong><span><i style="background:' + esc(it.color) + '"></i>' + fmt(v) + '<b>' + Math.round(100 * v / total) + '%</b></span>', ev.clientX - rect.left, ev.clientY - rect.top);
            });
            path.addEventListener('pointerleave', tip.hide);
            a0 = a1;
        });
        var t1 = el('text', { x: cx, y: cy + 2, 'text-anchor': 'middle', fill: INK, 'font-size': 22, 'font-weight': 700 }, svg); t1.textContent = fmt(total);
        var t2 = el('text', { x: cx, y: cy + 20, 'text-anchor': 'middle', fill: MUTED, 'font-size': 11 }, svg); t2.textContent = opt.centerLabel || 'total';
        var list = document.createElement('ul'); list.className = 'akdk-donut-legend';
        list.innerHTML = items.map(function (it) {
            return '<li><i style="background:' + esc(it.color) + '"></i><span>' + esc(it.label) + '</span><b>' + fmt(it.value) + '</b><em>' + Math.round(100 * (+it.value || 0) / total) + '%</em></li>';
        }).join('');
        node.appendChild(list);
    }

    /** Horizontal bars (HTML), used for the funnel and knowledge base inventory. */
    function hbars(node, items, opt) {
        if (!node) return;
        opt = opt || {};
        var max = items.reduce(function (a, b) { return Math.max(a, +b.value || 0); }, 0);
        if (!max) { emptyState(node, opt.empty); return; }
        node.classList.add('akdk-chart-ready');
        node.innerHTML = '<ul class="akdk-hbars">' + items.map(function (it, i) {
            var pct = Math.max(2, Math.round(100 * (+it.value || 0) / max));
            var extra = (opt.showRate && i > 0 && items[i - 1].value) ? '<em>' + Math.round(100 * it.value / items[i - 1].value) + '% of previous</em>' : '';
            return '<li><div class="akdk-hbar-top"><span>' + esc(it.label) + '</span><b>' + fmt(it.value) + '</b></div><div class="akdk-hbar-track"><i style="width:' + pct + '%;background:' + esc(it.color || design.c1) + '"></i></div>' + extra + '</li>';
        }).join('') + '</ul>';
    }

    function sparkline(node, data, color) {
        if (!node) return;
        node.innerHTML = '';
        if (!data || data.length < 2 || data.every(function (v) { return !v; })) return;
        var W = node.clientWidth || 120, H = 34, max = Math.max.apply(null, data) || 1, n = data.length;
        var pts = data.map(function (v, i) { return [(W * i) / (n - 1), H - 3 - ((+v || 0) / max) * (H - 6)]; });
        var svg = el('svg', { width: W, height: H, viewBox: '0 0 ' + W + ' ' + H }, node);
        var d = linePath(pts, true);
        el('path', { d: d + 'L' + W + ',' + H + 'L0,' + H + 'Z', fill: hexA(color, 0.14) }, svg);
        el('path', { d: d, fill: 'none', stroke: color, 'stroke-width': 2, 'stroke-linecap': 'round' }, svg);
    }

    window.akdkCharts = { xy: xyChart, donut: donut, hbars: hbars, spark: sparkline };

    /* ==================================================================
     * Markdown + sanitizer for the Data Analyst replies
     * ================================================================== */
    function sanitize(html) {
        var ALLOWED = { P: 1, BR: 1, B: 1, STRONG: 1, I: 1, EM: 1, UL: 1, OL: 1, LI: 1, H3: 1, H4: 1, H5: 1, A: 1, TABLE: 1, THEAD: 1, TBODY: 1, TR: 1, TH: 1, TD: 1, CODE: 1, PRE: 1, BLOCKQUOTE: 1, HR: 1, DEL: 1 };
        var doc = new DOMParser().parseFromString('<body>' + html, 'text/html');
        (function clean(node) {
            Array.prototype.slice.call(node.childNodes).forEach(function (ch) {
                if (ch.nodeType === 3) return;
                if (ch.nodeType !== 1 || /^(SCRIPT|STYLE|IFRAME|OBJECT|EMBED|FORM|INPUT|SVG|MATH)$/.test(ch.tagName)) { node.removeChild(ch); return; }
                if (!ALLOWED[ch.tagName]) { clean(ch); while (ch.firstChild) node.insertBefore(ch.firstChild, ch); node.removeChild(ch); return; }
                Array.prototype.slice.call(ch.attributes).forEach(function (a) {
                    if (!(ch.tagName === 'A' && a.name === 'href' && /^(https?:|mailto:|\/|#)/i.test(a.value))) ch.removeAttribute(a.name);
                });
                if (ch.tagName === 'A') { ch.setAttribute('target', '_blank'); ch.setAttribute('rel', 'noopener noreferrer'); }
                clean(ch);
            });
        })(doc.body);
        return doc.body.innerHTML;
    }
    function markdown(src) {
        var text = esc(String(src).replace(/\r\n?/g, '\n'));
        var inline = function (t) {
            return t.replace(/`([^`\n]+)`/g, '<code>$1</code>')
                .replace(/\[([^\]]+)\]\(([^)\s]+)\)/g, '<a href="$2">$1</a>')
                .replace(/\*\*([^*\n]+)\*\*/g, '<strong>$1</strong>')
                .replace(/(^|[^*])\*([^*\n]+)\*(?!\*)/g, '$1<em>$2</em>');
        };
        var out = [], para = [], list = null;
        var flushP = function () { if (para.length) { out.push('<p>' + para.map(inline).join('<br>') + '</p>'); para = []; } };
        var flushL = function () { if (list) { out.push('<' + list.t + '>' + list.items.map(function (i) { return '<li>' + inline(i) + '</li>'; }).join('') + '</' + list.t + '>'); list = null; } };
        text.split('\n').forEach(function (line) {
            var m;
            if (/^\s*$/.test(line)) { flushP(); flushL(); return; }
            if ((m = line.match(/^#{1,4}\s+(.*)$/))) { flushP(); flushL(); out.push('<h4>' + inline(m[1]) + '</h4>'); return; }
            if ((m = line.match(/^\s*[-*+]\s+(.*)$/))) { flushP(); if (!list || list.t !== 'ul') { flushL(); list = { t: 'ul', items: [] }; } list.items.push(m[1]); return; }
            if ((m = line.match(/^\s*\d+[.)]\s+(.*)$/))) { flushP(); if (!list || list.t !== 'ol') { flushL(); list = { t: 'ol', items: [] }; } list.items.push(m[1]); return; }
            flushL(); para.push(line);
        });
        flushP(); flushL();
        return sanitize(out.join(''));
    }

    /* ==================================================================
     * Overview
     * ================================================================== */
    var store = {
        get: function (k) { try { return window.localStorage.getItem(k); } catch (e) { return null; } },
        set: function (k, v) { try { window.localStorage.setItem(k, v); } catch (e) { /* ignore */ } }
    };

    function color(key) { return design[key] || CFG.defaults[key]; }

    function renderKpis(d) {
        var k = d.kpi, daily = d.daily;
        var perDay = function (num, den, scale) { return num.map(function (v, i) { return den[i] ? (scale * v) / den[i] : 0; }); };
        var answer = daily.conversations.map(function (q, i) { return q ? 100 * (q - daily.fallbacks[i]) / q : 0; });
        var status = k.conversations === 0 ? ['', ''] : k.answer_rate >= 90 ? ['good', 'Healthy'] : k.answer_rate >= 75 ? ['warn', 'Needs attention'] : ['bad', 'Poor, add knowledge'];
        var tiles = {
            conversations: [fmt(k.conversations), fmt(k.fallbacks) + ' unanswered', daily.conversations],
            visitors: [fmt(k.visitors), 'unique chat sessions', daily.visitors],
            leads: [fmt(k.leads), k.external_reported ? 'verified · ' + fmt(k.external_reported) + ' more reported' : (k.external_clicks ? 'verified · ' + fmt(k.external_clicks) + ' link clicks' : 'verified submissions'), daily.leads],
            conversion: [k.conversion + '%', 'of visitors became leads', perDay(daily.leads, daily.visitors, 100)],
            answer_rate: [k.answer_rate + '%', status[1] ? '<span class="akdk-status akdk-status-' + status[0] + '">' + status[1] + '</span>' : 'no chats yet', answer],
            avg_response: [k.avg_response + 's', 'average reply time', daily.response]
        };
        $('.akdk-kpi').each(function () {
            var key = $(this).data('kpi'), t = tiles[key], c = color($(this).data('color'));
            if (!t) return;
            $(this).find('.akdk-dot').css('background', c);
            $(this).find('.akdk-kpi-value').text(t[0]);
            $(this).find('.akdk-kpi-sub').html(t[1]);
            sparkline($(this).find('.akdk-kpi-spark')[0], t[2], c);
        });
    }

    function renderTable(d) {
        var rows = d.dates.map(function (date, i) {
            return '<tr><td>' + esc(d.labels[i]) + '</td><td>' + d.daily.conversations[i] + '</td><td>' + d.daily.visitors[i] + '</td><td>' + d.daily.leads[i] + '</td><td>' + d.daily.fallbacks[i] + '</td></tr>';
        }).join('');
        $('#table-trend').html('<table class="widefat striped"><thead><tr><th>Day</th><th>Conversations</th><th>Visitors</th><th>Leads</th><th>Unanswered</th></tr></thead><tbody>' + rows + '</tbody></table>');
    }

    var lastData = null;

    function renderAll(d) {
        lastData = d;
        $('#akdk-range-label').text(d.range.from === d.range.to ? d.range.from : d.range.from + ' → ' + d.range.to);
        renderKpis(d);

        xyChart(document.getElementById('chart-trend'), {
            type: 'line', labels: d.labels, title: 'Conversations and leads per day',
            series: [{ name: 'Conversations', color: color('c1'), data: d.daily.conversations }, { name: 'Leads', color: color('c2'), data: d.daily.leads }],
            empty: 'No conversations in this period yet.'
        });
        renderTable(d);

        xyChart(document.getElementById('chart-hourly'), {
            type: 'bar', labels: d.hours.map(function (h, i) { return i % 4 === 0 ? h : ''; }), tipLabels: d.hours, allLabels: true, title: 'Busiest hours',
            series: [{ name: 'Conversations', color: color('c1'), data: d.hourly.conversations }, { name: 'Leads', color: color('c2'), data: d.hourly.leads }]
        });

        var formColors = ['c1', 'c3', 'c4', 'c5', 'c6', 'c2'];
        var forms = d.by_form.slice().sort(function (a, b) { return b.value - a.value; });
        if (forms.length > 6) {
            var rest = forms.slice(5).reduce(function (a, b) { return a + b.value; }, 0);
            forms = forms.slice(0, 5).concat([{ label: 'Other', value: rest }]);
        }
        donut(document.getElementById('chart-forms'), forms.map(function (f, i) { return { label: f.label, value: f.value, color: color(formColors[i]) }; }), { centerLabel: 'leads', title: 'Leads by form' });

        hbars(document.getElementById('chart-funnel'), d.funnel.map(function (f, i) { return { label: f.label, value: f.value, color: i === 2 ? color('c2') : color('c1') }; }), { showRate: true, empty: 'No visitors in this period yet.' });

        xyChart(document.getElementById('chart-tokens'), {
            type: 'bar', stacked: true, labels: d.labels, title: 'Token usage',
            series: [{ name: 'Input', color: color('c3'), data: d.daily.tokens_in }, { name: 'Output', color: color('c5'), data: d.daily.tokens_out }],
            empty: 'No token usage in this period yet.'
        });
        $('#akdk-cost').text('$' + Number(d.kpi.est_cost).toFixed(4));
        $('#akdk-cost-parts').text('(chats $' + Number(d.kpi.cost_chat).toFixed(4) + ', admin $' + Number(d.kpi.cost_admin).toFixed(4) + ', indexing $' + Number(d.kpi.cost_index).toFixed(4) + (Number(d.kpi.voice_clips) ? ', voice $' + Number(d.kpi.cost_voice).toFixed(4) + ' for ' + fmt(d.kpi.voice_clips) + ' clips' : '') + ')');
        $('#akdk-admin-tokens').text(fmt(d.kpi.tokens_admin));
        $('#akdk-index-tokens').text(fmt(d.kpi.tokens_index));
        var notes = [];
        if (d.kpi.conversations) { notes.push(d.kpi.exact_share + '% of chats use exact counts from the AI provider; the rest are estimates.'); }
        if (d.kpi.tokens_index_estimated) { notes.push('Gemini does not report embedding usage, so indexing tokens are estimated.'); }
        if (!d.kpi.rates_complete) { notes.push('Prices for your model are not known: add them under Dashboard, Design, Cost estimate. Until then the cost is incomplete.'); }
        $('#akdk-token-note').text(notes.join(' '));

        xyChart(document.getElementById('chart-response'), {
            type: 'line', labels: d.labels, title: 'Average response time', fill: true,
            series: [{ name: 'Response time', color: color('c4'), data: d.daily.response }],
            yFormat: function (v) { return v + 's'; }, tipFormat: function (v) { return v.toFixed(2) + 's'; },
            empty: 'No replies in this period yet.'
        });

        $('#akdk-engine').html(Object.keys(d.engine).map(function (k) { return '<dt>' + esc(k) + '</dt><dd>' + esc(d.engine[k]) + '</dd>'; }).join(''));
        hbars(document.getElementById('chart-vectors'), d.vectors.map(function (v) { return { label: v.label, value: v.value, color: color('c3') }; }), { empty: 'Nothing indexed yet. Open Train Chatbot.' });

        var alerts = '';
        if (d.kpi.fallbacks > 0) alerts += '<p class="akdk-alert"><span class="akdk-status akdk-status-warn">Unanswered</span> ' + fmt(d.kpi.fallbacks) + ' questions had no good answer. Add that info to your knowledge base.</p>';
        if (d.kpi.unverified > 0) alerts += '<p class="akdk-alert"><span class="akdk-status akdk-status-warn">Checked</span> ' + fmt(d.kpi.unverified) + ' answers mentioned a link, contact detail or price not found in your content. It was removed or marked "please confirm".</p>';
        if (d.kpi.outdated > 0) {
            alerts += '<p class="akdk-alert"><span class="akdk-status akdk-status-warn">Outdated</span> ' + fmt(d.kpi.outdated) + ' pages/products changed since indexing. Re-run Train Chatbot.</p>';
            alerts += '<ul class="akdk-outdated">' + d.outdated_list.map(function (o) { return '<li>' + (o.url ? '<a href="' + esc(o.url) + '" target="_blank" rel="noopener">' + esc(o.title) + '</a>' : esc(o.title)) + '</li>'; }).join('') + '</ul>';
        }
        $('#akdk-alerts').html(alerts || '<p class="akdk-alert"><span class="akdk-status akdk-status-good">All good</span> No alerts.</p>');

        window.dashboardContextData = d.raw_context_string;
    }

    function loadData() {
        $('#dashboard-spinner').addClass('is-active');
        return $.post(CFG.ajaxurl, {
            action: 'wp_ai_get_dashboard_data', security: CFG.nonce,
            date_filter: $('#dashboard-date-filter').val(),
            start_date: $('#dash-start-date').val(), end_date: $('#dash-end-date').val()
        }).done(function (res) {
            if (res && res.success) renderAll(res.data);
        }).always(function () { $('#dashboard-spinner').removeClass('is-active'); });
    }

    /* ---------- Data Analyst chat ---------- */
    var HISTORY_KEY = 'wp_ai_admin_chat_history';
    var chatHistory = [];
    try { chatHistory = JSON.parse(store.get(HISTORY_KEY)) || []; } catch (e) { chatHistory = []; }

    function renderChat() {
        var $box = $('#admin-chat-box');
        $box.find('.chat-msg:not(#chat-typing)').remove();
        chatHistory.forEach(function (m) {
            var html = m.role === 'ai' ? markdown(m.text) : esc(m.text);
            $('#chat-typing').before('<div class="chat-msg ' + (m.role === 'ai' ? 'ai-msg' : 'admin-msg') + '"><div class="chat-bubble">' + html + '</div></div>');
        });
        if ($box[0]) $box.scrollTop($box[0].scrollHeight);
    }
    function saveChat() { store.set(HISTORY_KEY, JSON.stringify(chatHistory.slice(-40))); }
    function chatError(text) { $('#chat-typing').before('<div class="chat-msg ai-msg"><div class="chat-bubble akdk-chat-error">' + esc(text) + '</div></div>'); }

    function ask(message) {
        $('#chat-typing').prop('hidden', false);
        var history = chatHistory.slice(-24).map(function (m) { return m.role.toUpperCase() + ': ' + m.text; }).join('\n');
        return $.post(CFG.ajaxurl, { action: 'wp_ai_admin_dashboard_chat', security: CFG.nonce, message: message, context: window.dashboardContextData || '{}', history: message === 'INIT_GREETING' ? '' : history })
            .done(function (res) {
                if (res && res.success) { chatHistory.push({ role: 'ai', text: res.data.reply }); saveChat(); renderChat(); }
                else chatError(res && typeof res.data === 'string' ? res.data : 'Could not reach the AI provider.');
            })
            .fail(function () { chatError('Request failed. Please check your connection and try again.'); })
            .always(function () { $('#chat-typing').prop('hidden', true); var b = $('#admin-chat-box')[0]; if (b) b.scrollTop = b.scrollHeight; });
    }

    function initOverview() {
        var saved = store.get('wp_ai_dashboard_filter') || '7days';
        $('#dashboard-date-filter').val(saved);
        if (saved === 'custom') {
            $('#custom-date-range').prop('hidden', false);
            $('#dash-start-date').val(store.get('wp_ai_dash_start') || '');
            $('#dash-end-date').val(store.get('wp_ai_dash_end') || '');
        }
        $('#dashboard-date-filter').on('change', function () {
            var v = $(this).val();
            store.set('wp_ai_dashboard_filter', v);
            $('#custom-date-range').prop('hidden', v !== 'custom');
            if (v !== 'custom') loadData();
        });
        $('#apply-custom-date').on('click', function () {
            var s = $('#dash-start-date').val(), e = $('#dash-end-date').val();
            if (!s || !e) return;
            store.set('wp_ai_dash_start', s); store.set('wp_ai_dash_end', e);
            loadData();
        });
        $('#sync-history-btn').on('click', function () {
            var $b = $(this).prop('disabled', true).text('Syncing…');
            $.post(CFG.ajaxurl, { action: 'wp_ai_sync_historical_data', security: CFG.nonce }).always(function (res) {
                $b.prop('disabled', false).text('Sync history');
                if (res && typeof res.data === 'string') $('#akdk-range-label').text(res.data);
                loadData();
            });
        });

        renderChat();
        loadData().done(function () { if (!chatHistory.length) ask('INIT_GREETING'); });

        $('#admin-chat-send').on('click', function (e) {
            e.preventDefault();
            var msg = $.trim($('#admin-chat-input').val());
            if (!msg) return;
            chatHistory.push({ role: 'admin', text: msg }); saveChat(); renderChat();
            $('#admin-chat-input').val('');
            var $btn = $(this).prop('disabled', true);
            ask(msg).always(function () { $btn.prop('disabled', false); });
        });
        $('#admin-chat-input').on('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); $('#admin-chat-send').trigger('click'); } });
        $('#clear-admin-chat').on('click', function () {
            if (!window.confirm('Clear the Data Analyst conversation?')) return;
            chatHistory = []; saveChat(); renderChat(); ask('INIT_GREETING');
        });
    }

    /* ==================================================================
     * Design tab (live preview)
     * ================================================================== */
    function renderPreview() {
        var labels = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
        xyChart(document.getElementById('chart-preview-trend'), {
            type: 'line', labels: labels,
            series: [{ name: 'Conversations', color: color('c1'), data: [24, 31, 28, 42, 39, 51, 47] }, { name: 'Leads', color: color('c2'), data: [3, 5, 4, 7, 6, 9, 8] }]
        });
        donut(document.getElementById('chart-preview-forms'), [
            { label: 'Contact Us', value: 18, color: color('c1') }, { label: 'Get a Quote', value: 11, color: color('c3') },
            { label: 'Book a Call', value: 7, color: color('c4') }, { label: 'Newsletter', value: 5, color: color('c5') }, { label: 'Support', value: 3, color: color('c6') }
        ], { centerLabel: 'leads' });
    }

    function initDesign() {
        $('.akdk-design-color').each(function () {
            var $i = $(this);
            $i.wpColorPicker({ change: function (e, ui) { design[$i.data('key')] = ui.color.toString(); renderPreview(); } });
        });
        $('.akdk-design-flag').on('change', function () { design[$(this).data('key')] = this.checked ? '1' : '0'; renderPreview(); });
        $('#akdk-design-reset').on('click', function () {
            design = $.extend({}, CFG.defaults);
            $('.akdk-design-color').each(function () { $(this).wpColorPicker('color', CFG.defaults[$(this).data('key')]); $(this).val(CFG.defaults[$(this).data('key')]); });
            $('.akdk-design-flag').each(function () { this.checked = CFG.defaults[$(this).data('key')] === '1'; });
            renderPreview();
        });
        renderPreview();
    }

    $(function () {
        var $root = $('.akdk-dash');
        if (!$root.length) return;
        if ($root.data('tab') === 'design') initDesign(); else initOverview();
    });
})(jQuery);
