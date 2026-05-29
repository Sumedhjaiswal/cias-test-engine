/* global CIAS_LIVE, jQuery */
(function ($) {
    'use strict';

    const API  = (CIAS_LIVE.apiBase || CIAS_LIVE.api_url || '').replace(/\/$/, '');
    const NONCE = CIAS_LIVE.nonce;

    // ── Status helpers ────────────────────────────────────────────────────
    const STATUS_BADGE = {
        scheduled : '<span class="cias-badge blue">Scheduled</span>',
        live      : '<span class="cias-badge green pulse">🔴 Live</span>',
        completed : '<span class="cias-badge grey">Completed</span>',
        cancelled : '<span class="cias-badge red">Cancelled</span>',
    };

    function timeLeft(startTime) {
        const diff = new Date(startTime) - new Date();
        if (diff <= 0) return null;
        const h = Math.floor(diff / 3600000);
        const m = Math.floor((diff % 3600000) / 60000);
        return h > 0 ? `${h}h ${m}m left` : `${m} mins left`;
    }

    function formatTime(dt) {
        return new Date(dt).toLocaleTimeString('en-IN', { hour: '2-digit', minute: '2-digit', hour12: true });
    }

    function formatDate(dt) {
        return new Date(dt).toLocaleDateString('en-IN', { day: 'numeric', month: 'short', year: 'numeric' });
    }

    function duration(start, end) {
        const mins = Math.round((new Date(end) - new Date(start)) / 60000);
        const h = Math.floor(mins / 60);
        const m = mins % 60;
        return h > 0 ? `${h}h ${m > 0 ? m + 'm' : ''}` : `${m}m`;
    }

    // ── API calls ─────────────────────────────────────────────────────────
    function apiGet(path, params = {}) {
        return $.ajax({ url: API + path, data: params, headers: { 'X-WP-Nonce': NONCE } });
    }

    function apiPost(path, data) {
        return $.ajax({
            url: API + path, method: 'POST',
            contentType: 'application/json',
            data: JSON.stringify(data),
            headers: { 'X-WP-Nonce': NONCE },
        });
    }

    function apiPut(path, data) {
        return $.ajax({
            url: API + path, method: 'PUT',
            contentType: 'application/json',
            data: JSON.stringify(data),
            headers: { 'X-WP-Nonce': NONCE },
        });
    }

    function apiDelete(path) {
        return $.ajax({ url: API + path, method: 'DELETE', headers: { 'X-WP-Nonce': NONCE } });
    }

    // ── Load & render grid ────────────────────────────────────────────────
    function loadClasses(filters = {}) {
        const $grid = $('#cias-classes-grid');
        $grid.html('<div class="cias-loading">Loading classes…</div>');

        apiGet('/classes', filters).done(function (res) {
            if (!res.classes || res.classes.length === 0) {
                $grid.html('<div class="cias-empty-state"><p>No classes found. Click <strong>+ Schedule Class</strong> to create one.</p></div>');
                return;
            }
            renderGrid(res.classes, $grid);
        }).fail(function () {
            $grid.html('<div class="cias-error">Failed to load classes. Please refresh.</div>');
        });
    }

    function renderGrid(classes, $grid) {
        // Group by date
        const groups = {};
        classes.forEach(c => {
            const d = formatDate(c.start_time);
            if (!groups[d]) groups[d] = [];
            groups[d].push(c);
        });

        let html = '';
        Object.entries(groups).forEach(([date, items]) => {
            html += `<div class="cias-date-group"><h4 class="cias-date-label">${date}</h4><div class="cias-cards-row">`;
            items.forEach(c => { html += renderCard(c); });
            html += '</div></div>';
        });
        $grid.html(html);
    }

    function renderCard(c) {
        const tl  = timeLeft(c.start_time);
        const badge = STATUS_BADGE[c.status] || c.status;
        const dur = duration(c.start_time, c.end_time);

        const canEdit   = c.status === 'scheduled';
        const canCancel = ['scheduled', 'live'].includes(c.status);
        const canStart  = ['scheduled', 'live'].includes(c.status);

        return `
        <div class="cias-class-card status-${c.status}" data-id="${c.id}">
            ${tl ? `<span class="cias-countdown">${tl}</span>` : ''}
            <div class="cias-class-title">${escHtml(c.title)}</div>
            <div class="cias-class-meta">
                <span>Session ID: ${escHtml(c.zoom_session_id)} <em>(Zoom)</em></span>
            </div>
            <div class="cias-class-info">
                <span>🕐 ${formatTime(c.start_time)} – ${formatTime(c.end_time)}</span>
                <span>👤 ${escHtml(c.teacher_name)}</span>
            </div>
            <div class="cias-class-status">${badge}</div>
            <div class="cias-class-actions">
                ${canStart  ? `<button class="cias-icon-btn cias-btn-start" data-url="${escHtml(c.start_url || c.join_url)}" title="Start Class">▶</button>` : ''}
                <button class="cias-icon-btn cias-btn-copy"   data-id="${c.id}" data-join="${escHtml(c.join_url)}" data-account="${escHtml(c.zoom_account)}" title="Copy Join Link">📋</button>
                ${canEdit   ? `<button class="cias-icon-btn cias-btn-edit"   data-id="${c.id}" title="Edit Class">✏️</button>` : ''}
                <button class="cias-icon-btn cias-btn-view"   data-id="${c.id}" title="View Details">👁</button>
                <button class="cias-icon-btn cias-btn-notify" data-id="${c.id}" title="Send WhatsApp Reminder">🔔</button>
                ${canCancel ? `<button class="cias-icon-btn cias-btn-cancel" data-id="${c.id}" title="Cancel Class">❌</button>` : ''}
                ${c.recording_url ? `<button class="cias-icon-btn cias-btn-recording" data-url="${escHtml(c.recording_url)}" title="View Recording">🎬</button>` : ''}
            </div>
        </div>`;
    }

    function escHtml(str) {
        return String(str || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    // ── Modal helpers ─────────────────────────────────────────────────────
    function openModal() {
        $('#cias-class-modal').fadeIn(200);
        $('body').addClass('cias-modal-open');
    }

    function closeModal() {
        $('#cias-class-modal, #cias-detail-modal').fadeOut(200);
        $('body').removeClass('cias-modal-open');
        resetForm();
    }

    function resetForm() {
        $('#cias-class-id').val('');
        $('#cias-field-title, #cias-field-date, #cias-field-from, #cias-field-to, #cias-field-duration').val('');
        $('#cias-field-teacher, #cias-field-batch').val('');
        $('#cias-field-recording, #cias-field-hostvideo, #cias-field-mute').prop('checked', true);
        $('#cias-form-error').hide().text('');
        $('#cias-modal-title').text('Schedule New Class');
        $('#cias-submit-label').text('Schedule Class');
    }

    function showError(msg) {
        $('#cias-form-error').text(msg).show();
    }

    // ── Duration auto-calc ─────────────────────────────────────────────────
    function calcDuration() {
        const from = $('#cias-field-from').val();
        const to   = $('#cias-field-to').val();
        if (from && to) {
            const [fh, fm] = from.split(':').map(Number);
            const [th, tm] = to.split(':').map(Number);
            const mins = (th * 60 + tm) - (fh * 60 + fm);
            if (mins > 0) {
                const h = Math.floor(mins / 60), m = mins % 60;
                $('#cias-field-duration').val(h > 0 ? `${h}h ${m > 0 ? m + 'm' : ''}` : `${m} mins`);
            }
        }
    }

    // ── Copy to clipboard ─────────────────────────────────────────────────
    function copyToClipboard(text) {
        if (navigator.clipboard) {
            navigator.clipboard.writeText(text);
        } else {
            const el = document.createElement('textarea');
            el.value = text;
            document.body.appendChild(el);
            el.select();
            document.execCommand('copy');
            document.body.removeChild(el);
        }
    }

    function showCopyTooltip($btn, msg) {
        const $tip = $('<span class="cias-tooltip">').text(msg).appendTo($btn.parent());
        const off  = $btn.offset();
        $tip.css({ top: off.top - 30, left: off.left }).fadeIn(150);
        setTimeout(() => $tip.fadeOut(200, () => $tip.remove()), 2000);
    }

    // ── View Details Modal ────────────────────────────────────────────────
    function openDetailModal(cls) {
        const html = `
            <div class="cias-detail-grid">
                <div class="cias-detail-row">
                    <span class="cias-detail-label">Class</span>
                    <span class="cias-detail-value"><strong>${escHtml(cls.title)}</strong></span>
                </div>
                <div class="cias-detail-row">
                    <span class="cias-detail-label">Session ID</span>
                    <span class="cias-detail-value">${escHtml(cls.zoom_session_id)} <em>(Zoom)</em></span>
                </div>
                <div class="cias-detail-row">
                    <span class="cias-detail-label">Zoom Account</span>
                    <span class="cias-detail-value">${escHtml(cls.zoom_account)}</span>
                </div>
                <div class="cias-detail-row">
                    <span class="cias-detail-label">Join Link</span>
                    <span class="cias-detail-value">
                        <button class="button button-small cias-copy-join-detail" data-url="${escHtml(cls.join_url)}" data-account="${escHtml(cls.zoom_account)}">
                            📋 Copy Join Link
                        </button>
                    </span>
                </div>
                <div class="cias-detail-row">
                    <span class="cias-detail-label">Teacher</span>
                    <span class="cias-detail-value">${escHtml(cls.teacher_name)}</span>
                </div>
                <div class="cias-detail-row">
                    <span class="cias-detail-label">Date</span>
                    <span class="cias-detail-value">${formatDate(cls.start_time)}</span>
                </div>
                <div class="cias-detail-row">
                    <span class="cias-detail-label">Time</span>
                    <span class="cias-detail-value">${formatTime(cls.start_time)} – ${formatTime(cls.end_time)}</span>
                </div>
                <div class="cias-detail-row">
                    <span class="cias-detail-label">Duration</span>
                    <span class="cias-detail-value">${duration(cls.start_time, cls.end_time)}</span>
                </div>
                <div class="cias-detail-row">
                    <span class="cias-detail-label">Batch</span>
                    <span class="cias-detail-value">${escHtml(cls.batch_name)}</span>
                </div>
                <div class="cias-detail-row">
                    <span class="cias-detail-label">Status</span>
                    <span class="cias-detail-value">${STATUS_BADGE[cls.status] || cls.status}</span>
                </div>
                ${cls.recording_url ? `
                <div class="cias-detail-row">
                    <span class="cias-detail-label">Recording</span>
                    <span class="cias-detail-value"><a href="${escHtml(cls.recording_url)}" target="_blank">▶ View Recording</a></span>
                </div>` : ''}
            </div>`;
        $('#cias-detail-content').html(html);
        $('#cias-detail-modal').fadeIn(200);
        $('body').addClass('cias-modal-open');
    }

    // ── Event bindings ────────────────────────────────────────────────────
    $(document).ready(function () {

        // Load classes on page load
        if ($('#cias-classes-grid').length) {
            loadClasses({ date_from: new Date().toISOString().split('T')[0] });
        }

        // Open schedule modal
        $(document).on('click', '#cias-add-class-btn', function () {
            resetForm();
            openModal();
        });

        // Close modals
        $(document).on('click', '.cias-modal-close, .cias-modal-overlay, #cias-modal-cancel-btn', closeModal);

        // Duration auto-calc
        $(document).on('change', '#cias-field-from, #cias-field-to', calcDuration);

        // Filter
        $(document).on('click', '#cias-filter-btn', function () {
            loadClasses({
                status    : $('#cias-filter-status').val(),
                batch_id  : $('#cias-filter-batch').val(),
                date_from : $('#cias-filter-date').val(),
            });
        });

        $(document).on('click', '#cias-filter-clear', function () {
            $('#cias-filter-status, #cias-filter-batch').val('');
            $('#cias-filter-date').val(new Date().toISOString().split('T')[0]);
            loadClasses({ date_from: new Date().toISOString().split('T')[0] });
        });

        // Submit (create or update)
        $(document).on('click', '#cias-modal-submit-btn', function () {
            const classId = $('#cias-class-id').val();
            const date    = $('#cias-field-date').val();
            const from    = $('#cias-field-from').val();
            const to      = $('#cias-field-to').val();

            if (!$('#cias-field-title').val() || !date || !from || !to || !$('#cias-field-teacher').val() || !$('#cias-field-batch').val()) {
                showError('Please fill all required fields.');
                return;
            }

            const payload = {
                title         : $('#cias-field-title').val(),
                start_time    : date + ' ' + from + ':00',
                end_time      : date + ' ' + to + ':00',
                teacher_id    : $('#cias-field-teacher').val(),
                batch_id      : $('#cias-field-batch').val(),
                auto_recording: $('#cias-field-recording').is(':checked') ? 1 : 0,
                host_video    : $('#cias-field-hostvideo').is(':checked') ? 1 : 0,
                mute_on_entry : $('#cias-field-mute').is(':checked') ? 1 : 0,
            };

            $('#cias-form-error').hide();
            $('#cias-modal-submit-btn').prop('disabled', true);
            $('#cias-submit-spinner').show();

            const req = classId
                ? apiPut('/classes/' + classId, payload)
                : apiPost('/classes', payload);

            req.done(function (res) {
                if (res.success) {
                    closeModal();
                    loadClasses({ date_from: new Date().toISOString().split('T')[0] });
                } else {
                    showError(res.message || 'Something went wrong.');
                }
            }).fail(function (xhr) {
                const msg = xhr.responseJSON?.message || 'Request failed. Please try again.';
                showError(msg);
            }).always(function () {
                $('#cias-modal-submit-btn').prop('disabled', false);
                $('#cias-submit-spinner').hide();
            });
        });

        // ── Card actions ──────────────────────────────────────────────────

        // Start class
        $(document).on('click', '.cias-btn-start', function () {
            const url = $(this).data('url');
            if (url) window.open(url, '_blank');
        });

        // Copy join link
        $(document).on('click', '.cias-btn-copy', function () {
            const $btn    = $(this);
            const joinUrl = $btn.data('join');
            const account = $btn.data('account');
            copyToClipboard(joinUrl);
            showCopyTooltip($btn, '✓ Copied! Account: ' + account);
        });

        // Copy in detail modal
        $(document).on('click', '.cias-copy-join-detail', function () {
            const url     = $(this).data('url');
            const account = $(this).data('account');
            copyToClipboard(url);
            $(this).text('✓ Copied! (' + account + ')');
            setTimeout(() => $(this).text('📋 Copy Join Link'), 2500);
        });

        // Edit class
        $(document).on('click', '.cias-btn-edit', function () {
            const id = $(this).data('id');
            apiGet('/classes/' + id).done(function (cls) {
                $('#cias-class-id').val(cls.id);
                $('#cias-field-title').val(cls.title);
                const startDate = cls.start_time.split(' ')[0];
                const startTime = cls.start_time.split(' ')[1].substring(0, 5);
                const endTime   = cls.end_time.split(' ')[1].substring(0, 5);
                $('#cias-field-date').val(startDate);
                $('#cias-field-from').val(startTime);
                $('#cias-field-to').val(endTime);
                $('#cias-field-teacher').val(cls.teacher_id);
                $('#cias-field-batch').val(cls.batch_id);
                $('#cias-field-recording').prop('checked', cls.auto_recording == 1);
                $('#cias-field-hostvideo').prop('checked', cls.host_video == 1);
                $('#cias-field-mute').prop('checked', cls.mute_on_entry == 1);
                calcDuration();
                $('#cias-modal-title').text('Edit Class');
                $('#cias-submit-label').text('Update Class');
                openModal();
            });
        });

        // View details
        $(document).on('click', '.cias-btn-view', function () {
            const id = $(this).data('id');
            apiGet('/classes/' + id).done(function (cls) {
                openDetailModal(cls);
            });
        });

        // Notify via WhatsApp
        $(document).on('click', '.cias-btn-notify', function () {
            const id = $(this).data('id');
            if (!confirm('Send WhatsApp reminder to all students in this batch?')) return;
            apiPost('/classes/' + id + '/notify', {}).done(function (res) {
                alert(res.message || 'Notification sent!');
            }).fail(function () {
                alert('Failed to send notification.');
            });
        });

        // Cancel class
        $(document).on('click', '.cias-btn-cancel', function () {
            const id = $(this).data('id');
            if (!confirm('Cancel this class? The Zoom meeting will be deleted and the host account will be released.')) return;
            apiDelete('/classes/' + id).done(function (res) {
                if (res.success) {
                    loadClasses({ date_from: new Date().toISOString().split('T')[0] });
                } else {
                    alert(res.message || 'Failed to cancel.');
                }
            });
        });

        // View recording
        $(document).on('click', '.cias-btn-recording', function () {
            const url = $(this).data('url');
            if (url) window.open(url, '_blank');
        });

        // Lock / unlock host buttons
        $(document).on('click', '.cias-lock-host', function () {
            const id = $(this).data('id');
            $.post(API + '/zoom-hosts/' + id + '/lock', {}, null, 'json')
                .done(() => location.reload());
        });

        $(document).on('click', '.cias-unlock-host', function () {
            const id = $(this).data('id');
            $.post(API + '/zoom-hosts/' + id + '/unlock', {}, null, 'json')
                .done(() => location.reload());
        });
    });

})(jQuery);
