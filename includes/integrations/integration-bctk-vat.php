<?php

/**
 * Móc nhật ký cho tgs-bc-tk — khối Quản lý VAT.
 *
 * Nghe do_action mà class-bctk-ajax.php phát ra (xem mục 6 tài liệu):
 *   - tgs_bctk_vat_lines_saved : sửa dòng hàng phiếu xuất bán  → NHẠY CẢM
 *   - tgs_bctk_vat_note_saved  : sửa ghi chú phiếu             → thường
 *
 * Không đụng logic tgs-bc-tk. Nếu plugin này tắt, do_action thành no-op.
 *
 * @package tgs_audit_log
 */

if (!defined('ABSPATH')) {
    exit;
}

/* ── Chuẩn hoá & so sánh dòng hàng ───────────────────────────────────────── */

/**
 * Đưa một dòng local_ledger_item (ARRAY_A từ snapshot của handler) về khuôn gọn.
 *
 * @return array [ id => [sku,ten,dvt,sl,don_gia,ck,thue_pct,thue,so_lo,exp] ]
 */
function tgs_audit_norm_lines($rows)
{
    $out = [];
    foreach ((array) $rows as $r) {
        $id = (int) ($r['id'] ?? $r['local_ledger_item_id'] ?? 0);
        if ($id <= 0) {
            continue;
        }
        $out[$id] = [
            'sku'      => (string) ($r['sku'] ?? $r['local_product_sku'] ?? ''),
            'ten'      => (string) ($r['ten'] ?? $r['local_ledger_item_product_name_cache'] ?? ''),
            'dvt'      => (string) ($r['dvt'] ?? $r['local_ledger_item_unit_name'] ?? ''),
            'sl'       => (float) ($r['sl'] ?? $r['local_ledger_item_unit_quantity'] ?? 0),
            'qty'      => (float) ($r['qty'] ?? $r['quantity'] ?? 0),
            'don_gia'  => (float) ($r['price'] ?? $r['don_gia'] ?? 0),
            'ck'       => (float) ($r['ck'] ?? $r['local_ledger_item_discount_amount'] ?? 0),
            'thue_pct' => (float) ($r['thue_pct'] ?? $r['local_ledger_item_tax_percent'] ?? 0),
            'thue'     => (float) ($r['thue'] ?? $r['local_ledger_item_tax_amount'] ?? 0),
            'so_lo'    => (string) ($r['so_lo'] ?? $r['lot_code'] ?? ''),
            'exp'      => (string) ($r['exp'] ?? $r['exp_date'] ?? ''),
        ];
    }
    return $out;
}

/** Hai số coi là bằng nhau nếu lệch < 0,01 */
function tgs_audit_num_eq($a, $b)
{
    return abs((float) $a - (float) $b) < 0.01;
}

/**
 * So khuôn trước ↔ sau, ghép theo local_ledger_item_id.
 *
 * @return array {
 *   added:int, removed:int, modified:int,
 *   rows: list<{ state:'added'|'removed'|'modified', id:int, before:?array, after:?array, fields:string[] }>
 * }
 */
function tgs_audit_diff_lines($before, $after)
{
    $b = tgs_audit_norm_lines($before);
    $a = tgs_audit_norm_lines($after);

    $rows = [];
    $added = $removed = $modified = 0;

    foreach ($a as $id => $row) {
        if (!isset($b[$id])) {
            $added++;
            $rows[] = ['state' => 'added', 'id' => $id, 'before' => null, 'after' => $row, 'fields' => []];
        }
    }
    foreach ($b as $id => $row) {
        if (!isset($a[$id])) {
            $removed++;
            $rows[] = ['state' => 'removed', 'id' => $id, 'before' => $row, 'after' => null, 'fields' => []];
        }
    }
    foreach ($a as $id => $arow) {
        if (!isset($b[$id])) {
            continue;
        }
        $brow = $b[$id];
        $fields = [];
        foreach (['sku', 'ten', 'dvt', 'so_lo', 'exp'] as $f) {
            if ((string) $brow[$f] !== (string) $arow[$f]) {
                $fields[] = $f;
            }
        }
        foreach (['sl', 'don_gia', 'ck', 'thue_pct', 'thue'] as $f) {
            if (!tgs_audit_num_eq($brow[$f], $arow[$f])) {
                $fields[] = $f;
            }
        }
        if ($fields) {
            $modified++;
            $rows[] = ['state' => 'modified', 'id' => $id, 'before' => $brow, 'after' => $arow, 'fields' => $fields];
        }
    }

    return [
        'added'    => $added,
        'removed'  => $removed,
        'modified' => $modified,
        'unchanged' => max(0, count($a) - $added - $modified),
        'rows'     => $rows,
    ];
}

/**
 * Mã shop (tgs_site_code) + tên site của một blog — KHÔNG switch_to_blog.
 * Đọc thẳng wp_blogs (cột tgs_site_code do tgs_site_code_manager thêm) +
 * get_blog_option cho blogname.
 */
function tgs_audit_shop_meta($blog_id)
{
    global $wpdb;
    $blog_id = (int) $blog_id;
    $code = '';
    if ($blog_id > 0) {
        $code = (string) $wpdb->get_var($wpdb->prepare(
            "SELECT tgs_site_code FROM {$wpdb->base_prefix}blogs WHERE blog_id = %d",
            $blog_id
        ));
    }
    $name = $blog_id > 0 ? (string) get_blog_option($blog_id, 'blogname', '') : '';
    return ['code' => $code, 'name' => $name];
}

/* ── Nghe hook ──────────────────────────────────────────────────────────── */

add_action('tgs_bctk_vat_lines_saved', static function ($ctx) {
    if (!class_exists('TGS_Audit_Log') || !is_array($ctx)) {
        return;
    }

    $blog_id = (int) ($ctx['blog_id'] ?? 0);
    $shop    = tgs_audit_shop_meta($blog_id);
    $diff    = tgs_audit_diff_lines($ctx['before'] ?? [], $ctx['after'] ?? []);

    $g_before = (float) ($ctx['grand_before'] ?? 0);
    $g_after  = (float) ($ctx['grand_total'] ?? 0);

    $has_change = $diff['added'] || $diff['removed'] || $diff['modified']
        || !tgs_audit_num_eq($g_before, $g_after);

    $summary = sprintf(
        'Dòng hàng: +%d / sửa %d / xoá %d · Tổng phiếu %s → %s',
        $diff['added'],
        $diff['modified'],
        $diff['removed'],
        number_format_i18n($g_before),
        number_format_i18n($g_after)
    );
    $warning = trim((string) ($ctx['warning'] ?? ''));
    if ($warning !== '') {
        $summary .= ' · ⚠ ' . $warning;
    }
    if (!$has_change) {
        $summary = 'Bấm lưu nhưng không có thay đổi dòng hàng · Tổng phiếu '
            . number_format_i18n($g_after);
    }

    TGS_Audit_Log::record([
        'channel'      => 'bctk_vat',
        'action'       => 'vat_save_lines',
        'action_label' => 'Sửa dòng hàng phiếu xuất bán (VAT)',
        // Không có thay đổi thật → hạ xuống 'info' để không bắn Zalo
        'severity'     => $has_change ? 'sensitive' : 'info',
        'admin_blog'   => (int) ($ctx['report_blog'] ?? 0),
        'site' => [
            'blog_id' => $blog_id,
            'code'    => $shop['code'],
            'name'    => $shop['name'],
        ],
        'target' => [
            'type' => 'phieu_xuat_ban',
            'id'   => (int) ($ctx['sale_id'] ?? 0),
            'code' => (string) ($ctx['sale_code'] ?? ''),
            'shop' => $shop['code'],
        ],
        'summary' => $summary,
        'changes' => [
            'grand_total' => ['before' => $g_before, 'after' => $g_after],
            'warning'     => $warning,
            'lines'       => $diff,
            'live_count'  => (int) ($ctx['live_count'] ?? 0),
        ],
    ]);
}, 10, 1);

add_action('tgs_bctk_vat_note_saved', static function ($ctx) {
    if (!class_exists('TGS_Audit_Log') || !is_array($ctx)) {
        return;
    }

    $before = trim((string) ($ctx['before'] ?? ''));
    $after  = trim((string) ($ctx['after'] ?? ''));
    if ($before === $after) {
        return; // bấm lưu nhưng ghi chú y nguyên
    }

    $blog_id = (int) ($ctx['blog_id'] ?? 0);
    $shop    = tgs_audit_shop_meta($blog_id);

    $short = static function ($s) {
        $s = trim(preg_replace('/\s+/', ' ', (string) $s));
        return $s === '' ? '(trống)' : (mb_strlen($s) > 60 ? mb_substr($s, 0, 60) . '…' : $s);
    };

    TGS_Audit_Log::record([
        'channel'      => 'bctk_vat',
        'action'       => 'vat_save_note',
        'action_label' => 'Sửa ghi chú phiếu xuất bán (VAT)',
        // Trước để 'info' (chỉ lưu log, không bắn Zalo) — user yêu cầu sửa
        // ghi chú đơn hàng ở mọi màn đều phải báo Zalo, nâng lên 'sensitive'.
        'severity'     => 'sensitive',
        'admin_blog'   => (int) ($ctx['report_blog'] ?? 0),
        'site' => [
            'blog_id' => $blog_id,
            'code'    => $shop['code'],
            'name'    => $shop['name'],
        ],
        'target' => [
            'type' => 'phieu_xuat_ban',
            'id'   => (int) ($ctx['sale_id'] ?? 0),
            'code' => (string) ($ctx['sale_code'] ?? ''),
            'shop' => $shop['code'],
        ],
        'summary' => 'Ghi chú: "' . $short($before) . '" → "' . $short($after) . '"',
        'changes' => [
            'note' => ['before' => $before, 'after' => $after],
        ],
    ]);
}, 10, 1);
