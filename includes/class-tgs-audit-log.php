<?php

/**
 * LÕI nhật ký: record() dựng bản ghi chuẩn, gác điều kiện bắn Zalo, dựng link.
 *
 * @package tgs_audit_log
 */

if (!defined('ABSPATH')) {
    exit;
}

class TGS_Audit_Log
{
    /** Quyền tối thiểu để XEM trang nhật ký (khớp tinh thần TGS_BCTK_CAPABILITY) */
    const VIEW_CAP = 'read';

    /**
     * Nhãn tiếng Việt cho channel/action — trang xem hiển thị và bộ lọc lấy từ đây.
     * Mở rộng nghiệp vụ mới thì thêm vào đây (xem mục 7 tài liệu).
     */
    const LABELS = [
        'channel' => [
            'bctk_vat' => 'Quản lý hoá đơn VAT',
            'pos_gift' => 'Quà tặng ngoài chờ duyệt',
            'pos_order' => 'Đơn hàng POS',
            'htsoft_push' => 'Cấu hình đẩy HTsoft',
        ],
        'action' => [
            'pos_gift/gift_pending_approval' => 'Gửi quà tặng ngoài chờ kế toán duyệt',
            'bctk_vat/vat_save_lines' => 'Sửa dòng hàng phiếu xuất bán (VAT)',
            'bctk_vat/vat_save_note'  => 'Sửa ghi chú phiếu xuất bán (VAT)',
            'bctk_vat/vat_replace_invoice' => 'Lập hoá đơn thay thế qua Viettel',
            'pos_order/receipt_method_changed' => 'Sửa hình thức thanh toán phiếu thu',
            'pos_order/order_note_changed' => 'Sửa ghi chú đơn hàng (Lịch sử đơn hàng)',
            'pos_order/return_created' => 'Hoàn hàng (không đổi trả)',
            'htsoft_push/site_enabled' => 'BẬT đẩy dữ liệu lên HTsoft (site)',
            'htsoft_push/push_failed' => 'Không đẩy được phiếu lên HTsoft (đã lưu local mã BT)',
        ],
    ];

    /**
     * Channel nào có màn xem RIÊNG (khác trang nhật ký chung 'tgs-audit-log')
     * thì khai view slug ở đây — entry_url() tra bảng này để dựng đúng link.
     * 'pos_gift' → trang "Nhật ký chờ kế toán duyệt" (có nút Duyệt/Từ chối),
     * xem TGS_Audit_Pos_Gift_Admin::VIEW.
     */
    const CHANNEL_VIEWS = [
        'pos_gift' => 'tgs-audit-pos-gift',
    ];

    public static function boot()
    {
        add_action('tgs_audit_prune', [__CLASS__, 'cron_prune']);
    }

    public static function cron_prune()
    {
        TGS_Audit_Store::prune((int) get_site_option('tgs_audit_retention_days', 365));
    }

    /* ── Ghi một sự kiện ─────────────────────────────────────────────────── */

    /**
     * @param array $e {
     *   @type string $channel       (bắt buộc) vd 'bctk_vat'
     *   @type string $action        (bắt buộc) vd 'vat_save_lines'
     *   @type string $action_label  Nhãn tiếng Việt; rỗng thì tra LABELS rồi tới $action
     *   @type string $severity      'sensitive' | 'info' (mặc định 'info')
     *   @type array  $site          ['blog_id','code','name']
     *   @type array  $target        ['type','id','code','shop', ...]
     *   @type string $summary       Tóm tắt tiếng Việt (một dòng)
     *   @type array  $changes       Cấu trúc tự do (nên có grand_total.before/after, lines{...})
     *   @type string $zalo_text     Ghi đè nội dung Zalo; rỗng → tự dựng
     *   @type int    $admin_blog    Site đang chạy hệ quản trị, để dựng link
     * }
     * @return string|null  id bản ghi, hoặc null nếu thiếu channel/action
     */
    public static function record(array $e)
    {
        try {
            $channel = sanitize_key($e['channel'] ?? '');
            $action  = sanitize_key($e['action'] ?? '');
            if ($channel === '' || $action === '') {
                return null;
            }

            $blog_id = (int) ($e['site']['blog_id'] ?? get_current_blog_id());
            $u = wp_get_current_user();

            $id = sprintf(
                '%s-%d-%s',
                current_time('Ymd'),
                $blog_id,
                substr(bin2hex(random_bytes(5)), 0, 8)
            );

            $severity = ($e['severity'] ?? 'info') === 'sensitive' ? 'sensitive' : 'info';
            $label = (string) ($e['action_label'] ?? '');
            if ($label === '') {
                $label = self::LABELS['action'][$channel . '/' . $action] ?? $action;
            }

            $entry = [
                'id'           => $id,
                'ts'           => current_time('mysql'),
                'unix'         => (int) current_time('timestamp'),
                'channel'      => $channel,
                'action'       => $action,
                'action_label' => $label,
                'severity'     => $severity,
                'site' => [
                    'blog_id' => $blog_id,
                    'code'    => (string) ($e['site']['code'] ?? ''),
                    'name'    => (string) ($e['site']['name'] ?? ''),
                ],
                'actor' => [
                    'user_id' => (int) $u->ID,
                    'login'   => (string) $u->user_login,
                    'name'    => (string) ($u->display_name ?: $u->user_login),
                ],
                'target'  => self::arr($e['target'] ?? []),
                'summary' => (string) ($e['summary'] ?? ''),
                'changes' => self::arr($e['changes'] ?? []),
                'context' => [
                    'ip'  => self::ip(),
                    'ua'  => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 300),
                    'uri' => (string) ($_SERVER['REQUEST_URI'] ?? ''),
                ],
                'zalo'       => ['sent' => false],
                'admin_blog' => (int) ($e['admin_blog'] ?? 0),
            ];

            TGS_Audit_Store::append($entry);

            if ($severity === 'sensitive' && self::zalo_should_send($channel, $action)) {
                TGS_Audit_Zalo::enqueue($entry, (string) ($e['zalo_text'] ?? ''));
            }

            return $id;
        } catch (\Throwable $ex) {
            error_log('[TGS Audit] record lỗi: ' . $ex->getMessage());
            return null;
        }
    }

    /* ── Cấu hình / tiện ích ─────────────────────────────────────────────── */

    /** Danh sách "channel/action" được phép bắn Zalo (option cả mạng) */
    public static function zalo_actions()
    {
        $raw = get_site_option('tgs_audit_zalo_actions', self::default_zalo_actions());
        if (is_string($raw)) {
            $raw = array_filter(array_map('trim', preg_split('/[\r\n,]+/', $raw)));
        }
        return is_array($raw) ? array_values($raw) : self::default_zalo_actions();
    }

    /**
     * Mặc định cho site MỚI (chưa từng lưu panel Cấu hình). Site đã tự lưu
     * danh sách riêng (đã bấm "Lưu cấu hình" ít nhất 1 lần) sẽ KHÔNG tự nhận
     * action mới thêm vào đây — option đã lưu ghi đè default. Phải vào panel
     * tích thêm tay 1 lần (đã ghi trong docs/mo-ta-he-thong-nhat-ky.md).
     */
    private static function default_zalo_actions()
    {
        return [
            'bctk_vat/vat_save_lines',
            'bctk_vat/vat_save_note',
            'bctk_vat/vat_replace_invoice',
            'pos_gift/gift_pending_approval',
            'pos_order/receipt_method_changed',
            'pos_order/order_note_changed',
            'pos_order/return_created',
            'htsoft_push/push_failed',
        ];
    }

    public static function zalo_should_send($channel, $action)
    {
        if (!get_site_option('tgs_audit_zalo_enabled', 1)) {
            return false;
        }
        return in_array($channel . '/' . $action, self::zalo_actions(), true);
    }

    /**
     * URL mở thẳng một bản ghi trong hệ quản trị.
     * Ưu tiên option tgs_audit_admin_url; rỗng thì lấy admin.php của site chính
     * (hoặc $hint_blog nếu integration truyền vào — là site đang chạy hệ quản trị).
     */
    public static function entry_url($entry)
    {
        $id = is_array($entry) ? ($entry['id'] ?? '') : (string) $entry;
        $hint_blog = is_array($entry) ? (int) ($entry['admin_blog'] ?? 0) : 0;
        $channel = is_array($entry) ? (string) ($entry['channel'] ?? '') : '';

        $base = trim((string) get_site_option('tgs_audit_admin_url', ''));
        if ($base === '') {
            $blog = $hint_blog > 0 ? $hint_blog : (function_exists('get_main_site_id') ? get_main_site_id() : 1);
            $base = get_admin_url($blog, 'admin.php');
        }
        return add_query_arg(
            [
                'page'  => 'tgs-shop-management',
                'view'  => self::CHANNEL_VIEWS[$channel] ?? 'tgs-audit-log',
                'entry' => $id,
            ],
            $base
        );
    }

    public static function channel_label($channel)
    {
        return self::LABELS['channel'][$channel] ?? $channel;
    }

    public static function action_label($channel, $action)
    {
        return self::LABELS['action'][$channel . '/' . $action] ?? $action;
    }

    private static function ip()
    {
        foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $k) {
            if (!empty($_SERVER[$k])) {
                $ip = trim(explode(',', (string) $_SERVER[$k])[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }
        return '';
    }

    private static function arr($v)
    {
        return is_array($v) ? $v : [];
    }
}
