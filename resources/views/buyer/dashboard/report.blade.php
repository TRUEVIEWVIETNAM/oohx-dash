{{--
    Báo cáo phát sóng — đọc qua `/api/v2` từ TRÌNH DUYỆT.

    Bên tiêu thụ thứ sáu của nhóm cần quyền, và trang Blade cuối cùng còn
    NHÚNG dữ liệu vào HTML: bản cũ viết `@json($dailyData)` thẳng vào khối
    script, nên toàn bộ chuỗi impressions theo ngày nằm trong mã nguồn trang.

    Lý do không gọi từ PHP vẫn như năm trang kia: `Http::get()` là một request
    giữ một worker LSAPI trong khi chờ một request khác cũng cần worker; gọi
    qua HTTP kernel trong cùng tiến trình thì phải dựng giả phiên + Sanctum cho
    request con, và `throttle` đếm đôi mỗi lần vẽ trang.

    ══ Chart.js phải có lối thoát ══

    Bản cũ có HAI khối script: một khối đẩy vào `@push('scripts')` chỉ để vẽ
    biểu đồ, và nếu CDN bị chặn thì `new Chart` nổ trong khối đó — phần còn
    lại của trang vẫn hiện, vì máy chủ đã render sẵn.

    Nay mọi thứ nằm trong MỘT khối. Nên nếu `window.Chart` không có mà script
    vẫn gọi `new Chart`, lỗi sẽ cắt luôn ô thống kê và bảng chi tiết — một CDN
    chặn biến thành một trang trắng. Vì vậy biểu đồ vẽ CUỐI và chỉ khi
    `window.Chart` là một hàm; thiếu nó thì trang nói ra, không im lặng.

    ══ Hai cái bẫy của Blade ══

    1. Blade biên dịch chỉ thị TRƯỚC khi bỏ chú thích, nên không viết tên chỉ
       thị nào trong khối chú thích này.
    2. Blade cắt tham số chỉ thị tại dấu `)` đầu tiên, nên mảng cấu hình dựng ở
       khối PHP bên dưới rồi truyền vào bằng MỘT biến.
--}}
@extends('frontpage.layouts.app', ['activeNav' => 'dashboard', 'bodyClass' => ''])

@section('title', 'Báo cáo — ' . $campaign->name . ' | OOHX')

@push('head')
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
@endpush

@section('content')
@php
    $cauHinhRpt = [
        'api'          => url('/api/v2/campaigns/' . $campaign->id . '/report'),
        'duongChiTiet' => route('buyer.campaigns.show', $campaign),
        'dangNhap'     => url('/login'),
    ];
@endphp
<script type="application/json" data-rpt-config>@json($cauHinhRpt, JSON_HEX_TAG | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)</script>

<div class="w" style="padding-top:24px;padding-bottom:64px">

    {{--
        Khối đầu trang vẫn do máy chủ vẽ: mã và tên chiến dịch đã có từ ràng
        buộc route, và chúng không phải dữ liệu báo cáo.
    --}}
    <div class="buyer-welcome">
        <div>
            <div style="font-size:12px;color:var(--t4);font-weight:600;margin-bottom:4px">{{ $campaign->code }}</div>
            <h1 class="buyer-welcome-title">{{ $campaign->name }}</h1>
            <p class="buyer-welcome-sub">Báo cáo campaign</p>
        </div>
        <a href="{{ route('buyer.campaigns.show', $campaign) }}" class="btn btn-s btn-sm">Chi tiết Campaign</a>
    </div>

    <div data-rpt-loi hidden>
        <div class="cart-alert" style="border-color:var(--red);color:var(--red)">
            <span data-rpt-loi-msg></span>
            <a href="{{ url('/login') }}" style="margin-left:8px">Đăng nhập lại</a>
        </div>
    </div>

    {{--
        Bốn thẻ rỗng giữ chỗ. `.rpt-stats` là grid không có `min-height`, nên
        khối rỗng cao 0px và mọi thứ bên dưới nhảy khi API trả về — đúng lỗi đã
        sửa ở trang đầu khu người mua (PR #47).
    --}}
    <div class="rpt-stats" data-rpt-thongke>
        @for($i = 0; $i < 4; $i++)
        <div class="rpt-stat" aria-hidden="true">
            <div class="rpt-stat-n">&nbsp;</div>
            <div class="rpt-stat-l">&nbsp;</div>
            <div class="rpt-stat-sub">&nbsp;</div>
        </div>
        @endfor
    </div>

    <div data-rpt-tiendo></div>

    {{-- Daily impressions chart --}}
    <div class="rpt-card">
        <div class="rpt-card-title">Impressions theo ngày</div>
        <div style="position:relative;height:300px">
            <canvas data-rpt-bieudo></canvas>
        </div>
    </div>

    {{-- Screen breakdown table --}}
    <div class="rpt-card" style="margin-top:16px">
        <div class="rpt-card-title">Chi tiết theo màn hình</div>
        <div class="rpt-table-wrap" data-rpt-bang>
            <div style="color:var(--t3);font-size:13px;padding:24px 0">Đang tải báo cáo…</div>
        </div>
    </div>
</div>

<script>
(function () {
    'use strict';

    var oCauHinh = document.querySelector('[data-rpt-config]');
    var oBang    = document.querySelector('[data-rpt-bang]');

    if (! oCauHinh || ! oBang) {
        return;
    }

    var cf        = JSON.parse(oCauHinh.textContent);
    var oThongKe  = document.querySelector('[data-rpt-thongke]');
    var oTienDo   = document.querySelector('[data-rpt-tiendo]');
    var oBieuDo   = document.querySelector('[data-rpt-bieudo]');
    var oLoi      = document.querySelector('[data-rpt-loi]');
    var oLoiMsg   = document.querySelector('[data-rpt-loi-msg]');

    // ── Giúp việc ────────────────────────────────────────────────────────────

    /** Thoát đủ NĂM ký tự — tên màn hình và tên owner đến từ dữ liệu người dùng. */
    function chu(s) {
        return String(s === null || s === undefined ? '' : s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    /** Số nguyên có dấu phân nhóm. Không làm tròn — máy chủ đã trả số nguyên. */
    function so(n) {
        return Number(n || 0).toLocaleString('vi-VN');
    }

    /** Màu theo tỉ lệ phát sóng. Ngưỡng là việc trình bày, nên nó ở client. */
    function mauTiLe(p) {
        if (Number(p) >= 80) { return 'var(--grn)'; }
        return Number(p) >= 50 ? 'var(--org)' : 'var(--red)';
    }

    // ── Gọi API ──────────────────────────────────────────────────────────────

    /**
     * 401 thì NÉM, không tải lại trang — xem lý do ở `buyer/cart.blade.php`:
     * một phiên web còn hiệu lực mà `Referer` bị tước cho ra trang 200 + API
     * 401 cùng lúc, nên nạp lại là vòng lặp không lối ra.
     */
    function goi() {
        return fetch(cf.api, {
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
            cache: 'no-store'
        }).then(function (r) {
            if (r.ok) {
                return r.json();
            }

            return r.json().catch(function () { return {}; }).then(function (body) {
                var loi = new Error(body.message || ('Máy chủ trả về mã ' + r.status));
                loi.ma = r.status;
                throw loi;
            });
        });
    }

    // ── Vẽ ───────────────────────────────────────────────────────────────────

    function veThongKe(o) {
        if (! oThongKe) { return; }

        oThongKe.innerHTML = ''
            + the(so(o.total_actual_impressions), 'Impressions thực tế',
                  '/ ' + so(o.total_estimated_impressions) + ' ước tính')
            + the(Number(o.delivery_rate) + '%', 'Delivery rate',
                  o.days_elapsed + '/' + o.days_total + ' ngày', mauTiLe(o.delivery_rate))
            + the(so(o.total_actual_cost), 'Chi phí thực tế (₫)',
                  '/ ' + so(o.total_estimated_cost) + ' ước tính')
            + the(so(o.total_screens), 'Màn hình',
                  o.days_remaining + ' ngày còn lại');
    }

    function the(n, l, sub, mau) {
        return '<div class="rpt-stat">'
            + '<div class="rpt-stat-n"' + (mau ? ' style="color:' + mau + '"' : '') + '>' + chu(n) + '</div>'
            + '<div class="rpt-stat-l">' + chu(l) + '</div>'
            + '<div class="rpt-stat-sub">' + chu(sub) + '</div>'
            + '</div>';
    }

    function veTienDo(o) {
        if (! oTienDo) { return; }

        var p = Number(o.progress_pct || 0);

        oTienDo.innerHTML = '<div class="rpt-card"><div class="rpt-progress-head">'
            + '<span>Tiến độ chiến dịch</span><span>' + p + '%</span>'
            + '</div><div class="rpt-progress"><div class="rpt-progress-fill" style="width:' + p + '%"></div></div></div>';
    }

    function veBang(ds) {
        if (! ds.length) {
            oBang.innerHTML = '<div style="color:var(--t3);font-size:13px;padding:24px 0">'
                + 'Chưa có màn hình nào trong báo cáo này.</div>';
            return;
        }

        var h = '<table class="rpt-table"><thead><tr>'
            + '<th>Màn hình</th><th>Owner</th><th>Thời gian</th>'
            + '<th class="rpt-num">Ước tính</th><th class="rpt-num">Thực tế</th>'
            + '<th class="rpt-num">Delivery</th><th class="rpt-num">Chi phí</th>'
            + '</tr></thead><tbody>';

        ds.forEach(function (d) {
            h += '<tr>'
               + '<td><strong>' + chu(d.screen_name) + '</strong>'
               + '<div style="font-size:11px;color:var(--t4)">' + chu(d.city) + '</div></td>'
               + '<td>' + chu(d.owner_name) + '</td>'
               + '<td>' + chu(d.dates) + '</td>'
               + '<td class="rpt-num">' + so(d.estimated_impressions) + '</td>'
               + '<td class="rpt-num">' + so(d.actual_impressions) + '</td>'
               + '<td class="rpt-num"><span style="color:' + mauTiLe(d.delivery_rate) + '">'
               + Number(d.delivery_rate) + '%</span></td>'
               + '<td class="rpt-num">' + so(d.actual_cost) + ' &#8363;</td>'
               + '</tr>';
        });

        oBang.innerHTML = h + '</tbody></table>';
    }

    /**
     * Biểu đồ vẽ CUỐI và chỉ khi có Chart.js.
     *
     * Thư viện đến từ CDN. Thiếu nó thì ô thống kê và bảng chi tiết vẫn phải
     * hiện — một CDN bị chặn không được biến trang báo cáo thành trang trắng.
     * Và vì khối script này là khối duy nhất, một `new Chart` nổ ở đây sẽ cắt
     * cả hai phần kia nếu không có chốt này.
     */
    function veBieuDo(ngay) {
        if (! oBieuDo) { return; }

        if (typeof window.Chart !== 'function') {
            // Ẩn canvas và chèn MỘT nút cạnh nó — không ghi
            // `parentNode.innerHTML`.
            //
            // Ghi vào thẻ cha là giả định rằng cha chỉ chứa canvas. Trên trang
            // thật thì đúng, nhưng giả định đó không đọc được từ chỗ này, và
            // bộ khung test (dựng mọi móc thành thẻ con của `body`) cho thấy
            // ngay hậu quả: nó xoá sạch ô thống kê và bảng chi tiết.
            oBieuDo.hidden = true;

            var tb = oBieuDo.ownerDocument.createElement('div');
            tb.setAttribute(
                'style',
                'color:var(--t4);font-size:13px;display:flex;align-items:center;'
                + 'justify-content:center;height:100%',
            );
            tb.textContent = 'Không tải được thư viện biểu đồ. '
                + 'Số liệu vẫn đầy đủ ở bảng bên dưới.';

            oBieuDo.parentNode.insertBefore(tb, oBieuDo.nextSibling);

            return;
        }

        new window.Chart(oBieuDo, {
            type: 'bar',
            data: {
                labels: ngay.labels,
                datasets: [{
                    label: 'Impressions',
                    data: ngay.impressions,
                    backgroundColor: 'rgba(42,79,246,.15)',
                    borderColor: 'rgba(42,79,246,.8)',
                    borderWidth: 1.5,
                    borderRadius: 4,
                    maxBarThickness: 32
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: function (c) {
                                return c.parsed.y.toLocaleString('vi-VN') + ' impressions';
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        grid: { color: 'rgba(0,0,0,.05)' },
                        ticks: {
                            callback: function (v) {
                                if (v >= 1000000) { return (v / 1000000).toFixed(1) + 'M'; }
                                if (v >= 1000) { return (v / 1000).toFixed(0) + 'K'; }
                                return v;
                            },
                            font: { size: 11 },
                            color: '#999'
                        }
                    },
                    x: {
                        grid: { display: false },
                        ticks: { font: { size: 11 }, color: '#999', maxRotation: 0 }
                    }
                }
            }
        });
    }

    function veLoi(thong) {
        oBang.innerHTML = '';
        if (oThongKe) { oThongKe.innerHTML = ''; }
        if (oTienDo) { oTienDo.innerHTML = ''; }
        if (oLoiMsg) { oLoiMsg.textContent = thong; }
        if (oLoi) { oLoi.hidden = false; }
    }

    // ── Chạy ─────────────────────────────────────────────────────────────────

    goi()
        .then(function (j) {
            var d = j.data || {};

            if (oLoi) { oLoi.hidden = true; }

            veThongKe(d.overview || {});
            veTienDo(d.overview || {});
            veBang(d.breakdown || []);
            veBieuDo(d.daily || { labels: [], impressions: [] });
        })
        .catch(function (e) {
            if (e.ma === 401) {
                veLoi('Phiên đăng nhập đã hết. Đăng nhập lại để xem báo cáo.');
                return;
            }
            if (e.ma === 429) {
                veLoi('Bạn gọi quá nhanh. Chờ một phút rồi tải lại trang.');
                return;
            }

            veLoi(e.message || 'Không tải được báo cáo. Thử tải lại trang.');
        });
})();
</script>
@endsection
