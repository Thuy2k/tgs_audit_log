<?php
/**
 * Móc nhật ký cho "GHI PHIẾU — THU TIỀN SAU" (bán nợ) ở POS.
 *
 * Khi thu ngân bấm nút "Ghi (thu sau)": đơn vẫn tạo + đẩy HTsoft như thường,
 * NHƯNG KHÔNG sinh phiếu thu (coi như bán nợ), thu tiền sau qua Lịch sử thanh toán.
 * Business (tgs_pos) phát do_action('tgs_pos_credit_sale_written', $ctx); ở đây ghi
 * log + bắn Zalo cảnh báo "shop vừa ghi phiếu, cần xử lý (thu tiền) lại sau".
 *
 * @package tgs_audit_log
 */

if (!defined('ABSPATH')) {
    exit;
}

add_action('tgs_pos_credit_sale_written', static function ($ctx) {
    if (!class_exists('TGS_Audit_Log') || !is_array($ctx)) {
        return;
    }

    $blog_id = (int) ($ctx['blog_id'] ?? get_current_blog_id());
    $shop    = function_exists('tgs_audit_shop_meta')
        ? tgs_audit_shop_meta($blog_id)
        : ['code' => '', 'name' => ''];

    $sale_code = trim((string) ($ctx['sale_code'] ?? ''));
    $total     = (float) ($ctx['total'] ?? 0);
    $cust      = trim((string) ($ctx['customer_name'] ?? ''));

    $summary = '⚠ GHI PHIẾU BÁN NỢ (thu tiền sau)'
        . ($shop['code'] !== '' ? ' — shop ' . $shop['code'] : '')
        . ($sale_code !== '' ? ' — phiếu ' . $sale_code : '')
        . ' — tổng ' . number_format_i18n($total)
        . ($cust !== '' ? ' — KH: ' . $cust : '')
        . '. Chưa sinh phiếu thu, CẦN vào Lịch sử thanh toán thu tiền của khách sau.';

    TGS_Audit_Log::record([
        'channel'      => 'pos_order',
        'action'       => 'credit_sale_written',
        'action_label' => 'Ghi phiếu bán nợ — thu tiền sau (không sinh phiếu thu)',
        'severity'     => 'sensitive',
        'admin_blog'   => (int) ($ctx['admin_blog'] ?? 0),
        'site' => [
            'blog_id' => $blog_id,
            'code'    => $shop['code'],
            'name'    => $shop['name'],
        ],
        'target' => [
            'type' => 'phieu_ban',
            'id'   => (int) ($ctx['sale_ledger_id'] ?? 0),
            'code' => $sale_code,
            'shop' => $shop['code'],
        ],
        'summary' => $summary,
        'changes' => [
            'total'         => $total,
            'customer_name' => $cust,
            'no_receipt'    => true,
        ],
    ]);
}, 10, 1);
