<?php

/**
 * Đọc / ghi kho nhật ký JSONL.
 *
 * Một kho tập trung cho cả mạng: wp-content/uploads/audit-log/<YYYY-MM>/<YYYY-MM-DD>.jsonl
 * — trang xem chỉ đọc một chỗ, tra ID không cần biết site nào ghi.
 *
 * @package tgs_audit_log
 */

if (!defined('ABSPATH')) {
    exit;
}

class TGS_Audit_Store
{
    const DIRNAME = 'audit-log';

    /** Gốc kho log — cố định dưới wp-content/uploads, không theo site */
    public static function base_dir()
    {
        return rtrim(WP_CONTENT_DIR, '/\\') . '/uploads/' . self::DIRNAME;
    }

    /** Tạo thư mục gốc + chặn tải trực tiếp qua trình duyệt */
    public static function ensure_dir()
    {
        $dir = self::base_dir();
        if (!is_dir($dir)) {
            wp_mkdir_p($dir);
        }
        if (is_dir($dir) && !file_exists($dir . '/.htaccess')) {
            // Apache 2.2 (Deny) lẫn 2.4 (Require) — chặn cả hai đời
            @file_put_contents($dir . '/.htaccess', "Require all denied\nDeny from all\n");
        }
        if (is_dir($dir) && !file_exists($dir . '/index.php')) {
            @file_put_contents($dir . '/index.php', "<?php // Im lặng là vàng.\n");
        }
        return $dir;
    }

    /** Đường dẫn file cho một ngày 'Y-m-d' */
    public static function file_for_date($ymd)
    {
        $ymd = self::norm_ymd($ymd);
        $month = substr($ymd, 0, 7);
        return self::base_dir() . '/' . $month . '/' . $ymd . '.jsonl';
    }

    /**
     * Ghi thêm MỘT bản ghi. Không bao giờ ném lỗi ra ngoài — thao tác nghiệp vụ
     * gốc quan trọng hơn cái log.
     */
    public static function append(array $entry)
    {
        try {
            $ymd = substr((string) ($entry['ts'] ?? ''), 0, 10);
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $ymd)) {
                $ymd = current_time('Y-m-d');
            }
            $file = self::file_for_date($ymd);
            $sub  = dirname($file);
            if (!is_dir($sub)) {
                wp_mkdir_p($sub);
            }
            self::ensure_dir();

            $line = wp_json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($line === false) {
                return false;
            }
            return (bool) file_put_contents($file, $line . "\n", FILE_APPEND | LOCK_EX);
        } catch (\Throwable $e) {
            error_log('[TGS Audit] append lỗi: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Vá MỘT bản ghi theo id (đọc toàn file ngày, thay dòng khớp id, ghi lại).
     * Chỉ dùng cho lượng thấp (cập nhật trạng thái Zalo) — sự kiện nhạy cảm hiếm.
     *
     * @param string $id
     * @param array  $merge  Mảng trộn nông vào bản ghi (vd ['zalo' => [...]])
     */
    public static function patch($id, array $merge)
    {
        try {
            $ymd = self::date_from_id($id);
            if ($ymd === '') {
                return false;
            }
            $file = self::file_for_date($ymd);
            if (!file_exists($file)) {
                return false;
            }

            $fh = fopen($file, 'c+');
            if (!$fh) {
                return false;
            }
            if (!flock($fh, LOCK_EX)) {
                fclose($fh);
                return false;
            }

            $out = '';
            $hit = false;
            while (($ln = fgets($fh)) !== false) {
                $trim = trim($ln);
                if ($trim === '') {
                    continue;
                }
                $row = json_decode($trim, true);
                if (is_array($row) && ($row['id'] ?? null) === $id) {
                    foreach ($merge as $k => $v) {
                        $row[$k] = $v;
                    }
                    $hit = true;
                    $enc = wp_json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    $out .= ($enc === false ? $trim : $enc) . "\n";
                } else {
                    $out .= $trim . "\n";
                }
            }

            if ($hit) {
                ftruncate($fh, 0);
                rewind($fh);
                fwrite($fh, $out);
                fflush($fh);
            }
            flock($fh, LOCK_UN);
            fclose($fh);
            return $hit;
        } catch (\Throwable $e) {
            error_log('[TGS Audit] patch lỗi: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Đọc bản ghi của một ngày, mới nhất lên đầu, đã lọc.
     *
     * Bộ lọc hỗ trợ: channel, action, blog_id, actor (khớp chuỗi con
     * login/name/user_id), q (tìm trong summary + target.code + actor + JSON
     * changes), severity ('sensitive'|'info'), zalo ('sent'|'not').
     */
    public static function read_date($ymd, array $filters = [], $limit = 2000)
    {
        $file = self::file_for_date($ymd);
        if (!file_exists($file)) {
            return [];
        }

        $rows = [];
        $fh = fopen($file, 'r');
        if (!$fh) {
            return [];
        }
        while (($ln = fgets($fh)) !== false) {
            $trim = trim($ln);
            if ($trim === '') {
                continue;
            }
            $row = json_decode($trim, true);
            if (!is_array($row) || empty($row['id'])) {
                continue;
            }
            if (!self::match($row, $filters)) {
                continue;
            }
            $rows[] = $row;
        }
        fclose($fh);

        // Mới nhất lên đầu
        $rows = array_reverse($rows);
        if ($limit > 0 && count($rows) > $limit) {
            $rows = array_slice($rows, 0, $limit);
        }
        return $rows;
    }

    /** Tìm một bản ghi theo id (mở đúng file ngày theo tiền tố id) */
    public static function find_by_id($id)
    {
        $ymd = self::date_from_id($id);
        if ($ymd === '') {
            return null;
        }
        $file = self::file_for_date($ymd);
        if (!file_exists($file)) {
            return null;
        }
        $fh = fopen($file, 'r');
        if (!$fh) {
            return null;
        }
        $found = null;
        while (($ln = fgets($fh)) !== false) {
            $trim = trim($ln);
            if ($trim === '' || strpos($trim, $id) === false) {
                continue;
            }
            $row = json_decode($trim, true);
            if (is_array($row) && ($row['id'] ?? null) === $id) {
                $found = $row;
                break;
            }
        }
        fclose($fh);
        return $found;
    }

    /** Danh sách ngày có file (mới nhất lên đầu), tối đa $max ngày gần đây */
    public static function list_dates($max = 120)
    {
        $base = self::base_dir();
        if (!is_dir($base)) {
            return [];
        }
        $dates = [];
        foreach (glob($base . '/*', GLOB_ONLYDIR) ?: [] as $monthDir) {
            foreach (glob($monthDir . '/*.jsonl') ?: [] as $f) {
                $d = basename($f, '.jsonl');
                if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
                    $dates[] = $d;
                }
            }
        }
        rsort($dates);
        return array_slice($dates, 0, $max);
    }

    /** Xoá file cũ hơn $days ngày. Gọi từ cron 'tgs_audit_prune'. */
    public static function prune($days)
    {
        $days = max(1, (int) $days);
        $cut  = strtotime('-' . $days . ' days', current_time('timestamp'));
        $base = self::base_dir();
        if (!is_dir($base)) {
            return;
        }
        foreach (glob($base . '/*', GLOB_ONLYDIR) ?: [] as $monthDir) {
            foreach (glob($monthDir . '/*.jsonl') ?: [] as $f) {
                $d = basename($f, '.jsonl');
                if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && strtotime($d) < $cut) {
                    @unlink($f);
                }
            }
            if (!glob($monthDir . '/*.jsonl')) {
                @rmdir($monthDir);
            }
        }
    }

    /* ── nội bộ ──────────────────────────────────────────────────────────── */

    private static function norm_ymd($ymd)
    {
        $ymd = (string) $ymd;
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $ymd) ? $ymd : current_time('Y-m-d');
    }

    /** id dạng '20260903-2-a1b2c3d4' → '2026-09-03' */
    public static function date_from_id($id)
    {
        if (!is_string($id) || !preg_match('/^(\d{4})(\d{2})(\d{2})-/', $id, $m)) {
            return '';
        }
        return $m[1] . '-' . $m[2] . '-' . $m[3];
    }

    private static function match(array $row, array $f)
    {
        if (!empty($f['channel']) && ($row['channel'] ?? '') !== $f['channel']) {
            return false;
        }
        if (!empty($f['action']) && ($row['action'] ?? '') !== $f['action']) {
            return false;
        }
        if (!empty($f['blog_id']) && (int) ($row['site']['blog_id'] ?? 0) !== (int) $f['blog_id']) {
            return false;
        }
        if (!empty($f['severity']) && ($row['severity'] ?? '') !== $f['severity']) {
            return false;
        }
        if (!empty($f['zalo'])) {
            $sent = !empty($row['zalo']['sent']);
            if ($f['zalo'] === 'sent' && !$sent) {
                return false;
            }
            if ($f['zalo'] === 'not' && $sent) {
                return false;
            }
        }
        if (!empty($f['actor'])) {
            $needle = mb_strtolower(trim($f['actor']));
            $hay = mb_strtolower(
                ($row['actor']['login'] ?? '') . ' ' .
                ($row['actor']['name'] ?? '') . ' ' .
                ($row['actor']['user_id'] ?? '')
            );
            if ($needle !== '' && mb_strpos($hay, $needle) === false) {
                return false;
            }
        }
        if (!empty($f['q'])) {
            $needle = mb_strtolower(trim($f['q']));
            $hay = mb_strtolower(
                ($row['summary'] ?? '') . ' ' .
                ($row['action_label'] ?? '') . ' ' .
                ($row['target']['code'] ?? '') . ' ' .
                ($row['target']['shop'] ?? '') . ' ' .
                ($row['site']['code'] ?? '') . ' ' .
                ($row['site']['name'] ?? '') . ' ' .
                ($row['actor']['login'] ?? '') . ' ' .
                ($row['actor']['name'] ?? '') . ' ' .
                wp_json_encode($row['changes'] ?? [], JSON_UNESCAPED_UNICODE)
            );
            if ($needle !== '' && mb_strpos($hay, $needle) === false) {
                return false;
            }
        }
        return true;
    }
}
