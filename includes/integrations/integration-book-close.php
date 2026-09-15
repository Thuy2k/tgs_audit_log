<?php
/**
 * Móc nhật ký cho KHÓA SỔ đồng bộ HTsoft (plugin tgs_shop_management).
 *
 * Nghe do_action('tgs_book_close_set', $ctx) mà TGS_Book_Close::handle_save phát ra
 * khi 1 website được đặt/đổi/hủy khóa sổ. Ghi log + bắn Zalo cảnh báo (sensitive).
 *
 * Không đụng logic khóa sổ. Plugin này tắt → do_action thành no-op.
 *
 * @package tgs_audit_log
 */

if (!defined('ABSPATH')) {
    exit;
}

add_action('tgs_book_close_set', static function ($ctx) {
    if (!class_exists('TGS_Audit_Log') || !is_array($ctx)) {
        return;
    }

    $blog_id = (int) ($ctx['blog_id'] ?? 0);
    $shop    = function_exists('tgs_audit_shop_meta')
        ? tgs_audit_shop_meta($blog_id)
        : ['code' => '', 'name' => ''];

    $cleared = !empty($ctx['cleared']);
    $old_at  = trim((string) ($ctx['old_at'] ?? ''));
    $new_at  = trim((string) ($ctx['new_at'] ?? ''));
    $note    = trim((string) ($ctx['note'] ?? ''));

    $fmt = static function ($mysql_dt) {
        $mysql_dt = trim((string) $mysql_dt);
        if ($mysql_dt === '') {
            return '';
        }
        $ts = strtotime($mysql_dt);
        return $ts ? date('H:i:s d/m/Y', $ts) : $mysql_dt;
    };

    if ($cleared) {
        $action  = 'clear';
        $summary = 'HỦY khóa sổ shop' . ($shop['code'] !== '' ? ' ' . $shop['code'] : '')
            . ($old_at !== '' ? ' (trước đó khóa đến ' . $fmt($old_at) . ')' : '')
            . '. Các phiếu cũ được thao tác / đẩy HTsoft trở lại.';
    } else {
        $action  = 'set';
        $summary = 'KHÓA SỔ shop' . ($shop['code'] !== '' ? ' ' . $shop['code'] : '')
            . ' đến ' . $fmt($new_at)
            . '. Phiếu tạo trước-hoặc-bằng mốc này KHÔNG được sửa / đẩy HTsoft / gửi thuế VAT nữa.'
            . ($note !== '' ? ' Ghi chú: ' . $note : '');
    }

    TGS_Audit_Log::record([
        'channel'      => 'book_close',
        'action'       => $action,
        'action_label' => $cleared ? 'Hủy khóa sổ đồng bộ HTsoft' : 'Khóa sổ đồng bộ HTsoft (đặt/đổi mốc)',
        'severity'     => 'sensitive',
        'admin_blog'   => (int) ($ctx['admin_blog'] ?? 0),
        'site' => [
            'blog_id' => $blog_id,
            'code'    => $shop['code'],
            'name'    => $shop['name'],
        ],
        'target' => [
            'type' => 'khoa_so',
            'code' => $shop['code'],
            'shop' => $shop['code'],
        ],
        'summary' => $summary,
        'changes' => [
            'book_close_at' => ['before' => $old_at, 'after' => $new_at],
            'note'          => $note,
            'actor'         => (string) ($ctx['actor'] ?? ''),
        ],
    ]);
}, 10, 1);
