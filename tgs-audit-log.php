<?php

/**
 * Plugin Name: TGS Audit Log — Nhật ký thao tác nghiệp vụ (BTsoft)
 * Description: Ghi nhật ký mọi thao tác thêm/sửa/xoá nghiệp vụ trên phần mềm mới (có so sánh trước↔sau), bắn Zalo tức thì qua Smax cho sự kiện nhạy cảm, kèm trang xem nhật ký tiếng Việt trong hệ quản trị.
 * Version: 1.0.0
 * Author: TGS
 * Network: true
 *
 * ── Nguyên tắc ─────────────────────────────────────────────────────────────
 *  1. KHÔNG thêm bảng/cột DB — log ghi ra file JSONL (hệ 650 site, xem
 *     tgs_shop_management/docs/huong-dan-thay-doi-database.md).
 *  2. Ghi log không được làm chậm/hỏng thao tác gốc — lỗi log nuốt im, Zalo
 *     gửi ở hook 'shutdown' sau khi response đã trả.
 *  3. Không sửa logic nghiệp vụ — chỉ chèn do_action(...) ở plugin nguồn và
 *     nghe hook ở đây. Gỡ plugin = hệ thống chạy y như cũ.
 *
 * Tài liệu: docs/mo-ta-he-thong-nhat-ky.md
 *
 * @package tgs_audit_log
 */

if (!defined('ABSPATH')) {
    exit;
}

define('TGS_AUDIT_LOG_VERSION', '1.0.0');
define('TGS_AUDIT_LOG_DIR', plugin_dir_path(__FILE__));
define('TGS_AUDIT_LOG_URL', plugin_dir_url(__FILE__));

require_once TGS_AUDIT_LOG_DIR . 'includes/class-tgs-audit-store.php';
require_once TGS_AUDIT_LOG_DIR . 'includes/class-tgs-audit-zalo.php';
require_once TGS_AUDIT_LOG_DIR . 'includes/class-tgs-audit-log.php';
require_once TGS_AUDIT_LOG_DIR . 'includes/class-tgs-audit-admin.php';
require_once TGS_AUDIT_LOG_DIR . 'includes/class-tgs-audit-pos-gift-admin.php';

/*
 * Integration nghe do_action của plugin nguồn. Mỗi nghiệp vụ một file, require
 * thêm ở đây khi mở rộng — xem mục 7 của tài liệu.
 */
require_once TGS_AUDIT_LOG_DIR . 'includes/integrations/integration-bctk-vat.php';
require_once TGS_AUDIT_LOG_DIR . 'includes/integrations/integration-pos-gift.php';
require_once TGS_AUDIT_LOG_DIR . 'includes/integrations/integration-book-close.php';   // Khóa sổ đồng bộ HTsoft
require_once TGS_AUDIT_LOG_DIR . 'includes/integrations/integration-pos-credit-sale.php'; // Ghi phiếu bán nợ (thu tiền sau)

add_action('plugins_loaded', static function () {
    TGS_Audit_Log::boot();
    TGS_Audit_Zalo::boot();
    TGS_Audit_Admin::boot();
    TGS_Audit_Pos_Gift_Admin::boot();
}, 1);

register_activation_hook(__FILE__, static function () {
    TGS_Audit_Store::ensure_dir();
    if (!wp_next_scheduled('tgs_audit_prune')) {
        wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', 'tgs_audit_prune');
    }
});

register_deactivation_hook(__FILE__, static function () {
    $ts = wp_next_scheduled('tgs_audit_prune');
    if ($ts) {
        wp_unschedule_event($ts, 'tgs_audit_prune');
    }
});
