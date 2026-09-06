/**
 * Nhật ký thao tác — bung/thu chi tiết từng dòng.
 *
 * Trang render sẵn cả hàng chi tiết (ẩn bằng CSS). JS chỉ toggle class + đổi
 * icon mũi tên. Không gọi AJAX — dữ liệu đã có sẵn trong DOM.
 */
(function ($) {
    'use strict';

    function toggle($row, force) {
        var id = $row.data('entry');
        if (!id) { return; }
        var $detail = $('.tgs-au-detail[data-detail="' + $.escapeSelector(id) + '"]');
        var open = typeof force === 'boolean' ? force : !$detail.hasClass('is-open');
        $detail.toggleClass('is-open', open);
        $row.find('.tgs-au-toggle i')
            .attr('class', open ? 'bx bx-chevron-down' : 'bx bx-chevron-right');
    }

    $(function () {
        $('.tgs-au-table').on('click', '.tgs-au-row', function (e) {
            // Cho phép bôi đen text mà không bung
            if (window.getSelection && String(window.getSelection()).length) { return; }
            toggle($(this));
        });

        // Công tắc "Bật bắn Zalo": đổi màu hàng ngay khi tích, không đợi lưu
        $('.tgs-au-toggle-row input[type="checkbox"]').on('change', function () {
            var on = this.checked;
            $(this).closest('.tgs-au-toggle-row')
                .toggleClass('is-on', on)
                .toggleClass('is-off', !on);
        });

        // Deep link ?entry=... : trang đã mở sẵn is-open, cuộn tới cho dễ thấy
        var $open = $('.tgs-au-detail.is-open').first();
        if ($open.length) {
            $('html, body').animate(
                { scrollTop: Math.max(0, $open.offset().top - 120) },
                250
            );
        }
    });
})(jQuery);
