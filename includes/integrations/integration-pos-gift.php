<?php

/**
 * Khép vòng truy vết cho "Quà tặng ngoài chờ kế toán duyệt": khi đơn thật
 * cuối cùng được tạo trên POS (tgs_pos), gắn thêm sale_ledger_id vào đúng
 * bản ghi JSONL đã gửi duyệt trước đó (khớp theo mã phiếu).
 *
 * Không bắt buộc cho luồng chính hoạt động — chỉ là tiện ích để kế toán biết
 * "đã duyệt → đã thật sự lên đơn nào". Nghe hook `tgs_sale_completed` mà
 * TGS_POS_Order_Handler::fire_sale_completed() đã phát sẵn (chạy TRÊN site
 * của chính shop đó) — không cần switch_to_blog vì kho JSONL của
 * TGS_Audit_Store nằm CỐ ĐỊNH dưới wp-content/uploads, không theo site.
 *
 * @package tgs_audit_log
 */

if (!defined('ABSPATH')) {
    exit;
}

add_action('tgs_sale_completed', static function ($data) {
    if (!class_exists('TGS_Audit_Store') || !is_array($data)) {
        return;
    }

    $sale_code = trim((string) ($data['sale_code'] ?? ''));
    if ($sale_code === '') {
        return;
    }

    $today = current_time('Y-m-d');
    $yesterday = gmdate('Y-m-d', strtotime($today . ' -1 day'));

    foreach ([$today, $yesterday] as $ymd) {
        $rows = TGS_Audit_Store::read_date($ymd, ['channel' => 'pos_gift']);
        foreach ($rows as $row) {
            if ((string) ($row['target']['code'] ?? '') !== $sale_code) {
                continue;
            }

            $changes = is_array($row['changes'] ?? null) ? $row['changes'] : [];
            $changes['linked_sale'] = [
                'sale_ledger_id' => (int) ($data['sale_ledger_id'] ?? 0),
                'blog_id'        => (int) ($data['blog_id'] ?? get_current_blog_id()),
                'linked_at'      => current_time('mysql'),
            ];
            TGS_Audit_Store::patch((string) $row['id'], ['changes' => $changes]);
            return;
        }
    }
}, 10, 1);
