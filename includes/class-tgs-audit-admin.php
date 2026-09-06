<?php

/**
 * Nối trang nhật ký vào hệ quản trị (tgs_shop_management) + xử lý form cấu hình
 * và nút "Gửi lại Zalo".
 *
 * Bám đúng khuôn các plugin anh em (tgs-bc-tk, tgs-activities-admin): đăng ký
 * qua filter 'tgs_shop_dashboard_routes' + 'tgs_shop_workflow_nav', không sửa
 * thẳng menu của tgs_shop_management.
 *
 * @package tgs_audit_log
 */

if (!defined('ABSPATH')) {
    exit;
}

class TGS_Audit_Admin
{
    const VIEW = 'tgs-audit-log';
    const NONCE_SETTINGS = 'tgs_audit_settings';
    const NONCE_RESEND = 'tgs_audit_resend';

    public static function boot()
    {
        add_filter('tgs_shop_dashboard_routes', [__CLASS__, 'register_route']);
        add_filter('tgs_shop_workflow_nav', [__CLASS__, 'register_nav'], 10, 2);
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue']);

        add_action('admin_post_tgs_audit_save_settings', [__CLASS__, 'handle_save_settings']);
        add_action('admin_post_tgs_audit_resend_zalo', [__CLASS__, 'handle_resend_zalo']);
    }

    public static function register_route($routes)
    {
        $routes[self::VIEW] = [
            'Nhật ký thao tác (PM mới)',
            TGS_AUDIT_LOG_DIR . 'templates/audit-log-page.php',
        ];
        return $routes;
    }

    /**
     * Chèn vào menu "Báo cáo". Nếu chưa có khối reports thì bỏ qua — không tự
     * dựng menu mới.
     */
    public static function register_nav($nav, $current_view = '')
    {
        if (!isset($nav['reports']['sections']) || !is_array($nav['reports']['sections'])) {
            return $nav;
        }

        $nav['reports']['sections'][] = [
            'key'     => 'tgs-audit',
            'heading' => 'Nhật ký thao tác',
            'icon'    => 'bx bx-history',
            'items'   => [
                [
                    'view'  => self::VIEW,
                    'label' => 'Nhật ký thao tác (PM mới)',
                    'icon'  => 'bx bx-history',
                ],
            ],
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

        wp_enqueue_style(
            'tgs-audit-log',
            TGS_AUDIT_LOG_URL . 'assets/audit-log.css',
            [],
            TGS_AUDIT_LOG_VERSION . '.' . @filemtime(TGS_AUDIT_LOG_DIR . 'assets/audit-log.css')
        );
        wp_enqueue_script(
            'tgs-audit-log',
            TGS_AUDIT_LOG_URL . 'assets/audit-log.js',
            ['jquery'],
            TGS_AUDIT_LOG_VERSION . '.' . @filemtime(TGS_AUDIT_LOG_DIR . 'assets/audit-log.js'),
            true
        );
    }

    /* ── Form cấu hình (chỉ super admin mạng) ────────────────────────────── */

    public static function can_manage()
    {
        return is_multisite()
            ? current_user_can('manage_network_options')
            : current_user_can('manage_options');
    }

    public static function handle_save_settings()
    {
        if (!self::can_manage() || !check_admin_referer(self::NONCE_SETTINGS)) {
            wp_die('Không đủ quyền.');
        }

        $enabled = !empty($_POST['zalo_enabled']) ? 1 : 0;
        update_site_option('tgs_audit_zalo_enabled', $enabled);

        update_site_option(
            'tgs_audit_zalo_url',
            esc_url_raw(trim((string) wp_unslash($_POST['zalo_url'] ?? '')))
        );
        update_site_option(
            'tgs_audit_zalo_customer_id',
            sanitize_text_field(wp_unslash($_POST['zalo_customer_id'] ?? ''))
        );
        update_site_option(
            'tgs_audit_zalo_page_id',
            sanitize_text_field(wp_unslash($_POST['zalo_page_id'] ?? ''))
        );
        update_site_option(
            'tgs_audit_zalo_auth',
            sanitize_text_field(wp_unslash($_POST['zalo_auth'] ?? ''))
        );
        update_site_option(
            'tgs_audit_admin_url',
            esc_url_raw(trim((string) wp_unslash($_POST['admin_url'] ?? '')))
        );

        // Danh sách thao tác bắn Zalo — chỉ nhận đúng các mã nghiệp vụ đã khai
        // trong TGS_Audit_Log::LABELS (giao diện là ô tích, không cho gõ tự do).
        $picked = (isset($_POST['zalo_actions']) && is_array($_POST['zalo_actions']))
            ? array_map('sanitize_text_field', wp_unslash($_POST['zalo_actions']))
            : [];
        $known = array_keys(TGS_Audit_Log::LABELS['action']);
        $list = array_values(array_intersect($picked, $known));
        update_site_option('tgs_audit_zalo_actions', $list);

        $days = (int) ($_POST['retention_days'] ?? 365);
        update_site_option('tgs_audit_retention_days', max(7, min(3650, $days)));

        wp_safe_redirect(add_query_arg(
            ['tgs_audit_msg' => 'saved'],
            wp_get_referer() ?: admin_url()
        ));
        exit;
    }

    public static function handle_resend_zalo()
    {
        if (!self::can_manage() || !check_admin_referer(self::NONCE_RESEND)) {
            wp_die('Không đủ quyền.');
        }
        $id = sanitize_text_field(wp_unslash($_POST['entry_id'] ?? ''));
        $entry = $id !== '' ? TGS_Audit_Store::find_by_id($id) : null;

        $msg = 'notfound';
        if ($entry) {
            $res = TGS_Audit_Zalo::send(TGS_Audit_Zalo::build_message($entry));
            if (is_wp_error($res)) {
                TGS_Audit_Store::patch($id, ['zalo' => [
                    'sent'  => false,
                    'at'    => current_time('mysql'),
                    'error' => $res->get_error_message(),
                ]]);
                $msg = 'resend_fail';
            } else {
                TGS_Audit_Store::patch($id, ['zalo' => [
                    'sent'   => true,
                    'at'     => current_time('mysql'),
                    'msg_id' => (string) ($res['data']['msg_id'] ?? $res['msg_id'] ?? $res['id'] ?? ''),
                ]]);
                $msg = 'resend_ok';
            }
        }

        wp_safe_redirect(add_query_arg(
            ['tgs_audit_msg' => $msg, 'entry' => $id],
            wp_get_referer() ?: admin_url()
        ));
        exit;
    }
}
