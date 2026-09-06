/**
 * Modal xem lại "Quà tặng ngoài chờ kế toán duyệt".
 *
 * KHÔNG dùng lại TGSBctkPhieuModal (tgs-bc-tk/assets/js/bctk-phieu-modal.js) —
 * cố ý làm modal RIÊNG vì payload ở đây là ảnh chụp giỏ hàng POS (chưa từng
 * là phiếu thật), không có các trường VAT (thuế suất/seri/mẫu HĐ...) mà modal
 * kia gắn chặt vào. Phong cách/khung thẻ vẫn bám theo cho quen mắt.
 *
 * Dữ liệu KHÔNG gọi AJAX để xem — toàn bộ bản ghi trong ngày đã được server
 * in sẵn vào #tgsPosGiftEntries (JSON), JS chỉ tra theo id.
 *
 * @package tgs_audit_log
 */
(function ($) {
    'use strict';

    var CFG = window.tgsPosGiftAdmin || {};
    var ENTRIES = {};

    function esc(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function fmtNum(n) {
        var v = Number(n || 0);
        try { return v.toLocaleString('vi-VN'); } catch (e) { return String(v); }
    }

    function fmtTime(unix, ts) {
        var d = unix ? new Date(unix * 1000) : (ts ? new Date(ts.replace(' ', 'T')) : null);
        if (!d || isNaN(d.getTime())) return ts || '';
        function p2(n) { return String(n).padStart(2, '0'); }
        return p2(d.getHours()) + ':' + p2(d.getMinutes()) + ' ' + p2(d.getDate()) + '/' + p2(d.getMonth() + 1) + '/' + d.getFullYear();
    }

    function loadEntries() {
        var el = document.getElementById('tgsPosGiftEntries');
        if (!el) return;
        var list = [];
        try { list = JSON.parse(el.textContent || '[]'); } catch (e) { list = []; }
        ENTRIES = {};
        (list || []).forEach(function (e) { if (e && e.id) ENTRIES[e.id] = e; });
    }

    function statusMeta(status) {
        if (status === 'auto') return { label: 'Tự động duyệt', cls: 'auto' };
        if (status === 'approved') return { label: 'Đã duyệt', cls: 'ok' };
        if (status === 'rejected') return { label: 'Từ chối', cls: 'err' };
        return { label: 'Chờ duyệt', cls: 'wait' };
    }

    /** Trạng thái hiệu lực: shop bật tự động → nhãn riêng 'auto'. */
    function effStatus(entry) {
        var ap = (entry && entry.changes && entry.changes.approval) || {};
        if (ap.auto) return 'auto';
        return ap.status || 'pending';
    }

    function ensureModalRoot() {
        var $root = $('#tgsPosGiftModalRoot');
        if ($root.length) return $root;
        $root = $('<div id="tgsPosGiftModalRoot" class="tgs-pg-modal-overlay" style="display:none"></div>');
        $('body').append($root);
        $root.on('click', function (ev) {
            if (ev.target === $root.get(0)) close();
        });
        return $root;
    }

    function close() {
        $('#tgsPosGiftModalRoot').hide().empty();
    }

    /**
     * Dòng ĐÃ TÍNH SẴN (CK, thành tiền) — gửi lên từ chính hàm bảng giỏ hàng
     * đang dùng để hiện số ở máy bán, nên số ở đây LUÔN khớp POS, không tự
     * suy công thức. Bản ghi cũ (trước khi thêm trường này) không có
     * `rows` — rơi về hiện bảng rút gọn (không CK/thành tiền) để không vỡ.
     */
    function renderLines(entry) {
        var ch = entry.changes || {};
        var snap = ch.cart_snapshot || {};
        var rows = Array.isArray(snap.rows) ? snap.rows : null;
        var cart = Array.isArray(snap.cart) ? snap.cart : [];

        if (rows && rows.length) {
            return renderPricedLines(rows) + renderTotals(snap.totals);
        }
        if (!cart.length) {
            return '<p class="text-muted">Không có dữ liệu giỏ hàng.</p>';
        }
        return renderRawLines(cart)
            + '<p class="text-muted" style="margin-top:6px;">Bản ghi cũ, chưa có sẵn CK/thành tiền từng dòng.</p>';
    }

    function renderPricedLines(rows) {
        var body = rows.map(function (item, i) {
            var isGift = !!item.tgsPosGiftPending;
            var note = item.note || '';
            return '<tr class="' + (isGift ? 'tgs-pg-row-gift' : '') + '">'
                + '<td>' + (i + 1) + '</td>'
                + '<td>' + esc(item.sku || '') + '</td>'
                + '<td>' + esc(item.name || '')
                + (isGift ? ' <span class="tgs-pg-badge">Quà tặng ngoài — thêm thủ công</span>' : '')
                + '</td>'
                + '<td>' + esc(item.unit || '') + '</td>'
                + '<td class="num">' + fmtNum(item.qty) + '</td>'
                + '<td class="num">' + fmtNum(item.price) + '</td>'
                + '<td class="num">' + fmtNum(item.discount) + '</td>'
                + '<td class="num"><b>' + fmtNum(item.lineTotal) + '</b></td>'
                + '<td>' + esc(note) + '</td>'
                + '</tr>';
        }).join('');

        return '<table class="tgs-au-lines tgs-pg-lines"><thead><tr>'
            + '<th>#</th><th>Mã hàng</th><th>Tên hàng</th><th>ĐVT</th>'
            + '<th class="num">SL</th><th class="num">Đơn giá</th><th class="num">CK</th>'
            + '<th class="num">Thành tiền</th><th>Ghi chú dòng</th>'
            + '</tr></thead><tbody>' + body + '</tbody></table>';
    }

    /** Bản ghi trước khi có `rows` — chỉ có dữ liệu thô, không CK/thành tiền */
    function renderRawLines(cart) {
        var rows = cart.map(function (item, i) {
            var isGift = !!item.tgsPosGiftPending;
            var unit = item.selectedSaleUnit || item.baseSaleUnit || '';
            var note = item.note || '';
            return '<tr class="' + (isGift ? 'tgs-pg-row-gift' : '') + '">'
                + '<td>' + (i + 1) + '</td>'
                + '<td>' + esc(item.sku || '') + '</td>'
                + '<td>' + esc(item.name || '')
                + (isGift ? ' <span class="tgs-pg-badge">Quà tặng ngoài — thêm thủ công</span>' : '')
                + '</td>'
                + '<td>' + esc(unit) + '</td>'
                + '<td class="num">' + fmtNum(item.qty) + '</td>'
                + '<td class="num">' + fmtNum(item.price) + '</td>'
                + '<td>' + esc(note) + '</td>'
                + '</tr>';
        }).join('');

        return '<table class="tgs-au-lines tgs-pg-lines"><thead><tr>'
            + '<th>#</th><th>Mã hàng</th><th>Tên hàng</th><th>ĐVT</th>'
            + '<th class="num">SL</th><th class="num">Đơn giá</th><th>Ghi chú dòng</th>'
            + '</tr></thead><tbody>' + rows + '</tbody></table>';
    }

    function renderTotals(totals) {
        if (!totals) return '';
        var subtotal = Number(totals.subtotal || 0);
        var discount = Number(totals.discount || 0);
        var grand = Number(totals.grandTotal || 0);
        return '<div class="tgs-pg-totals">'
            + '<div><span class="text-muted">Tiền hàng</span><b>' + fmtNum(subtotal) + 'đ</b></div>'
            + '<div><span class="text-muted">Chiết khấu</span><b>' + fmtNum(discount) + 'đ</b></div>'
            + '<div class="tgs-pg-totals-grand"><span>Tổng thanh toán</span><b>' + fmtNum(grand) + 'đ</b></div>'
            + '</div>';
    }

    function renderActions(entry) {
        var status = effStatus(entry);
        if (!CFG.canApprove) {
            return '<p class="text-muted">Bạn không có quyền duyệt tại đây.</p>';
        }
        if (status !== 'pending') {
            var meta = statusMeta(status);
            var who = (entry.changes && entry.changes.approval && entry.changes.approval.by) || '';
            var reason = (entry.changes && entry.changes.approval && entry.changes.approval.reason) || '';
            var extra = status === 'auto'
                ? ' — kế toán đang để tự động duyệt, không cần bấm gì. Xem lại bill ở trên nếu thấy bất thường.'
                : '';
            return '<p><span class="tgs-pg-status tgs-pg-status-' + meta.cls + '">' + meta.label + '</span>'
                + (who ? ' bởi <b>' + esc(who) + '</b>' : '')
                + (reason ? ' — ' + esc(reason) : '') + esc(extra) + '</p>';
        }
        // Ô lý do từ chối nằm SẴN trong modal (ẩn), không dùng window.prompt() —
        // giống cách hỏi "Tên kế toán" ở hộp "Chốt gửi kế toán duyệt" bên POS.
        return ''
            + '<div class="tgs-pg-actions">'
            + '  <div class="tgs-pg-actions-buttons">'
            + '    <button type="button" class="button button-primary tgs-pg-approve"><i class="bx bx-check"></i> Duyệt</button>'
            + '    <button type="button" class="button tgs-pg-reject"><i class="bx bx-x"></i> Từ chối</button>'
            + '    <span class="tgs-pg-action-msg"></span>'
            + '  </div>'
            + '  <div class="tgs-pg-reject-form" style="display:none">'
            + '    <label class="tgs-pg-reject-label">Lý do từ chối (không bắt buộc)</label>'
            + '    <input type="text" class="tgs-pg-reject-reason" placeholder="VD: quà không hợp lý, sai mã hàng…">'
            + '    <div class="tgs-pg-reject-form-btns">'
            + '      <button type="button" class="button tgs-pg-reject-cancel">Huỷ</button>'
            + '      <button type="button" class="button button-primary tgs-pg-reject-confirm">Xác nhận từ chối</button>'
            + '      <span class="tgs-pg-action-msg"></span>'
            + '    </div>'
            + '  </div>'
            + '</div>';
    }

    function render(entry) {
        var ch = entry.changes || {};
        var snap = ch.cart_snapshot || {};
        var status = effStatus(entry);
        var meta = statusMeta(status);
        var shop = ((entry.site && entry.site.code) || '') + (entry.site && entry.site.name ? ' (' + entry.site.name + ')' : '');
        var customerName = (snap.customer && (snap.customer.name || snap.customer.phone)) || 'Khách lẻ';

        var html = ''
            + '<div class="tgs-pg-modal">'
            + '  <div class="tgs-pg-modal-head">'
            + '    <div>'
            + '      <h3>Quà tặng ngoài chờ duyệt <span class="tgs-pg-status tgs-pg-status-' + meta.cls + '">' + meta.label + '</span></h3>'
            + '      <div class="tgs-pg-modal-sub">'
            + '        Shop ' + esc(shop) + ' · Phiếu <b>' + esc(entry.target && entry.target.code) + '</b>'
            + '        · Gửi lúc ' + fmtTime(entry.unix, entry.ts)
            + '      </div>'
            + '    </div>'
            + '    <button type="button" class="tgs-pg-close" title="Đóng">&times;</button>'
            + '  </div>'
            + '  <div class="tgs-pg-modal-body">'
            + '    <div class="tgs-pg-info-grid">'
            + '      <div><span class="text-muted">Nhân viên gửi</span><br><b>' + esc(entry.actor && (entry.actor.name || entry.actor.login)) + '</b></div>'
            + '      <div><span class="text-muted">Khách hàng</span><br><b>' + esc(customerName) + '</b></div>'
            + '      <div><span class="text-muted">Kế toán được nhắc</span><br><b>' + esc(ch.accountant_name || '—') + '</b></div>'
            + '    </div>'
            + (snap.note ? '<p class="tgs-pg-note"><span class="text-muted">Ghi chú hoá đơn:</span> ' + esc(snap.note) + '</p>' : '')
            + renderLines(entry)
            + '    <div class="tgs-au-meta"><span><i class="bx bx-hash"></i> Mã bản ghi: ' + esc(entry.id) + '</span></div>'
            + '  </div>'
            + '  <div class="tgs-pg-modal-foot">' + renderActions(entry) + '</div>'
            + '</div>';

        var $root = ensureModalRoot();
        $root.html(html).show();
        $root.find('.tgs-pg-close').on('click', close);

        $root.find('.tgs-pg-approve').on('click', function () { act('approve', entry, $root); });

        // Bấm "Từ chối" → hiện ô lý do TRONG modal, không bật popup trình duyệt.
        $root.find('.tgs-pg-reject').on('click', function () {
            $root.find('.tgs-pg-actions-buttons').hide();
            $root.find('.tgs-pg-reject-form').show();
            $root.find('.tgs-pg-reject-reason').trigger('focus');
        });
        $root.find('.tgs-pg-reject-cancel').on('click', function () {
            $root.find('.tgs-pg-reject-form').hide();
            $root.find('.tgs-pg-actions-buttons').show();
        });
        $root.find('.tgs-pg-reject-confirm').on('click', function () {
            var reason = String($root.find('.tgs-pg-reject-reason').val() || '').trim();
            act('reject', entry, $root, reason);
        });
        $root.find('.tgs-pg-reject-reason').on('keydown', function (ev) {
            if (ev.key === 'Enter') { $root.find('.tgs-pg-reject-confirm').trigger('click'); }
        });
    }

    function act(kind, entry, $root, reason) {
        var $btns = $root.find('.tgs-pg-approve, .tgs-pg-reject');
        var $msg = $root.find('.tgs-pg-action-msg');
        $btns.prop('disabled', true);
        $msg.text('Đang xử lý…');

        var fd = new FormData();
        fd.append('action', 'tgs_audit_pos_gift_' + kind);
        fd.append('nonce', CFG.nonce || '');
        fd.append('entry_id', entry.id);
        if (reason) fd.append('reason', reason);

        fetch(CFG.ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (out) {
                if (!out || !out.success) {
                    throw new Error((out && out.data && out.data.message) || 'Không thực hiện được.');
                }
                entry.changes = entry.changes || {};
                entry.changes.approval = {
                    status: out.data.status,
                    by: out.data.by,
                    at: new Date().toISOString(),
                    reason: reason || '',
                };
                ENTRIES[entry.id] = entry;
                render(entry);
                var $row = $('.tgs-pg-open[data-entry="' + entry.id + '"]').closest('tr');
                var meta = statusMeta(out.data.status);
                $row.find('.tgs-pg-status').attr('class', 'tgs-pg-status tgs-pg-status-' + meta.cls).text(meta.label);
            })
            .catch(function (err) {
                $msg.text(err.message || 'Lỗi.');
                $btns.prop('disabled', false);
            });
    }

    $(function () {
        loadEntries();

        $(document).on('click', '.tgs-pg-open', function () {
            var id = $(this).data('entry');
            var entry = ENTRIES[id];
            if (!entry) { return; }
            render(entry);
        });

        var auto = window.tgsPosGiftAutoOpen;
        if (auto && ENTRIES[auto]) {
            render(ENTRIES[auto]);
        }
    });
})(jQuery);
