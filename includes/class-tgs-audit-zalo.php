<?php

/**
 * Gửi Zalo qua trung gian Smax (hàm riêng, đúng request curl đã cung cấp).
 *
 * Gửi ở hook 'shutdown' — sau khi response đã trả cho trình duyệt — để nút
 * "Lưu" của kế toán không chậm đi vì gọi API ngoài.
 *
 * @package tgs_audit_log
 */

if (!defined('ABSPATH')) {
    exit;
}

class TGS_Audit_Zalo
{
    const DEFAULT_URL = 'https://api.smax.ai/public/bizs/the-gioi--sua/triggers/6a98f29db80215669e03d4b2';
    const DEFAULT_CUSTOMER_ID = 'zlw6350040536411401311';
    const DEFAULT_PAGE_ID = 'zlw643803197505260719';

    /** @var array<int,array{entry:array,text:string}> */
    private static $queue = [];

    public static function boot()
    {
        add_action('shutdown', [__CLASS__, 'flush'], 99);
    }

    /** Xếp một bản ghi vào hàng đợi gửi cuối request */
    public static function enqueue(array $entry, $text_override = '')
    {
        self::$queue[] = ['entry' => $entry, 'text' => (string) $text_override];
    }

    /** Chạy ở 'shutdown': đẩy response xong rồi mới gọi Smax */
    public static function flush()
    {
        if (empty(self::$queue)) {
            return;
        }
        $jobs = self::$queue;
        self::$queue = [];

        // Trả nốt response cho khách rồi mới đi gọi API ngoài
        if (function_exists('fastcgi_finish_request')) {
            @fastcgi_finish_request();
        }

        foreach ($jobs as $job) {
            $entry = $job['entry'];
            $text  = $job['text'] !== '' ? $job['text'] : self::build_message($entry);
            $res   = self::send($text);

            $zalo = is_wp_error($res)
                ? [
                    'sent'  => false,
                    'at'    => current_time('mysql'),
                    'error' => $res->get_error_message(),
                ]
                : [
                    'sent'   => true,
                    'at'     => current_time('mysql'),
                    'msg_id' => (string) (
                        $res['data']['msg_id']
                        ?? $res['msg_id']
                        ?? $res['id']
                        ?? ''
                    ),
                ];

            if (!empty($entry['id'])) {
                TGS_Audit_Store::patch($entry['id'], ['zalo' => $zalo]);
            }
        }
    }

    /**
     * Gửi một nội dung text tới trigger Smax.
     *
     * @return array|WP_Error  Mảng response (JSON đã decode) hoặc WP_Error
     */
    public static function send($noidung)
    {
        if (!get_site_option('tgs_audit_zalo_enabled', 1)) {
            return new WP_Error('tgs_audit_zalo_disabled', 'Zalo đang tắt trong cấu hình.');
        }

        $url = trim((string) get_site_option('tgs_audit_zalo_url', self::DEFAULT_URL));
        if ($url === '') {
            return new WP_Error('tgs_audit_zalo_no_url', 'Chưa cấu hình URL trigger Smax.');
        }

        $customer_id = trim((string) get_site_option('tgs_audit_zalo_customer_id', self::DEFAULT_CUSTOMER_ID));
        $page_id     = trim((string) get_site_option('tgs_audit_zalo_page_id', self::DEFAULT_PAGE_ID));
        // Token xác thực Smax — endpoint trigger BẮT BUỘC có (không có → 403
        // "Token empty"). Dán nguyên giá trị header Authorization mà Postman /
        // Smax đang dùng (kèm cả tiền tố "Bearer " nếu có).
        $auth = trim((string) get_site_option('tgs_audit_zalo_auth', ''));

        $payload = [
            'customer' => [
                'id'      => $customer_id,
                'page_id' => $page_id,
            ],
            'attrs' => [
                [
                    'name'  => 'noidung',
                    'value' => (string) $noidung,
                ],
            ],
        ];

        $headers = ['Content-Type' => 'application/json'];
        if ($auth !== '') {
            // WordPress bỏ qua header rỗng nên chỉ gửi khi có giá trị thật.
            $headers['Authorization'] = $auth;
        }

        $resp = wp_remote_post($url, [
            'timeout' => 12,
            'headers' => $headers,
            'body'    => wp_json_encode($payload, JSON_UNESCAPED_UNICODE),
        ]);

        if (is_wp_error($resp)) {
            error_log('[TGS Audit Zalo] HTTP lỗi: ' . $resp->get_error_message());
            return $resp;
        }

        $code = (int) wp_remote_retrieve_response_code($resp);
        $body = wp_remote_retrieve_body($resp);
        $json = json_decode($body, true);

        if ($code >= 200 && $code < 300) {
            return is_array($json) ? $json : ['ok' => true, 'raw' => $body];
        }

        error_log("[TGS Audit Zalo] Smax trả HTTP {$code}: {$body}");
        return new WP_Error(
            'tgs_audit_zalo_http_' . $code,
            'Smax trả lỗi HTTP ' . $code . ': ' . mb_substr((string) $body, 0, 300)
        );
    }

    /**
     * Dựng nội dung tin Zalo tiếng Việt từ một bản ghi.
     */
    public static function build_message(array $e)
    {
        $chan_label = TGS_Audit_Log::channel_label($e['channel'] ?? '');
        $act_label  = (string) ($e['action_label'] ?? ($e['action'] ?? ''));

        $shop = trim(
            (string) ($e['site']['code'] ?? '')
            . (!empty($e['site']['name']) ? ' (' . $e['site']['name'] . ')' : '')
        );
        $doc = (string) ($e['target']['code'] ?? '');
        $actor = (string) ($e['actor']['name'] ?? ($e['actor']['login'] ?? '?'));
        $when  = !empty($e['unix']) ? date_i18n('H:i d/m/Y', (int) $e['unix']) : (string) ($e['ts'] ?? '');

        $is_pos_gift = ($e['channel'] ?? '') === 'pos_gift' && ($e['action'] ?? '') === 'gift_pending_approval';
        $accountant_name = trim((string) ($e['changes']['accountant_name'] ?? ''));
        // Kế toán đang để "tự động duyệt" cho shop này: đơn đã qua ngay lúc
        // nhân viên chốt gửi — tin Zalo chỉ để kế toán BIẾT (xem lại nếu thấy
        // bất thường), không cần ai bấm Duyệt nữa.
        $is_auto_gift = $is_pos_gift && !empty($e['changes']['approval']['auto']);

        $lines = [];
        if ($is_auto_gift) {
            $lines[] = '🎁 Quà tặng ngoài — ĐƠN ĐÃ TỰ ĐỘNG DUYỆT';
        } elseif ($is_pos_gift) {
            // Nghiệp vụ NÀY cần kế toán hành động (bấm Duyệt), khác các nghiệp
            // vụ chỉ để lưu vết — mở đầu bằng lời nhờ thay vì nhãn hệ thống.
            $lines[] = '🎁 ' . ($accountant_name !== '' ? "Nhờ {$accountant_name} " : 'Nhờ kế toán ')
                . 'duyệt giúp quà tặng ngoài';
        } else {
            $lines[] = '🧾 PM MỚI · ' . $act_label;
        }

        $loc = [];
        if ($shop !== '') {
            $loc[] = 'Shop ' . $shop;
        }
        if ($doc !== '') {
            $loc[] = 'Chứng từ ' . $doc;
        }
        if ($loc) {
            $lines[] = implode(' · ', $loc);
        }

        $lines[] = 'Người thao tác: ' . $actor . ' — ' . $when;

        if (!empty($e['summary'])) {
            $lines[] = (string) $e['summary'];
        }

        // Liệt kê từng dòng quà — cho kế toán biết ngay không phải mở link mới thấy.
        $gift_lines = $is_pos_gift && is_array($e['changes']['gift_lines'] ?? null)
            ? $e['changes']['gift_lines'] : [];
        if ($gift_lines) {
            foreach (array_slice($gift_lines, 0, 8) as $g) {
                $unit = trim((string) ($g['unit'] ?? ''));
                $lines[] = '• ' . (string) ($g['name'] ?? '') . ' — SL ' . (string) ($g['qty'] ?? '')
                    . ($unit !== '' ? ' ' . $unit : '');
            }
            if (count($gift_lines) > 8) {
                $lines[] = '… và ' . (count($gift_lines) - 8) . ' dòng khác';
            }
        }

        // Ghi chú tổng của hoá đơn (nhân viên gõ ở ô "Ghi chú tổng" / trong
        // hộp "Chốt gửi kế toán duyệt") — đã lưu vào JSONL, giờ cho vào Zalo
        // luôn để kế toán không phải mở link mới đọc được.
        $note = $is_pos_gift
            ? trim((string) ($e['changes']['cart_snapshot']['note'] ?? ''))
            : '';
        if ($note !== '') {
            $lines[] = '📝 Ghi chú: ' . $note;
        }

        $warn = trim((string) ($e['changes']['warning'] ?? ''));
        if ($warn !== '') {
            $lines[] = '⚠ ' . $warn;
        }

        if ($is_auto_gift) {
            $lines[] = 'Kế toán đang để CHẾ ĐỘ TỰ ĐỘNG DUYỆT cho shop này — nhân viên cứ bán tiếp bình thường.';
            $lines[] = '👉 Kế toán xem lại (nếu cần): ' . TGS_Audit_Log::entry_url($e);
        } else {
            $lines[] = ($is_pos_gift ? '👉 Xem & duyệt: ' : '👉 Xem nhật ký: ') . TGS_Audit_Log::entry_url($e);
        }

        // Trong ngoặc để phân biệt với channel nếu về sau gộp nhiều loại
        if ($is_auto_gift) {
            $lines[] = '(Quà tặng ngoài — tự động duyệt)';
        } elseif ($chan_label !== '' && $chan_label !== ($e['channel'] ?? '')) {
            $lines[] = '(' . $chan_label . ')';
        }

        return implode("\n", $lines);
    }
}
