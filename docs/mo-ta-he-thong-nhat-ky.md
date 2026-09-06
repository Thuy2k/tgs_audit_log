# TGS Audit Log — Nhật ký thao tác nghiệp vụ (phần mềm mới BTsoft)

> Plugin: `tgs_audit_log` · Bản mô tả v1 · Ngày 03/09/2026 · Cập nhật 05/09/2026
>
> Đọc file này TRƯỚC khi sửa code plugin hoặc móc thêm nghiệp vụ mới.

**05/09/2026 — thêm action `pos_order/return_created`** ("Hoàn hàng (không đổi
trả)"): CHỈ luồng hoàn thẳng — trả hàng, trả tiền khách, kết thúc (hay dùng khi
đơn xuất bán kèm quà tặng có tiền đã lên thuế rồi hoàn lại cho khách). Ghi 1
dòng nhật ký + bắn Zalo, kèm **ghi chú phiếu hoàn** (`reason`) trong tóm tắt.
Log ngay trong handler AJAX `TGS_POS_Ajax_Return::commit_return_order()`
(`tgs_pos/functions/ajax/class-tgs-pos-ajax-return.php`, hàm
`record_plain_return_audit()`) — kiểu gọi thẳng `TGS_Audit_Log::record()` như
`receipt_method_changed`. Luồng "Hoàn hàng kết hợp đổi trả" KHÔNG đi qua handler
này (gọi thẳng `do_action` từ `commit_pos_sale`) nên cố ý không dính log.

**05/09/2026 — thêm channel `pos_gift`** (Quà tặng ngoài chờ kế toán duyệt):
nhân viên POS thêm quà tặng thủ công vào giỏ → khoá tab → gửi snapshot giỏ
hàng (KHÔNG lưu DB) làm 1 dòng nhật ký + bắn Zalo cho kế toán → kế toán duyệt
ở menu con "Nhật ký chờ kế toán duyệt" → nút Thanh toán ở đúng tab đó mới mở
khoá. Toàn bộ luồng nằm ở `tgs_pos` (giỏ hàng, cổng chặn thanh toán, option
`TGS_POS_Accountant_Gate` theo từng shop) + `tgs_audit_log` (log, Zalo, màn
duyệt trung tâm — `class-tgs-audit-pos-gift-admin.php`,
`templates/pos-gift-review-page.php`, `assets/pos-gift-modal.{js,css}`). Chi
tiết đầy đủ: memory `audit-log-plugin` mục quà tặng ngoài, hoặc đọc thẳng
`tgs_pos/includes/class-tgs-pos-accountant-gate.php`.

**07/09/2026 — `pos_gift` thêm chế độ TỰ ĐỘNG DUYỆT (KẾ TOÁN để, theo shop).**
Trên trang "Nhật ký chờ kế toán duyệt" có thẻ **"Kế toán để tự động duyệt
(theo shop)"** (hiện với người có quyền duyệt): chọn shop → công tắc BẬT/TẮT
(không dùng checkbox mặc định), kèm nút **Bật cho TẤT CẢ** / **Tắt toàn bộ**.
Người bật/tắt là KẾ TOÁN — nhân viên shop chỉ làm theo (chung nhóm Zalo). Lưu
ở `site_option` cả mạng `tgs_audit_pos_gift_auto_blogs` = map `{ blog_id => 1 }`
— đọc được từ site shop mà không cần `switch_to_blog`. Shop nào được để tự
động: nhân viên bấm "Chốt gửi kế toán duyệt" →
`TGS_POS_Accountant_Gate::mark_auto_approved()` (status
`approved` + cờ `auto`) nên cổng thanh toán cho qua NGAY; bản ghi JSONL ghi
`changes.approval = {status: approved, auto: true}` (vẫn `action =
gift_pending_approval` để đi đúng luật bắn Zalo). `TGS_Audit_Zalo::build_message()`
thấy `approval.auto` → đổi lời văn ("ĐƠN ĐÃ TỰ ĐỘNG DUYỆT", "Kế toán xem lại
nếu cần"). Trên màn duyệt, bản ghi auto hiện nhãn **"Tự động duyệt"** (xanh
dương), lọc riêng được, mở modal chỉ để XEM (không còn nút Duyệt/Từ chối).
Mặc định TẮT cho mọi shop.

---

## 1. Vì sao có plugin này

Phần mềm mới (BTsoft) đang chạy song song phần mềm cũ (HTsoft). Ở BTsoft:

- **Đơn bán** từ `tgs_pos` → tự động đồng bộ ngược lên HTsoft (bạn IT đang làm).
- **Mặt hàng / danh mục / giá theo ĐVT / chính sách / thuế suất** → kéo từ HTsoft
  sang, không nhập tay ở BTsoft.
- **Sửa đơn, hoàn thuế, điều chỉnh hoá đơn** → làm TRÊN BTsoft (ví dụ màn
  *Bán hàng → Quản lý VAT → Quản lý phiếu xuất bán*). HTsoft KHÔNG tự biết, nên
  kế toán phải vào HTsoft sửa tay lại đúng những đơn đã đẩy.

Vấn đề: các chị vẫn làm chính trên HTsoft. Khi có người vào BTsoft sửa một
chứng từ đã gửi thuế, **không ai được báo** → HTsoft và cơ quan thuế lệch nhau
mà không ai biết vì sao.

Plugin này giải quyết đúng chỗ đó:

1. **Ghi nhật ký** mọi thao tác thêm / sửa / xoá nghiệp vụ trên BTsoft, có
   **so sánh trước ↔ sau** (dòng nào thêm, dòng nào xoá, số tiền đổi thế nào).
2. **Bắn Zalo tức thì** (qua trung gian Smax) cho nhóm triển khai / kế toán mỗi
   khi có thao tác **nhạy cảm**, kèm **link mở thẳng bản ghi nhật ký** để soi.
3. **Trang xem nhật ký** trong hệ quản trị, tiếng Việt, lọc theo shop / ngày /
   loại nghiệp vụ / người thao tác.

---

## 2. Nguyên tắc thiết kế (bất di bất dịch)

| # | Nguyên tắc | Lý do |
|---|------------|-------|
| 1 | **KHÔNG thêm bảng / cột DB.** Log ghi ra file JSONL. | Hệ 650 site, `class-tgs-database.php` là bảng dùng chung; bump `DB_VERSION` = 650 site cùng migration → nguy cơ treo (xem `tgs_shop_management/docs/huong-dan-thay-doi-database.md` và sự cố 29/08/2026). |
| 2 | **Ghi log KHÔNG được làm chậm / làm hỏng thao tác gốc.** Mọi lỗi ghi log đều nuốt im. Zalo gửi ở `shutdown`, sau khi response đã trả. | Nút "Lưu" của kế toán không được chậm đi vì một cái log. |
| 3 | **Không sửa logic nghiệp vụ.** Chỉ chèn `do_action(...)` ở cuối handler + một class lắng nghe ở plugin này. | Gỡ plugin ra là hệ thống chạy y như cũ. |
| 4 | **Việt hoá, cho người không rành kỹ thuật đọc.** Nhãn nghiệp vụ, tóm tắt thay đổi bằng tiếng Việt. | Người đọc là kế toán / ban lãnh đạo. |
| 5 | **Zalo chỉ bắn cho sự kiện NHẠY CẢM**, cấu hình bật/tắt được. Sự kiện thường vẫn lưu log, không bắn. | Tránh spam nhóm Zalo. |
| 6 | **Mỗi bản ghi có ID cố định** → link Zalo mở thẳng được, không phụ thuộc bộ lọc. | "Xem log đó" trong tin Zalo. |

---

## 3. Kiến trúc

```
tgs_audit_log/
├── tgs-audit-log.php                     Bootstrap: hằng số, require, khởi động
├── docs/
│   └── mo-ta-he-thong-nhat-ky.md         File này
├── includes/
│   ├── class-tgs-audit-log.php           LÕI: record(), cấu hình, gác Zalo, link
│   ├── class-tgs-audit-store.php         Đọc/ghi JSONL: đường dẫn, append, query,
│   │                                     tìm theo ID, vá 1 dòng, dọn file cũ
│   ├── class-tgs-audit-zalo.php          Gửi Smax (hàm riêng) + xếp hàng ở shutdown
│   ├── class-tgs-audit-admin.php         Route + menu vào tgs_shop_management,
│   │                                     xử lý lưu cấu hình, gửi lại Zalo
│   └── integrations/
│       └── integration-bctk-vat.php      Móc cho tgs-bc-tk (nghe do_action)
├── templates/
│   └── audit-log-page.php                Trang xem nhật ký (tiếng Việt)
└── assets/
    ├── audit-log.css
    └── audit-log.js                      Bung/thu chi tiết, nút gửi lại Zalo
```

### 3.1 Luồng một sự kiện

```
Kế toán bấm "Lưu" ở màn Quản lý phiếu xuất bán (VAT)
        │
        ▼
tgs-bc-tk : TGS_BCTK_Ajax::vat_save_lines()
  - chụp trạng thái dòng hàng TRƯỚC  ($audit_before, $audit_grand_before)
  - ... sửa dòng hàng như cũ ...
  - chụp trạng thái SAU              ($audit_after)
  - do_action('tgs_bctk_vat_lines_saved', [ ...ngữ cảnh... ])   ← 1 dòng chèn thêm
  - wp_send_json_success(...)   (response trả về ngay)
        │
        ▼
tgs_audit_log : integration-bctk-vat.php  (nghe hook)
  - so trước ↔ sau → { thêm N, sửa N, xoá N, rows[] }
  - TGS_Audit_Log::record([...])
        │
        ├─► TGS_Audit_Store::append()  → ghi 1 dòng JSON vào file ngày
        │
        └─► nếu severity = 'sensitive' và action nằm trong danh sách bắn Zalo:
              TGS_Audit_Zalo::enqueue()   → xếp hàng
        │
        ▼ (hook 'shutdown', SAU khi response đã trả cho trình duyệt)
TGS_Audit_Zalo::flush()
  - dựng nội dung tiếng Việt + link bản ghi
  - POST tới Smax trigger
  - TGS_Audit_Store::patch(id, ['zalo' => {sent, msg_id | error}])
```

### 3.2 Nơi lưu file

```
wp-content/uploads/audit-log/
├── .htaccess          (Deny from all — chặn tải trực tiếp)
├── index.php
└── 2026-09/
    ├── 2026-09-03.jsonl
    └── 2026-09-04.jsonl
```

- **Một kho tập trung cho cả mạng** (không tách theo site) — trang xem chỉ đọc
  một chỗ, và tra ID không cần biết site nào ghi.
- Ghi bằng `file_put_contents(..., FILE_APPEND | LOCK_EX)` — an toàn khi nhiều
  request ghi cùng lúc.
- Một file / ngày → tra ID (có tiền tố ngày) chỉ mở đúng một file.
- Cron `tgs_audit_prune` chạy hằng ngày, xoá file cũ hơn
  `tgs_audit_retention_days` (mặc định 365 ngày).

### 3.3 Cấu trúc một dòng JSONL

```json
{
  "id": "20260903-2-a1b2c3d4",
  "ts": "2026-09-03 15:34:12",
  "unix": 1756900452,
  "channel": "bctk_vat",
  "action": "vat_save_lines",
  "action_label": "Sửa dòng hàng phiếu xuất bán (VAT)",
  "severity": "sensitive",
  "site":   { "blog_id": 2, "code": "2002", "name": "Yên Lạc" },
  "actor":  { "user_id": 1, "login": "thegioisua", "name": "thegioisua" },
  "target": { "type": "phieu_xuat_ban", "id": 12345,
              "code": "HTS-HD-260811-E5187", "shop": "2002" },
  "summary": "Dòng hàng: +0 / sửa 2 / xoá 1 · Tổng phiếu 470.000 → 463.000",
  "changes": {
    "grand_total": { "before": 470000, "after": 463000 },
    "warning": "Tổng phiếu mới 463.000đ nhưng tiền đã thu 470.000đ — chênh 7.000đ.",
    "lines": {
      "added": 0, "removed": 1, "modified": 2,
      "rows": [
        { "state": "modified", "id": 88,
          "before": { "sku": "130211041", "ten": "Kun STC Cam", "dvt": "Lốc_4",
                      "sl": 3, "don_gia": 30000, "ck": 0, "thue_pct": 8, "thue": 0 },
          "after":  { "sku": "130211041", "ten": "Kun STC Cam", "dvt": "Lốc_4",
                      "sl": 2, "don_gia": 30000, "ck": 0, "thue_pct": 8, "thue": 0 } }
      ]
    }
  },
  "context": { "ip": "10.0.0.5", "ua": "Mozilla/5.0 ...", "uri": "/wp-admin/admin-ajax.php" },
  "zalo": { "sent": true, "at": "2026-09-03 15:34:13", "msg_id": "abc123" },
  "admin_blog": 1
}
```

Trường bắt buộc khi gọi `record()`: `channel`, `action`. Còn lại có mặc định.

---

## 4. Gửi Zalo qua Smax (hàm riêng)

`TGS_Audit_Zalo::send($noidung)` — dựng đúng request curl anh đưa:

```
POST  {tgs_audit_zalo_url}
Headers:
  Content-Type: application/json
  Authorization:            (rỗng — giống 'Authorization;' của curl)
Body:
  {
    "customer": { "id": "{tgs_audit_zalo_customer_id}",
                  "page_id": "{tgs_audit_zalo_page_id}" },
    "attrs": [ { "name": "noidung", "value": "<nội dung tiếng Việt + link>" } ]
  }
```

### Cấu hình (site_option — 1 giá trị cho cả mạng)

| Option | Mặc định | Ý nghĩa |
|--------|----------|---------|
| `tgs_audit_zalo_enabled` | `1` | Bật/tắt toàn bộ việc bắn Zalo |
| `tgs_audit_zalo_url` | `https://api.smax.ai/public/bizs/the-gioi--sua/triggers/6a98f29db80215669e03d4b2` | Endpoint trigger |
| `tgs_audit_zalo_customer_id` | `zlw6350040536411401311` | `customer.id` |
| `tgs_audit_zalo_page_id` | `zlw643803197505260719` | `customer.page_id` |
| `tgs_audit_zalo_auth` | *(rỗng)* | **Bắt buộc.** Giá trị header `Authorization` gửi kèm (vd `Bearer <token>`). Endpoint trigger Smax trả `403 "Token empty"` nếu không có. Snippet curl Postman xuất ra `--header 'Authorization;'` = header RỖNG (Postman gắn token ở chỗ khác: Collection auth / biến môi trường) → curl trần và `wp_remote_post` đều fail; phải lấy token thật dán vào đây. |
| `tgs_audit_zalo_actions` | `["bctk_vat/vat_save_lines"]` | Danh sách `channel/action` được bắn Zalo. Ngoài danh sách này → chỉ lưu log. **Trên giao diện là các ô tích** (nhãn tiếng Việt lấy từ `TGS_Audit_Log::LABELS['action']`), người dùng không gõ mã tay; lưu xuống vẫn là chuỗi `channel/action`. |
| `tgs_audit_admin_url` | *(rỗng)* | Gốc URL trang quản trị để dựng link trong tin Zalo. Rỗng → tự lấy `admin.php` của site chính (`get_main_site_id()`) |
| `tgs_audit_retention_days` | `365` | Số ngày giữ file nhật ký |

Sửa các option này ở **panel Cấu hình** đầu trang nhật ký (chỉ hiện với
`manage_network_options`). Toàn bộ nhãn trên panel đã Việt hoá; mã kỹ thuật
(`customer.id`, `customer.page_id`, `channel/action`) chỉ hiện dạng gợi ý nhỏ
màu xám để lập trình viên đối chiếu.

### Mẫu nội dung tin Zalo

```
🧾 PM MỚI · Sửa phiếu xuất bán (VAT)
Shop 2002 (Yên Lạc) · Chứng từ HTS-HD-260811-E5187
Người thao tác: thegioisua — 15:34 03/09/2026
Dòng hàng: +0 / sửa 2 / xoá 1 · Tổng phiếu 470.000 → 463.000
⚠ Tiền đã thu chênh 7.000đ — kiểm tra phiếu thu / công nợ.
👉 Xem nhật ký: https://quantri.thegioisua.com/wp-admin/admin.php?page=tgs-shop-management&view=tgs-audit-log&entry=20260903-2-a1b2c3d4
```

---

## 5. Trang xem nhật ký

- **Đường vào**: Hệ quản trị (`tgs_shop_management`) → menu **Báo cáo** →
  *Nhật ký thao tác (PM mới)*. Slug view: `tgs-audit-log`.
- **Bộ lọc** (GET, server render — giống `tgs-activities-admin/templates/erp-page.php`):
  ngày, loại nghiệp vụ, shop, người thao tác, từ khoá, "chỉ nhạy cảm".
- **Bảng**: Thời gian · Nghiệp vụ · Shop · Chứng từ · Người thao tác ·
  Tóm tắt thay đổi · Zalo · [Xem].
- **Bấm một dòng** → bung chi tiết: bảng trước ↔ sau (đỏ = xoá, xanh = thêm,
  vàng = sửa), tổng phiếu trước/sau, cảnh báo, IP, trạng thái Zalo + nút
  **Gửi lại Zalo**.
- **Deep link** `?page=tgs-shop-management&view=tgs-audit-log&entry=<id>` → tự
  mở sẵn chi tiết bản ghi đó (đọc đúng file ngày theo tiền tố ID).

---

## 6. Điểm móc đã cài (đợt 1 — chỉ `tgs-bc-tk` VAT)

### 6.1 Sửa trong `tgs-bc-tk` (bổ sung, không đổi logic)

File `wp-content/plugins/tgs-bc-tk/includes/class-bctk-ajax.php`:

- `vat_save_lines()`:
  - Sau khi dựng `$own`: chụp `$audit_before` (dòng hàng hiện tại) +
    `$audit_grand_before` (`local_ledger_total_amount` của phiếu xuất) — **chỉ
    chạy khi `has_action('tgs_bctk_vat_lines_saved')`**.
  - Ngay trước `vat_row_for_sale(...)`: chụp `$audit_after` rồi
    `do_action('tgs_bctk_vat_lines_saved', [...])`.
- `vat_save_note()`:
  - Đầu hàm: `$report_blog = get_current_blog_id();`
  - Trước `wp_send_json_success`: `do_action('tgs_bctk_vat_note_saved', [...])`
    (kèm ghi chú trước/sau).

Payload hook `tgs_bctk_vat_lines_saved`:

```php
[
  'blog_id'      => int,   // site shop của phiếu
  'sale_id'      => int,
  'report_blog'  => int,   // site đang chạy hệ quản trị (để dựng link)
  'sale_code'    => string, // 'HTS-HD-...'
  'export_id'    => int,
  'before'       => array,  // hàng dòng ARRAY_A, cột chuẩn hoá ở integration
  'after'        => array,
  'grand_before' => float,
  'grand_total'  => float,
  'warning'      => string, // cảnh báo lệch tiền đã thu (nếu có)
  'live_count'   => int,
]
```

### 6.2 Nghe ở `tgs_audit_log/includes/integrations/integration-bctk-vat.php`

- `tgs_audit_norm_lines()` — chuẩn hoá 1 dòng về
  `{ sku, ten, dvt, sl, don_gia, ck, thue_pct, thue, so_lo, exp }`.
- `tgs_audit_diff_lines(before, after)` — ghép theo `local_ledger_item_id`:
  `added` (chỉ có ở sau), `removed` (chỉ có ở trước), `modified` (lệch bất kỳ
  trường nào, sai số 0,01). Trả `rows[]` chỉ gồm dòng có thay đổi.
- `tgs_audit_shop_meta(blog_id)` — lấy `tgs_site_code` + `blogname` (không
  `switch_to_blog`, đọc thẳng `wp_blogs` + `get_blog_option`).
- Gọi `TGS_Audit_Log::record()` với `channel='bctk_vat'`,
  `action='vat_save_lines'`, `severity='sensitive'`.
- Sự kiện ghi chú: `action='vat_save_note'`, `severity='info'` (mặc định không
  bắn Zalo; thêm `bctk_vat/vat_save_note` vào `tgs_audit_zalo_actions` nếu muốn).

---

## 7. Cách móc thêm nghiệp vụ mới (đợt sau)

1. Ở plugin nguồn, cuối handler thêm / sửa / xoá: chèn `do_action('<tên>', [...])`
   với đủ ngữ cảnh (ưu tiên kèm trạng thái trước & sau). **Không** đổi logic.
2. Tạo `includes/integrations/integration-<slug>.php` trong plugin này, nghe hook
   đó, dựng tóm tắt tiếng Việt, gọi `TGS_Audit_Log::record()`.
3. `require` file integration trong `tgs-audit-log.php`.
4. Nếu cần bắn Zalo: thêm `"<channel>/<action>"` vào option
   `tgs_audit_zalo_actions`.
5. Thêm nhãn `channel` / `action` vào `TGS_Audit_Log::LABELS` để trang xem hiển
   thị tiếng Việt và bộ lọc có lựa chọn.

Ứng viên đợt sau: `tgs_htsoft_*` (kéo mặt hàng / giá), `tgs_selling_policy`
(chính sách), quản lý thuế suất, phiếu điều chỉnh giảm, hoàn hàng POS, nhập mua.

---

## 8. Kiểm thử nhanh sau khi bật plugin

1. Network Activate `tgs_audit_log`.
2. Vào *Bán hàng → Quản lý VAT → Quản lý phiếu xuất bán (VAT)*, mở một phiếu
   **chưa phát hành hoá đơn**, sửa số lượng một dòng, bấm **Lưu**.
3. Vào *Báo cáo → Nhật ký thao tác (PM mới)* → thấy dòng mới, bấm vào xem
   trước ↔ sau.
4. Kiểm tra file `wp-content/uploads/audit-log/<YYYY-MM>/<YYYY-MM-DD>.jsonl` có
   một dòng JSON.
5. Nếu đã cấu hình Zalo: nhóm Zalo nhận được tin kèm link; bấm link mở đúng bản
   ghi. Trường `zalo.sent` trong file = `true`.
6. Thử **Gửi lại Zalo** từ trang chi tiết.

---

## 9. Việc CHƯA làm (ghi rõ để khỏi tưởng thiếu sót)

- Chưa móc các plugin ngoài `tgs-bc-tk` (đúng phạm vi đợt 1).
- Chưa có gộp digest theo giờ — mỗi sự kiện nhạy cảm bắn một tin.
- Chưa phân quyền chi tiết: xem nhật ký theo `TGS_BCTK_CAPABILITY` tương đương
  (`read`); panel cấu hình + gửi lại Zalo yêu cầu `manage_network_options`.
- Chưa có xuất Excel trang nhật ký (JSONL tự thân đã là dữ liệu thô để trích).
