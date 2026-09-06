<?php

/**
 * Màn "Nhật ký chờ kế toán duyệt" — quà tặng ngoài thêm thủ công ở giỏ hàng
 * POS (tgs_pos). Gộp vào ĐÚNG khối "Nhật ký thao tác" mà TGS_Audit_Admin đã
 * tạo trong menu Báo cáo, thêm 1 mục nữa vào section 'tgs-audit' thay vì dựng
 * heading riêng.
 *
 * Nút "Duyệt" gọi sang tgs_pos (site của CHÍNH shop đó) qua
 * switch_to_blog() + TGS_POS_Accountant_Gate::approve() (guard
 * class_exists — plugin này không PHỤ THUỘC cứng tgs_pos, chỉ tận dụng khi
 * có). Xem tgs_pos/includes/class-tgs-pos-accountant-gate.php.
 *
 * @package tgs_audit_log
 */

if (!defined('ABSPATH')) {
    exit;
}

class TGS_Audit_Pos_Gift_Admin
{
    const VIEW = 'tgs-audit-pos-gift';
    const NONCE = 'tgs_audit_pos_gift';

    /**
     * Cấu hình "TỰ ĐỘNG DUYỆT" quà tặng ngoài — site_option CẢ MẠNG giữ map
     * { blog_id => 1 }. Đọc được từ mọi site (kể cả site shop lúc "Chốt gửi kế
     * toán duyệt") mà không cần switch_to_blog. Chỉ những blog_id có trong map
     * mới tự duyệt — KHÔNG áp toàn bộ theo mặc định.
     */
    const AUTO_OPTION = 'tgs_audit_pos_gift_auto_blogs';

    /*
     * Quyền bấm "Duyệt" — TẠM để mức 'read' (đăng nhập vào quản trị là duyệt
     * được), giống hệt tinh thần TGS_BCTK_CAPABILITY trong
     * tgs-bc-tk/tgs-bc-tk.php: "chưa phân quyền vội — giai đoạn phát triển,
     * khi nào chốt nghiệp vụ thì siết lại ở MỘT chỗ duy nhất: hằng số này".
     * Đây là lựa chọn CÓ CHỦ Ý của người dùng (không phải bỏ sót) — xem
     * memory audit-log-plugin / cuộc hội thoại lúc thiết kế tính năng.
     */
    const APPROVE_CAP = 'read';

    public static function boot()
    {
        add_filter('tgs_shop_dashboard_routes', [__CLASS__, 'register_route']);
        // Priority 11: chạy SAU TGS_Audit_Admin::register_nav (10) để section
        // 'tgs-audit' đã có mặt trong $nav lúc mình chèn thêm mục vào.
        add_filter('tgs_shop_workflow_nav', [__CLASS__, 'register_nav'], 11, 2);
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue']);
        add_action('wp_ajax_tgs_audit_pos_gift_approve', [__CLASS__, 'ajax_approve']);
        add_action('wp_ajax_tgs_audit_pos_gift_reject', [__CLASS__, 'ajax_reject']);
        add_action('wp_ajax_tgs_audit_pos_gift_auto_get', [__CLASS__, 'ajax_auto_get']);
        add_action('wp_ajax_tgs_audit_pos_gift_auto_save', [__CLASS__, 'ajax_auto_save']);
        add_action('wp_ajax_tgs_audit_pos_gift_auto_bulk', [__CLASS__, 'ajax_auto_bulk']);
    }

    /* ── Cấu hình "tự động duyệt" theo shop ──────────────────────────────── */

    /** @return array<int,int>  map { blog_id => 1 } */
    public static function auto_map()
    {
        $m = get_site_option(self::AUTO_OPTION, []);
        if (!is_array($m)) {
            return [];
        }
        $out = [];
        foreach ($m as $bid => $on) {
            $bid = (int) $bid;
            if ($bid > 0 && !empty($on)) {
                $out[$bid] = 1;
            }
        }
        return $out;
    }

    /** Shop này có đang bật tự động duyệt không (đọc từ mọi site) */
    public static function is_auto_approve($blog_id)
    {
        $blog_id = (int) $blog_id;
        if ($blog_id <= 0) {
            return false;
        }
        $m = self::auto_map();
        return !empty($m[$blog_id]);
    }

    public static function set_auto_approve($blog_id, $on)
    {
        $blog_id = (int) $blog_id;
        if ($blog_id <= 0) {
            return false;
        }
        $m = self::auto_map();
        if ($on) {
            $m[$blog_id] = 1;
        } else {
            unset($m[$blog_id]);
        }
        return update_site_option(self::AUTO_OPTION, $m);
    }

    /**
     * Danh sách site shop (đọc thẳng wp_blogs — không switch_to_blog).
     * @return array<int,array{blog_id:int,code:string,name:string}>
     */
    public static function all_shop_sites()
    {
        global $wpdb;
        $rows = $wpdb->get_results(
            "SELECT blog_id, tgs_site_code FROM {$wpdb->base_prefix}blogs
              WHERE deleted = 0 AND spam = 0 AND archived = 0
              ORDER BY blog_id ASC",
            ARRAY_A
        ) ?: [];
        $out = [];
        foreach ($rows as $r) {
            $bid = (int) $r['blog_id'];
            if ($bid <= 0) {
                continue;
            }
            $out[] = [
                'blog_id' => $bid,
                'code'    => (string) ($r['tgs_site_code'] ?? ''),
                'name'    => (string) get_blog_option($bid, 'blogname', ''),
            ];
        }
        return $out;
    }

    public static function ajax_auto_get()
    {
        check_ajax_referer(self::NONCE, 'nonce');
        if (!self::can_approve()) {
            wp_send_json_error(['message' => 'Không có quyền.'], 403);
        }
        $map = self::auto_map();
        $sites = array_map(static function ($s) use ($map) {
            $s['auto'] = !empty($map[$s['blog_id']]);
            return $s;
        }, self::all_shop_sites());
        $total = count($sites);
        $on = count(array_filter($sites, static function ($s) {
            return !empty($s['auto']);
        }));
        wp_send_json_success([
            'sites'   => $sites,
            'on'      => $on,
            'total'   => $total,
            'all_on'  => $total > 0 && $on === $total,
            'all_off' => $on === 0,
        ]);
    }

    public static function ajax_auto_save()
    {
        check_ajax_referer(self::NONCE, 'nonce');
        if (!self::can_approve()) {
            wp_send_json_error(['message' => 'Không có quyền.'], 403);
        }
        $blog_id = (int) ($_POST['blog_id'] ?? 0);
        $on = !empty($_POST['auto']) && $_POST['auto'] !== 'false' && $_POST['auto'] !== '0';
        if ($blog_id <= 0) {
            wp_send_json_error(['message' => 'Thiếu shop.']);
        }
        self::set_auto_approve($blog_id, $on);
        wp_send_json_success([
            'blog_id' => $blog_id,
            'auto'    => $on,
            'message' => $on
                ? 'Đã BẬT tự động duyệt cho shop này.'
                : 'Đã TẮT tự động duyệt cho shop này.',
        ]);
    }

    public static function ajax_auto_bulk()
    {
        check_ajax_referer(self::NONCE, 'nonce');
        if (!self::can_approve()) {
            wp_send_json_error(['message' => 'Không có quyền.'], 403);
        }
        $mode = sanitize_key((string) wp_unslash($_POST['mode'] ?? ''));
        if ($mode === 'all_off') {
            update_site_option(self::AUTO_OPTION, []);
            wp_send_json_success(['message' => 'Đã TẮT tự động duyệt cho TẤT CẢ shop.']);
        }
        if ($mode === 'all_on') {
            $m = [];
            foreach (self::all_shop_sites() as $s) {
                $m[$s['blog_id']] = 1;
            }
            update_site_option(self::AUTO_OPTION, $m);
            wp_send_json_success([
                'message' => 'Đã BẬT tự động duyệt cho TẤT CẢ ' . count($m) . ' shop.',
            ]);
        }
        wp_send_json_error(['message' => 'Chế độ không hợp lệ.']);
    }

    public static function register_route($routes)
    {
        $routes[self::VIEW] = [
            'Nhật ký chờ kế toán duyệt',
            TGS_AUDIT_LOG_DIR . 'templates/pos-gift-review-page.php',
        ];
        return $routes;
    }

    public static function register_nav($nav, $current_view = '')
    {
        if (!isset($nav['reports']['sections']) || !is_array($nav['reports']['sections'])) {
            return $nav;
        }

        $item = [
            'view'  => self::VIEW,
            'label' => 'Nhật ký chờ kế toán duyệt',
            'icon'  => 'bx bx-gift',
        ];

        foreach ($nav['reports']['sections'] as &$section) {
            if (($section['key'] ?? '') === 'tgs-audit') {
                $section['items'][] = $item;
                return $nav;
            }
        }
        unset($section);

        // Không thấy section 'tgs-audit' (TGS_Audit_Admin có thể đang tắt) —
        // tự tạo riêng để chức năng vẫn dùng được, không mất trắng.
        $nav['reports']['sections'][] = [
            'key'     => 'tgs-audit-pos-gift-fallback',
            'heading' => 'Nhật ký thao tác',
            'icon'    => 'bx bx-history',
            'items'   => [$item],
        ];

        return $nav;
    }

    public static function enqueue($hook)
    {
        if ($hook !== 'toplevel_page_tgs-shop-management') {
            return;
        }
        $view = isset($_GET['view']) ? sanitize_text_field(wp_unslash($_GET['view'])) : '';
        if ($view !== self::VIEW) {
            return;
        }

        // Tái dùng khung thẻ/bảng của trang nhật ký chính cho đồng bộ giao diện.
        wp_enqueue_style(
            'tgs-audit-log',
            TGS_AUDIT_LOG_URL . 'assets/audit-log.css',
            [],
            TGS_AUDIT_LOG_VERSION . '.' . @filemtime(TGS_AUDIT_LOG_DIR . 'assets/audit-log.css')
        );
        wp_enqueue_style(
            'tgs-pos-gift-modal',
            TGS_AUDIT_LOG_URL . 'assets/pos-gift-modal.css',
            ['tgs-audit-log'],
            TGS_AUDIT_LOG_VERSION . '.' . @filemtime(TGS_AUDIT_LOG_DIR . 'assets/pos-gift-modal.css')
        );
        wp_enqueue_script(
            'tgs-pos-gift-modal',
            TGS_AUDIT_LOG_URL . 'assets/pos-gift-modal.js',
            ['jquery'],
            TGS_AUDIT_LOG_VERSION . '.' . @filemtime(TGS_AUDIT_LOG_DIR . 'assets/pos-gift-modal.js'),
            true
        );
        wp_localize_script('tgs-pos-gift-modal', 'tgsPosGiftAdmin', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce(self::NONCE),
            'canApprove' => self::can_approve(),
        ]);
    }

    public static function can_approve()
    {
        return current_user_can(self::APPROVE_CAP);
    }

    /* ── AJAX Duyệt / Từ chối ─────────────────────────────────────────────── */

    private static function load_target_entry()
    {
        $entry_id = sanitize_text_field((string) wp_unslash($_POST['entry_id'] ?? ''));
        if ($entry_id === '') {
            wp_send_json_error(['message' => 'Thiếu mã bản ghi.']);
        }
        $entry = TGS_Audit_Store::find_by_id($entry_id);
        if (!$entry) {
            wp_send_json_error(['message' => 'Không tìm thấy bản ghi.'], 404);
        }
        $blog_id   = (int) ($entry['site']['blog_id'] ?? 0);
        $sale_code = (string) ($entry['target']['code'] ?? '');
        if ($blog_id <= 0 || $sale_code === '') {
            wp_send_json_error(['message' => 'Bản ghi thiếu blog_id/mã phiếu.']);
        }
        return [$entry_id, $entry, $blog_id, $sale_code];
    }

    public static function ajax_approve()
    {
        check_ajax_referer(self::NONCE, 'nonce');
        if (!self::can_approve()) {
            wp_send_json_error(['message' => 'Không có quyền duyệt.'], 403);
        }

        [$entry_id, $entry, $blog_id, $sale_code] = self::load_target_entry();

        $u = wp_get_current_user();
        $approver = ['user_id' => (int) $u->ID, 'name' => (string) ($u->display_name ?: $u->user_login)];

        $switched = false;
        if (get_current_blog_id() !== $blog_id && function_exists('switch_to_blog')) {
            switch_to_blog($blog_id);
            $switched = true;
        }
        $ok = class_exists('TGS_POS_Accountant_Gate')
            ? TGS_POS_Accountant_Gate::approve($sale_code, $approver)
            : false;
        if ($switched) {
            restore_current_blog();
        }

        if (!$ok) {
            wp_send_json_error(['message' => 'Không duyệt được — thiếu TGS_POS_Accountant_Gate trên site shop (tgs_pos chưa cập nhật?).'], 500);
        }

        $changes = is_array($entry['changes'] ?? null) ? $entry['changes'] : [];
        $changes['approval'] = [
            'status'  => 'approved',
            'by'      => $approver['name'],
            'user_id' => $approver['user_id'],
            'at'      => current_time('mysql'),
        ];
        TGS_Audit_Store::patch($entry_id, ['changes' => $changes]);

        self::notify_shop_zalo($entry, 'approved', $approver['name']);

        wp_send_json_success(['status' => 'approved', 'by' => $approver['name']]);
    }

    public static function ajax_reject()
    {
        check_ajax_referer(self::NONCE, 'nonce');
        if (!self::can_approve()) {
            wp_send_json_error(['message' => 'Không có quyền.'], 403);
        }

        [$entry_id, $entry, $blog_id, $sale_code] = self::load_target_entry();
        $reason = sanitize_text_field((string) wp_unslash($_POST['reason'] ?? ''));

        $u = wp_get_current_user();
        $rejecter = ['user_id' => (int) $u->ID, 'name' => (string) ($u->display_name ?: $u->user_login)];

        $switched = false;
        if (get_current_blog_id() !== $blog_id && function_exists('switch_to_blog')) {
            switch_to_blog($blog_id);
            $switched = true;
        }
        $ok = class_exists('TGS_POS_Accountant_Gate')
            ? TGS_POS_Accountant_Gate::reject($sale_code, $rejecter, $reason)
            : false;
        if ($switched) {
            restore_current_blog();
        }

        if (!$ok) {
            wp_send_json_error(['message' => 'Không ghi được — thiếu TGS_POS_Accountant_Gate trên site shop.'], 500);
        }

        $changes = is_array($entry['changes'] ?? null) ? $entry['changes'] : [];
        $changes['approval'] = [
            'status'  => 'rejected',
            'by'      => $rejecter['name'],
            'user_id' => $rejecter['user_id'],
            'at'      => current_time('mysql'),
            'reason'  => $reason,
        ];
        TGS_Audit_Store::patch($entry_id, ['changes' => $changes]);

        self::notify_shop_zalo($entry, 'rejected', $rejecter['name'], $reason);

        wp_send_json_success(['status' => 'rejected', 'by' => $rejecter['name']]);
    }

    /**
     * Báo ngược lại nhóm Zalo là kế toán đã Duyệt/Từ chối — cùng nhóm đã nhận
     * tin "nhờ duyệt" ban đầu (hệ hiện chỉ có 1 đích Zalo cấu hình chung, nên
     * "tag tên shop" ở đây là nói RÕ TÊN SHOP trong nội dung tin, không phải
     * đổi đích gửi). Gửi ĐỒNG BỘ (giống handle_resend_zalo) vì đây là hành
     * động rời rạc do người dùng bấm, không nằm trên đường "ra đơn" cần
     * nhanh — không cần né qua hook shutdown như lúc ghi log gốc.
     *
     * Chỉ theo công tắc tổng tgs_audit_zalo_enabled — KHÔNG xét danh sách
     * "nghiệp vụ được bắn Zalo" (đó là bộ lọc cho SỰ KIỆN GHI LOG mới, tin
     * duyệt/từ chối này là phản hồi ngay tại chỗ, không phải sự kiện log mới).
     */
    private static function notify_shop_zalo(array $entry, $status, $approver_name, $reason = '')
    {
        if (!class_exists('TGS_Audit_Zalo') || !get_site_option('tgs_audit_zalo_enabled', 1)) {
            return;
        }

        $shop = trim(
            (string) ($entry['site']['code'] ?? '')
            . (!empty($entry['site']['name']) ? ' (' . $entry['site']['name'] . ')' : '')
        );
        $code = (string) ($entry['target']['code'] ?? '');
        $submitter = (string) ($entry['actor']['name'] ?? ($entry['actor']['login'] ?? ''));
        $when = date_i18n('H:i d/m/Y');

        if ($status === 'approved') {
            $lines = [
                '✅ Đã DUYỆT quà tặng ngoài',
                'Shop ' . $shop . ' · Phiếu ' . $code,
                'Kế toán duyệt: ' . $approver_name . ' — ' . $when,
                'Người gửi: ' . $submitter,
                '👉 Nhân viên bấm lại Thanh toán ở đúng hoá đơn đó là được.',
            ];
        } else {
            $lines = [
                '❌ Đã TỪ CHỐI quà tặng ngoài',
                'Shop ' . $shop . ' · Phiếu ' . $code,
                'Kế toán từ chối: ' . $approver_name . ' — ' . $when,
                'Người gửi: ' . $submitter,
            ];
            $reason = trim((string) $reason);
            if ($reason !== '') {
                $lines[] = 'Lý do: ' . $reason;
            }
            $lines[] = '👉 Nhân viên đóng hoá đơn này, lên lại đơn khác.';
        }

        TGS_Audit_Zalo::send(implode("\n", $lines));
    }
}
