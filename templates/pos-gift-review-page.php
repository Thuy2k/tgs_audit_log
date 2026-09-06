<?php

/**
 * Nhật ký chờ kế toán duyệt — quà tặng ngoài nhân viên thêm thủ công ở giỏ
 * hàng POS (tgs_pos). Không đọc từ DB nào — toàn bộ dữ liệu là JSONL của
 * TGS_Audit_Store, channel 'pos_gift'.
 *
 * Chạy trong hệ quản trị tgs_shop_management (Bootstrap + boxicons có sẵn),
 * cùng khuôn thẻ/bảng với templates/audit-log-page.php.
 *
 * @package tgs_audit_log
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!current_user_can(TGS_Audit_Log::VIEW_CAP)) {
    echo '<div class="notice notice-error"><p>Bạn không có quyền xem trang này.</p></div>';
    return;
}

$today  = current_time('Y-m-d');
$f_date = isset($_GET['date']) ? sanitize_text_field(wp_unslash($_GET['date'])) : $today;
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $f_date)) {
    $f_date = $today;
}
$f_status = isset($_GET['status']) ? sanitize_key(wp_unslash($_GET['status'])) : '';
$open_id  = isset($_GET['entry']) ? sanitize_text_field(wp_unslash($_GET['entry'])) : '';

/** Trạng thái hiệu lực: đơn shop bật tự động thì gắn nhãn riêng 'auto'. */
$eff_status = static function ($e) {
    if (!empty($e['changes']['approval']['auto'])) {
        return 'auto';
    }
    return (string) ($e['changes']['approval']['status'] ?? 'pending');
};

$logs = TGS_Audit_Store::read_date($f_date, ['channel' => 'pos_gift']);
if ($f_status !== '') {
    $logs = array_values(array_filter($logs, static function ($e) use ($f_status, $eff_status) {
        return $eff_status($e) === $f_status;
    }));
}

// Bản ghi được liên kết (từ link Zalo) có thể ở ngày khác ngày đang lọc
$linked = null;
if ($open_id !== '') {
    $on_page = false;
    foreach ($logs as $l) {
        if (($l['id'] ?? '') === $open_id) {
            $on_page = true;
            break;
        }
    }
    if (!$on_page) {
        $linked = TGS_Audit_Store::find_by_id($open_id);
    }
}

$dates_avail = TGS_Audit_Store::list_dates(90);
$can_approve = TGS_Audit_Pos_Gift_Admin::can_approve();

$status_label = static function ($status) {
    switch ($status) {
        case 'approved': return ['Đã duyệt', 'ok'];
        case 'rejected': return ['Từ chối', 'err'];
        case 'auto': return ['Tự động duyệt', 'auto'];
        default: return ['Chờ duyệt', 'wait'];
    }
};

/*
 * Toàn bộ bản ghi hiển thị trong ngày (+ bản liên kết nếu khác ngày) được in
 * ra một khối JSON ẩn — JS đọc từ đây khi mở modal, KHÔNG gọi lại AJAX chỉ để
 * xem chi tiết (đã có sẵn hết trong trang).
 */
$all_for_json = $logs;
if ($linked) {
    $all_for_json[] = $linked;
}
?>

<div class="tgs-audit-wrap tgs-pos-gift-wrap">

    <div class="tgs-au-head">
        <h4><i class="bx bx-gift"></i> Nhật ký chờ kế toán duyệt</h4>
        <p class="text-muted">
            Quà tặng ngoài nhân viên thêm thủ công ở giỏ hàng POS — chưa lên đơn thật,
            chỉ ghi tạm ở đây. Duyệt xong nhân viên mới bấm Thanh toán được.
        </p>
    </div>

    <?php if ($can_approve) : ?>
    <div class="tgs-au-card tgs-pg-auto-card" id="tgsPgAutoCard">
        <div class="tgs-au-card-head"><i class="bx bx-bolt-circle"></i> Kế toán để tự động duyệt (theo shop)</div>
        <div class="tgs-au-card-body">
            <p class="text-muted" style="margin-top:0">
                Chọn shop rồi bật — khi nhân viên shop đó bấm <b>"Chốt gửi kế toán duyệt"</b> ở POS
                thì đơn <b>tự động được duyệt luôn</b>, nhân viên Thanh toán được ngay.
                <b>Kế toán là người quyết định bật/tắt</b> (nhân viên chỉ làm theo). Zalo vẫn gửi
                vào nhóm chung — ghi rõ <i>"đơn đã tự động duyệt"</i> để kế toán xem lại nếu thấy
                bất thường. Mặc định TẮT hết.
            </p>
            <div class="tgs-pg-auto-row" style="display:flex;gap:14px;align-items:flex-end;flex-wrap:wrap">
                <label>Shop
                    <select id="tgsPgAutoShop" style="min-width:260px"></select>
                </label>
                <span class="tgs-pg-switch-wrap">
                    <label class="tgs-pg-switch">
                        <input type="checkbox" id="tgsPgAutoOn">
                        <span class="tgs-pg-switch-track"><span class="tgs-pg-switch-knob"></span></span>
                    </label>
                    <b id="tgsPgAutoOnText" class="tgs-pg-switch-text">—</b>
                </span>
                <button type="button" class="button button-primary" id="tgsPgAutoSave">
                    <i class="bx bx-save"></i> Lưu
                </button>
                <span id="tgsPgAutoMsg" class="text-muted"></span>
            </div>
            <div class="tgs-pg-auto-bulk" style="margin-top:10px;display:flex;gap:8px;flex-wrap:wrap;align-items:center">
                <button type="button" class="button" id="tgsPgAutoAllOn">
                    <i class="bx bx-list-check"></i> Bật tự động cho TẤT CẢ shop
                </button>
                <button type="button" class="button" id="tgsPgAutoAllOff">
                    <i class="bx bx-x-circle"></i> Tắt tự động toàn bộ
                </button>
                <span id="tgsPgAutoCount" class="text-muted"></span>
            </div>
            <div id="tgsPgAutoList" style="margin-top:10px"></div>
        </div>
    </div>
    <?php endif; ?>

    <form method="get" class="tgs-au-card tgs-au-filters">
        <div class="tgs-au-card-head"><i class="bx bx-filter-alt"></i> Bộ lọc</div>
        <div class="tgs-au-card-body tgs-au-filter-row">
            <input type="hidden" name="page" value="tgs-shop-management">
            <input type="hidden" name="view" value="<?php echo esc_attr(TGS_Audit_Pos_Gift_Admin::VIEW); ?>">
            <label>Ngày
                <input type="date" name="date" value="<?php echo esc_attr($f_date); ?>">
            </label>
            <label>Trạng thái
                <select name="status">
                    <option value="" <?php selected($f_status, ''); ?>>— Tất cả —</option>
                    <option value="pending" <?php selected($f_status, 'pending'); ?>>Chờ duyệt</option>
                    <option value="approved" <?php selected($f_status, 'approved'); ?>>Đã duyệt</option>
                    <option value="auto" <?php selected($f_status, 'auto'); ?>>Tự động duyệt</option>
                    <option value="rejected" <?php selected($f_status, 'rejected'); ?>>Từ chối</option>
                </select>
            </label>
            <button type="submit" class="button button-primary"><i class="bx bx-search"></i> Lọc</button>
        </div>
    </form>

    <p class="tgs-au-count">
        Ngày <b><?php echo esc_html(date_i18n('d/m/Y', strtotime($f_date))); ?></b> ·
        <b><?php echo count($logs); ?></b> bản ghi
        <?php if ($dates_avail) : ?>
            · Ngày có dữ liệu:
            <?php
            $links = [];
            foreach (array_slice($dates_avail, 0, 14) as $d) {
                $links[] = '<a href="' . esc_url(remove_query_arg('entry', add_query_arg(['date' => $d]))) . '">'
                    . esc_html(date_i18n('d/m', strtotime($d))) . '</a>';
            }
            echo implode(' · ', $links);
            ?>
        <?php endif; ?>
    </p>

    <div class="tgs-au-card tgs-au-table-card">
        <div class="tgs-au-card-head"><i class="bx bx-list-ul"></i> Danh sách gửi duyệt</div>
        <div class="tgs-au-table-wrap">
            <table class="tgs-au-table widefat" id="tgsPosGiftTable">
                <thead>
                    <tr>
                        <th>Thời gian</th>
                        <th>Shop</th>
                        <th>Mã phiếu</th>
                        <th>Nhân viên gửi</th>
                        <th>Dòng quà</th>
                        <th>Tóm tắt</th>
                        <th>Trạng thái</th>
                        <th>Zalo</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$logs) : ?>
                    <tr><td colspan="9" class="tgs-au-empty">Chưa có yêu cầu duyệt nào trong ngày này.</td></tr>
                <?php endif; ?>
                <?php foreach ($logs as $e) :
                    $eid = (string) ($e['id'] ?? '');
                    $st  = $eff_status($e);
                    [$st_label, $st_cls] = $status_label($st);
                    $zsent = !empty($e['zalo']['sent']);
                    ?>
                    <tr>
                        <td class="tgs-au-time"><?php echo esc_html(date_i18n('H:i d/m', (int) ($e['unix'] ?? strtotime($e['ts'] ?? 'now')))); ?></td>
                        <td><?php echo esc_html(trim(($e['site']['code'] ?? '') . ' ' . ($e['site']['name'] ?? ''))) ?: '—'; ?></td>
                        <td class="tgs-au-doc"><?php echo esc_html($e['target']['code'] ?? '—'); ?></td>
                        <td><?php echo esc_html($e['actor']['name'] ?? ($e['actor']['login'] ?? '—')); ?></td>
                        <td><?php echo (int) count($e['changes']['gift_lines'] ?? []); ?></td>
                        <td class="tgs-au-sum"><?php echo esc_html($e['summary'] ?? ''); ?></td>
                        <td><span class="tgs-pg-status tgs-pg-status-<?php echo esc_attr($st_cls); ?>"><?php echo esc_html($st_label); ?></span></td>
                        <td>
                            <?php if ($zsent) : ?>
                                <span class="tgs-au-z tgs-au-z-ok">✔ Đã gửi</span>
                            <?php elseif (!empty($e['zalo']['error'])) : ?>
                                <span class="tgs-au-z tgs-au-z-err" title="<?php echo esc_attr($e['zalo']['error']); ?>">✖ Lỗi</span>
                            <?php else : ?>
                                <span class="tgs-au-z tgs-au-z-wait">… chờ</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <button type="button" class="button button-small tgs-pg-open" data-entry="<?php echo esc_attr($eid); ?>">
                                <i class="bx bx-show"></i> Xem
                            </button>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script type="application/json" id="tgsPosGiftEntries">
    <?php echo wp_json_encode($all_for_json, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP); ?>
</script>
<script>
    window.tgsPosGiftAutoOpen = <?php echo wp_json_encode($open_id); ?>;
</script>

<?php if ($can_approve) : ?>
<script>
(function () {
  function initTgsPgAuto() {
    // window.tgsPosGiftAdmin (wp_localize_script) in ở footer — chưa có lúc
    // khối inline này được parse, nên đợi DOMContentLoaded rồi mới đọc.
    var CFG = window.tgsPosGiftAdmin || {};
    var card = document.getElementById('tgsPgAutoCard');
    if (!card || !CFG.ajaxUrl) { return; }

    var $shop = document.getElementById('tgsPgAutoShop');
    var $on = document.getElementById('tgsPgAutoOn');
    var $onText = document.getElementById('tgsPgAutoOnText');
    var $save = document.getElementById('tgsPgAutoSave');
    var $msg = document.getElementById('tgsPgAutoMsg');
    var $list = document.getElementById('tgsPgAutoList');
    var $count = document.getElementById('tgsPgAutoCount');
    var $allOn = document.getElementById('tgsPgAutoAllOn');
    var $allOff = document.getElementById('tgsPgAutoAllOff');

    var sites = [];

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    function post(action, extra) {
        var body = new URLSearchParams(Object.assign({ action: action, nonce: CFG.nonce || '' }, extra || {}));
        return fetch(CFG.ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin' })
            .then(function (r) { return r.json(); });
    }

    function currentSite() {
        var id = Number($shop.value || 0);
        return sites.find(function (s) { return Number(s.blog_id) === id; }) || null;
    }

    function paintSwitch() {
        var on = $on.checked;
        $onText.textContent = on ? '✓ ĐANG để tự động duyệt' : 'Đang TẮT (kế toán duyệt tay)';
        $onText.className = 'tgs-pg-switch-text ' + (on ? 'is-on' : 'is-off');
    }

    function syncCheckbox() {
        var s = currentSite();
        $on.checked = !!(s && s.auto);
        paintSwitch();
    }

    function render(data) {
        sites = (data && data.sites) || [];
        $shop.innerHTML = sites.map(function (s) {
            return '<option value="' + Number(s.blog_id) + '">'
                + esc(s.name || ('Shop #' + s.blog_id)) + ' (' + esc(s.code || s.blog_id) + ')'
                + (s.auto ? ' — ĐANG BẬT' : '') + '</option>';
        }).join('');
        syncCheckbox();

        var on = sites.filter(function (s) { return s.auto; });
        $count.textContent = on.length + '/' + sites.length + ' shop đang bật tự động';
        $list.innerHTML = on.length
            ? '<b>Đang bật tự động:</b> ' + on.map(function (s) {
                return esc((s.code || '') + ' ' + (s.name || '')).trim();
            }).join(' · ')
            : '<span class="text-muted">Chưa shop nào bật — tất cả vẫn chờ kế toán duyệt tay.</span>';
    }

    function load() {
        $msg.textContent = 'Đang tải…';
        post('tgs_audit_pos_gift_auto_get').then(function (out) {
            $msg.textContent = '';
            if (!out || !out.success) {
                $msg.textContent = (out && out.data && out.data.message) || 'Lỗi tải dữ liệu.';
                return;
            }
            render(out.data);
        }).catch(function () { $msg.textContent = 'Lỗi kết nối.'; });
    }

    $shop.addEventListener('change', syncCheckbox);
    $on.addEventListener('change', paintSwitch);

    $save.addEventListener('click', function () {
        var s = currentSite();
        if (!s) { return; }
        $save.disabled = true;
        $msg.textContent = 'Đang lưu…';
        post('tgs_audit_pos_gift_auto_save', { blog_id: s.blog_id, auto: $on.checked ? '1' : '0' })
            .then(function (out) {
                $save.disabled = false;
                if (!out || !out.success) {
                    $msg.textContent = (out && out.data && out.data.message) || 'Lưu thất bại.';
                    return;
                }
                $msg.textContent = out.data.message || 'Đã lưu.';
                load();
            }).catch(function () { $save.disabled = false; $msg.textContent = 'Lỗi kết nối.'; });
    });

    function bulk(mode, confirmText) {
        if (!window.confirm(confirmText)) { return; }
        $msg.textContent = 'Đang áp dụng…';
        post('tgs_audit_pos_gift_auto_bulk', { mode: mode }).then(function (out) {
            if (!out || !out.success) {
                $msg.textContent = (out && out.data && out.data.message) || 'Thất bại.';
                return;
            }
            $msg.textContent = out.data.message || 'Xong.';
            load();
        }).catch(function () { $msg.textContent = 'Lỗi kết nối.'; });
    }

    $allOn.addEventListener('click', function () {
        bulk('all_on', 'BẬT tự động duyệt quà tặng ngoài cho TẤT CẢ shop?\nTừ giờ mọi shop chốt gửi là đơn tự qua luôn.');
    });
    $allOff.addEventListener('click', function () {
        bulk('all_off', 'TẮT tự động duyệt cho toàn bộ shop?\nMọi shop quay lại chờ kế toán duyệt tay.');
    });

    load();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initTgsPgAuto);
  } else {
    initTgsPgAuto();
  }
})();
</script>
<?php endif; ?>
