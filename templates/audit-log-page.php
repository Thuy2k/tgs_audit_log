<?php

/**
 * Trang xem nhật ký thao tác nghiệp vụ (PM mới).
 *
 * Chạy trong hệ quản trị tgs_shop_management (Bootstrap + boxicons có sẵn).
 * Server render theo tham số GET — giống tgs-activities-admin/templates/erp-page.php.
 *
 * @package tgs_audit_log
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!current_user_can(TGS_Audit_Log::VIEW_CAP)) {
    echo '<div class="notice notice-error"><p>Bạn không có quyền xem nhật ký.</p></div>';
    return;
}

$today   = current_time('Y-m-d');
$f_date  = isset($_GET['date']) ? sanitize_text_field(wp_unslash($_GET['date'])) : $today;
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $f_date)) {
    $f_date = $today;
}
$f_chan  = isset($_GET['channel']) ? sanitize_key(wp_unslash($_GET['channel'])) : '';
$f_act   = isset($_GET['action_key']) ? sanitize_key(wp_unslash($_GET['action_key'])) : '';
$f_blog  = isset($_GET['blog']) ? (int) $_GET['blog'] : 0;
$f_actor = isset($_GET['actor']) ? sanitize_text_field(wp_unslash($_GET['actor'])) : '';
$f_q     = isset($_GET['q']) ? sanitize_text_field(wp_unslash($_GET['q'])) : '';
$f_sev   = !empty($_GET['sev']) ? 'sensitive' : '';
$open_id = isset($_GET['entry']) ? sanitize_text_field(wp_unslash($_GET['entry'])) : '';
$flash   = isset($_GET['tgs_audit_msg']) ? sanitize_key(wp_unslash($_GET['tgs_audit_msg'])) : '';

$filters = array_filter([
    'channel'  => $f_chan,
    'action'   => $f_act,
    'blog_id'  => $f_blog,
    'actor'    => $f_actor,
    'q'        => $f_q,
    'severity' => $f_sev,
]);

$logs = TGS_Audit_Store::read_date($f_date, $filters);

// Bản ghi được liên kết (từ tin Zalo) — có thể khác ngày đang lọc
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
$can_manage  = TGS_Audit_Admin::can_manage();
$post_url    = admin_url('admin-post.php');

/* Lựa chọn cho dropdown: shop lấy từ dữ liệu ngày, nghiệp vụ lấy từ LABELS */
$shop_opts = [];
foreach ($logs as $l) {
    $bid = (int) ($l['site']['blog_id'] ?? 0);
    if ($bid > 0 && !isset($shop_opts[$bid])) {
        $lbl = trim((string) ($l['site']['code'] ?? ''));
        if (!empty($l['site']['name'])) {
            $lbl = ($lbl !== '' ? $lbl . ' — ' : '') . $l['site']['name'];
        }
        $shop_opts[$bid] = $lbl !== '' ? $lbl : ('Site #' . $bid);
    }
}
asort($shop_opts);

/**
 * In một dòng chi tiết (bảng trước↔sau). Dùng cho cả bảng chính lẫn card liên kết.
 */
$render_detail = static function (array $e) {
    $ch = $e['changes'] ?? [];
    $g  = $ch['grand_total'] ?? null;
    $lines = $ch['lines'] ?? null;
    $note  = $ch['note'] ?? null;

    ?>
    <div class="tgs-au-detail-inner">
        <?php if ($g && (isset($g['before']) || isset($g['after']))) : ?>
            <p class="tgs-au-grand">
                <span class="text-muted">Tổng phiếu:</span>
                <b><?php echo number_format_i18n((float) ($g['before'] ?? 0)); ?>đ</b>
                <i class="bx bx-right-arrow-alt"></i>
                <b><?php echo number_format_i18n((float) ($g['after'] ?? 0)); ?>đ</b>
                <?php if (!empty($ch['warning'])) : ?>
                    <span class="tgs-au-warn">⚠ <?php echo esc_html($ch['warning']); ?></span>
                <?php endif; ?>
            </p>
        <?php endif; ?>

        <?php if (is_array($note)) : ?>
            <div class="tgs-au-note">
                <div><span class="text-muted">Ghi chú cũ:</span> <?php echo esc_html($note['before'] !== '' ? $note['before'] : '(trống)'); ?></div>
                <div><span class="text-muted">Ghi chú mới:</span> <b><?php echo esc_html($note['after'] !== '' ? $note['after'] : '(trống)'); ?></b></div>
            </div>
        <?php endif; ?>

        <?php if (is_array($lines) && !empty($lines['rows'])) : ?>
            <table class="tgs-au-lines">
                <thead>
                    <tr>
                        <th>Trạng thái</th><th>Mã hàng</th><th>Tên hàng</th><th>ĐVT</th>
                        <th class="num">SL</th><th class="num">Đơn giá</th><th class="num">CK</th>
                        <th class="num">Thuế %</th><th class="num">Tiền thuế</th><th>Số lô</th><th>HSD</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($lines['rows'] as $r) :
                    $state = $r['state'] ?? 'modified';
                    $chg = array_flip($r['fields'] ?? []);
                    $badge = ['added' => 'Thêm', 'removed' => 'Xoá', 'modified' => 'Sửa'][$state] ?? $state;
                    $srow = $state === 'added' ? ($r['after'] ?? null) : ($r['before'] ?? null);
                    ?>
                    <tr class="tgs-au-<?php echo esc_attr($state); ?>">
                        <td><span class="tgs-au-badge tgs-au-badge-<?php echo esc_attr($state); ?>"><?php echo esc_html($badge); ?></span></td>
                        <?php foreach (['sku', 'ten', 'dvt', 'sl', 'don_gia', 'ck', 'thue_pct', 'thue', 'so_lo', 'exp'] as $k) : ?>
                            <?php
                            if ($state === 'modified') {
                                $b = $r['before'][$k] ?? '';
                                $a = $r['after'][$k] ?? '';
                                $is_chg = isset($chg[$k]);
                                echo '<td' . ($is_chg ? ' class="tgs-au-chg"' : '') . '>';
                                if ($is_chg) {
                                    echo '<span class="old">' . ($b === '' ? '—' : (is_numeric($b) ? number_format_i18n((float) $b) : esc_html($b))) . '</span> ';
                                    echo '<i class="bx bx-right-arrow-alt"></i> ';
                                    echo '<b>' . ($a === '' ? '—' : (is_numeric($a) ? number_format_i18n((float) $a) : esc_html($a))) . '</b>';
                                } else {
                                    echo $a === '' ? '—' : (is_numeric($a) ? number_format_i18n((float) $a) : esc_html($a));
                                }
                                echo '</td>';
                            } else {
                                $v = $srow[$k] ?? '';
                                echo '<td>' . ($v === '' ? '—' : (is_numeric($v) ? number_format_i18n((float) $v) : esc_html($v))) . '</td>';
                            }
                            ?>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php if (!empty($lines['unchanged'])) : ?>
                <p class="text-muted tgs-au-unchg"><?php echo (int) $lines['unchanged']; ?> dòng không đổi (ẩn).</p>
            <?php endif; ?>
        <?php elseif (!is_array($note)) : ?>
            <p class="text-muted">Không có thay đổi dòng hàng.</p>
        <?php endif; ?>

        <div class="tgs-au-meta">
            <span><i class="bx bx-globe"></i> Địa chỉ IP: <?php echo esc_html($e['context']['ip'] ?? '—'); ?></span>
            <span><i class="bx bx-link"></i> Đường dẫn: <?php echo esc_html($e['context']['uri'] ?? '—'); ?></span>
            <span><i class="bx bx-hash"></i> Mã bản ghi: <?php echo esc_html($e['id']); ?></span>
        </div>
    </div>
    <?php
};
?>

<div class="tgs-audit-wrap">

    <div class="tgs-au-head">
        <h4><i class="bx bx-history"></i> Nhật ký thao tác nghiệp vụ — phần mềm mới</h4>
        <p class="text-muted">
            Ghi lại mọi lần thêm / sửa / xoá chứng từ trên phần mềm mới, kèm so sánh
            trước ↔ sau. Việc nhạy cảm (sửa dòng phiếu xuất bán…) được bắn Zalo ngay
            cho nhóm triển khai.
        </p>
    </div>

    <?php if ($flash) : ?>
        <?php
        $flash_map = [
            'saved'        => ['ok', 'Đã lưu cấu hình.'],
            'resend_ok'    => ['ok', 'Đã gửi lại tin Zalo.'],
            'resend_fail'  => ['err', 'Gửi lại Zalo thất bại — xem chi tiết bản ghi.'],
            'notfound'     => ['err', 'Không tìm thấy bản ghi.'],
        ];
        $fm = $flash_map[$flash] ?? null;
        if ($fm) :
            ?>
            <div class="tgs-au-flash tgs-au-flash-<?php echo esc_attr($fm[0]); ?>"><?php echo esc_html($fm[1]); ?></div>
        <?php endif; ?>
    <?php endif; ?>

    <?php if ($can_manage) :
        $zalo_on = (int) get_site_option('tgs_audit_zalo_enabled', 1) === 1;
        ?>
        <details class="tgs-au-card tgs-au-settings"<?php echo $flash === 'saved' ? ' open' : ''; ?>>
            <summary class="tgs-au-card-head">
                <i class="bx bx-cog"></i> Cấu hình Zalo &amp; nhật ký
                <span class="tgs-au-card-sub">chỉ quản trị mạng</span>
                <span class="tgs-au-chevron"><i class="bx bx-chevron-down"></i></span>
            </summary>
            <form method="post" action="<?php echo esc_url($post_url); ?>" class="tgs-au-settings-form">
                <input type="hidden" name="action" value="tgs_audit_save_settings">
                <?php wp_nonce_field(TGS_Audit_Admin::NONCE_SETTINGS); ?>

                <label class="tgs-au-toggle-row <?php echo $zalo_on ? 'is-on' : 'is-off'; ?>">
                    <input type="checkbox" name="zalo_enabled" value="1" <?php checked($zalo_on); ?>>
                    <span class="tgs-au-toggle-ui" aria-hidden="true"></span>
                    <span class="tgs-au-toggle-text">
                        <b>Tự động gửi tin Zalo khi có thao tác nhạy cảm</b>
                        <small>Tắt thì hệ thống vẫn lưu nhật ký đầy đủ, chỉ không gửi tin Zalo.</small>
                    </span>
                </label>

                <div class="tgs-au-grid">
                    <label class="col-2">Đường dẫn API gửi Zalo (Smax)
                        <small>Nơi hệ thống gửi tin sang để Zalo bắn cho nhóm. Lấy trong phần trình kích hoạt (trigger) của Smax.</small>
                        <input type="text" name="zalo_url" value="<?php echo esc_attr(get_site_option('tgs_audit_zalo_url', TGS_Audit_Zalo::DEFAULT_URL)); ?>">
                    </label>
                    <label>Mã người / nhóm nhận trên Zalo
                        <small>Trường <code>customer.id</code> trong tài liệu Smax</small>
                        <input type="text" name="zalo_customer_id" value="<?php echo esc_attr(get_site_option('tgs_audit_zalo_customer_id', TGS_Audit_Zalo::DEFAULT_CUSTOMER_ID)); ?>">
                    </label>
                    <label>Mã trang Zalo OA
                        <small>Trường <code>customer.page_id</code> trong tài liệu Smax</small>
                        <input type="text" name="zalo_page_id" value="<?php echo esc_attr(get_site_option('tgs_audit_zalo_page_id', TGS_Audit_Zalo::DEFAULT_PAGE_ID)); ?>">
                    </label>
                    <label class="col-2">Mã xác thực Smax (bắt buộc)
                        <small>Dán nguyên giá trị header <code>Authorization</code> mà Postman đang dùng (mở request trong Postman → tab <b>Headers</b> hoặc <b>Authorization</b>, hoặc xem ở cấp Collection). Kèm cả tiền tố <code>Bearer </code> nếu có. Thiếu ô này Smax trả lỗi <b>403 “Token empty”</b>.</small>
                        <input type="text" name="zalo_auth" autocomplete="off" placeholder="Bearer eyJhbGciOi…" value="<?php echo esc_attr(get_site_option('tgs_audit_zalo_auth', '')); ?>">
                    </label>
                    <label class="col-2">Địa chỉ trang quản trị
                        <small>Dùng để chèn nút “Xem nhật ký” vào tin Zalo. Để trống = hệ thống tự lấy địa chỉ site chính.</small>
                        <input type="text" name="admin_url" placeholder="https://quantri.thegioisua.com/wp-admin/admin.php" value="<?php echo esc_attr(get_site_option('tgs_audit_admin_url', '')); ?>">
                    </label>
                    <label>Số ngày lưu nhật ký
                        <small>Bản ghi cũ hơn số ngày này sẽ tự động xoá.</small>
                        <input type="number" name="retention_days" min="7" max="3650" value="<?php echo esc_attr(get_site_option('tgs_audit_retention_days', 365)); ?>">
                    </label>
                </div>

                <div class="tgs-au-evt">
                    <div class="tgs-au-evt-title">Những thao tác sẽ được gửi tin Zalo</div>
                    <div class="tgs-au-evt-hint">Tích vào thao tác cần cảnh báo. Thao tác không tích vẫn được lưu nhật ký, chỉ không gửi Zalo.</div>
                    <?php
                    $on_actions = TGS_Audit_Log::zalo_actions();
                    foreach (TGS_Audit_Log::LABELS['action'] as $key => $lbl) :
                        [$c_key, $a_key] = array_pad(explode('/', $key, 2), 2, '');
                        ?>
                        <label class="tgs-au-check tgs-au-evt-item">
                            <input type="checkbox" name="zalo_actions[]" value="<?php echo esc_attr($key); ?>" <?php checked(in_array($key, $on_actions, true)); ?>>
                            <span>
                                <b><?php echo esc_html($lbl); ?></b>
                                <code><?php echo esc_html($key); ?></code>
                            </span>
                        </label>
                    <?php endforeach; ?>
                </div>

                <div class="tgs-au-card-foot">
                    <button type="submit" class="button button-primary">Lưu cấu hình</button>
                </div>
            </form>
        </details>
    <?php endif; ?>

    <form method="get" class="tgs-au-card tgs-au-filters">
        <div class="tgs-au-card-head"><i class="bx bx-filter-alt"></i> Bộ lọc</div>
        <div class="tgs-au-card-body tgs-au-filter-row">
        <?php // Giữ tham số route của hệ quản trị để bấm "Lọc" không văng ra ngoài ?>
        <input type="hidden" name="page" value="tgs-shop-management">
        <input type="hidden" name="view" value="<?php echo esc_attr(TGS_Audit_Admin::VIEW); ?>">
        <label>Ngày
            <input type="date" name="date" value="<?php echo esc_attr($f_date); ?>">
        </label>
        <label>Nghiệp vụ
            <select name="action_key">
                <option value="">— Tất cả —</option>
                <?php foreach (TGS_Audit_Log::LABELS['action'] as $key => $lbl) :
                    [$c, $a] = array_pad(explode('/', $key, 2), 2, '');
                    ?>
                    <option value="<?php echo esc_attr($a); ?>" <?php selected($f_act, $a); ?>><?php echo esc_html($lbl); ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Shop
            <select name="blog">
                <option value="0">— Tất cả —</option>
                <?php foreach ($shop_opts as $bid => $lbl) : ?>
                    <option value="<?php echo (int) $bid; ?>" <?php selected($f_blog, $bid); ?>><?php echo esc_html($lbl); ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Người thao tác
            <input type="text" name="actor" value="<?php echo esc_attr($f_actor); ?>" placeholder="tên / tài khoản">
        </label>
        <label>Từ khoá
            <input type="text" name="q" value="<?php echo esc_attr($f_q); ?>" placeholder="mã chứng từ, nội dung…">
        </label>
        <label class="tgs-au-check">
            <input type="checkbox" name="sev" value="1" <?php checked($f_sev, 'sensitive'); ?>> Chỉ việc nhạy cảm
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
                $links[] = '<a href="' . esc_url(remove_query_arg('entry', add_query_arg(['date' => $d]))) . '">' . esc_html(date_i18n('d/m', strtotime($d))) . '</a>';
            }
            echo implode(' · ', $links);
            ?>
        <?php endif; ?>
    </p>

    <?php if ($linked) : ?>
        <div class="tgs-au-linked">
            <div class="tgs-au-linked-head">
                <i class="bx bx-link-alt"></i> Bản ghi được liên kết —
                <?php echo esc_html(date_i18n('H:i d/m/Y', (int) ($linked['unix'] ?? strtotime($linked['ts'] ?? 'now')))); ?>
                · <?php echo esc_html($linked['action_label'] ?? ''); ?>
                · Shop <?php echo esc_html($linked['site']['code'] ?? '—'); ?>
                · <?php echo esc_html($linked['target']['code'] ?? ''); ?>
                <a class="tgs-au-jump" href="<?php echo esc_url(add_query_arg(['date' => substr($linked['ts'] ?? $f_date, 0, 10), 'entry' => $linked['id']])); ?>">mở theo ngày →</a>
            </div>
            <?php $render_detail($linked); ?>
            <?php if ($can_manage) : ?>
                <form method="post" action="<?php echo esc_url($post_url); ?>" class="tgs-au-resend">
                    <input type="hidden" name="action" value="tgs_audit_resend_zalo">
                    <input type="hidden" name="entry_id" value="<?php echo esc_attr($linked['id']); ?>">
                    <?php wp_nonce_field(TGS_Audit_Admin::NONCE_RESEND); ?>
                    <button type="submit" class="button"><i class="bx bx-send"></i> Gửi lại Zalo</button>
                    <span class="text-muted"><?php echo !empty($linked['zalo']['sent']) ? 'Đã gửi Zalo' . (!empty($linked['zalo']['msg_id']) ? ' (' . esc_html($linked['zalo']['msg_id']) . ')' : '') : 'Chưa gửi Zalo'; ?></span>
                </form>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <div class="tgs-au-card tgs-au-table-card">
        <div class="tgs-au-card-head"><i class="bx bx-list-ul"></i> Danh sách thao tác</div>
    <div class="tgs-au-table-wrap">
        <table class="tgs-au-table widefat">
            <thead>
                <tr>
                    <th style="width:32px"></th>
                    <th>Thời gian</th>
                    <th>Nghiệp vụ</th>
                    <th>Shop</th>
                    <th>Chứng từ</th>
                    <th>Người thao tác</th>
                    <th>Tóm tắt thay đổi</th>
                    <th>Zalo</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$logs) : ?>
                <tr><td colspan="8" class="tgs-au-empty">Không có bản ghi nào khớp bộ lọc.</td></tr>
            <?php endif; ?>
            <?php foreach ($logs as $e) :
                $eid = (string) ($e['id'] ?? '');
                $is_open = ($eid !== '' && $eid === $open_id);
                $sev = ($e['severity'] ?? 'info') === 'sensitive';
                $zsent = !empty($e['zalo']['sent']);
                $zerr  = !empty($e['zalo']['error']);
                ?>
                <tr class="tgs-au-row<?php echo $sev ? ' tgs-au-row-sensitive' : ''; ?>" data-entry="<?php echo esc_attr($eid); ?>">
                    <td class="tgs-au-toggle"><i class="bx <?php echo $is_open ? 'bx-chevron-down' : 'bx-chevron-right'; ?>"></i></td>
                    <td class="tgs-au-time"><?php echo esc_html(date_i18n('H:i:s', (int) ($e['unix'] ?? strtotime($e['ts'] ?? 'now')))); ?></td>
                    <td>
                        <?php echo esc_html($e['action_label'] ?? ($e['action'] ?? '')); ?>
                        <?php if ($sev) : ?><span class="tgs-au-dot" title="Nhạy cảm"></span><?php endif; ?>
                    </td>
                    <td><?php echo esc_html(trim(($e['site']['code'] ?? '') . ' ' . ($e['site']['name'] ?? ''))) ?: '—'; ?></td>
                    <td class="tgs-au-doc"><?php echo esc_html($e['target']['code'] ?? '—'); ?></td>
                    <td><?php echo esc_html($e['actor']['name'] ?? ($e['actor']['login'] ?? '—')); ?></td>
                    <td class="tgs-au-sum"><?php echo esc_html($e['summary'] ?? ''); ?></td>
                    <td>
                        <?php if ($zsent) : ?>
                            <span class="tgs-au-z tgs-au-z-ok" title="<?php echo esc_attr($e['zalo']['at'] ?? ''); ?>">✔ Đã gửi</span>
                        <?php elseif ($zerr) : ?>
                            <span class="tgs-au-z tgs-au-z-err" title="<?php echo esc_attr($e['zalo']['error']); ?>">✖ Lỗi</span>
                        <?php elseif ($sev) : ?>
                            <span class="tgs-au-z tgs-au-z-wait">… chờ</span>
                        <?php else : ?>
                            <span class="tgs-au-z">—</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr class="tgs-au-detail<?php echo $is_open ? ' is-open' : ''; ?>" data-detail="<?php echo esc_attr($eid); ?>">
                    <td colspan="8">
                        <?php $render_detail($e); ?>
                        <?php if ($can_manage) : ?>
                            <form method="post" action="<?php echo esc_url($post_url); ?>" class="tgs-au-resend">
                                <input type="hidden" name="action" value="tgs_audit_resend_zalo">
                                <input type="hidden" name="entry_id" value="<?php echo esc_attr($eid); ?>">
                                <?php wp_nonce_field(TGS_Audit_Admin::NONCE_RESEND); ?>
                                <button type="submit" class="button"><i class="bx bx-send"></i> Gửi lại Zalo</button>
                                <?php if ($zerr) : ?><span class="tgs-au-warn"><?php echo esc_html($e['zalo']['error']); ?></span><?php endif; ?>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    </div>
</div>
