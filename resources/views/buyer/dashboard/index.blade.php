{{--
    Trang đầu khu người mua — đọc qua `/api/v2` từ TRÌNH DUYỆT.

    Bên tiêu thụ thứ năm của nhóm cần quyền. Lý do không gọi từ PHP vẫn như bốn
    trang kia: `Http::get()` là một request giữ một worker LSAPI trong khi chờ
    một request khác cũng cần worker; gọi qua HTTP kernel trong cùng tiến trình
    thì phải dựng giả phiên + Sanctum cho request con, và `throttle` đếm đôi mỗi
    lần vẽ trang.

    ══ Lần chuyển này đóng một lỗ hổng phạm vi, không chỉ đổi nguồn dữ liệu ══

    Bản cũ đếm bằng `$org->campaigns()` với `$org = $user->currentOrganization`,
    và `currentOrganization` là một `belongsTo` thuần trên
    `current_organization_id` — không kiểm tư cách thành viên. Người bị gỡ khỏi
    tổ chức, mà cột đó vẫn trỏ ở đó, vẫn thấy số đếm và năm chiến dịch gần nhất
    của tổ chức ấy kèm tên, mã, kỳ chạy. Khu quản trị tổ chức xoá được thành
    viên và không chỗ nào dọn cột đó, nên đây là tình huống có thật.

    Nay cả hai khối đi qua `CampaignService::tuCachXemChienDich()` — cùng cổng
    mà `/my/campaigns` dùng từ PR #40.

    ══ Hai lời gọi, không một ══

    Số đếm là toàn bộ; danh sách là năm bản ghi mới nhất. Hai câu hỏi khác nhau
    về cùng một tập, nên hai đường: `campaigns/summary` và `campaigns?per_page=5`.
    Nhét số đếm toàn bộ vào `meta` của một danh sách đã lọc là đặt hai phạm vi
    cạnh nhau trong một phản hồi.

    ══ Hai cái bẫy của Blade ══

    1. Blade biên dịch chỉ thị TRƯỚC khi bỏ chú thích, nên không viết tên chỉ
       thị nào trong khối chú thích này.
    2. Blade cắt tham số chỉ thị tại dấu `)` đầu tiên, nên mảng cấu hình dựng ở
       khối PHP bên dưới rồi truyền vào bằng MỘT biến.
--}}
@extends('frontpage.layouts.app', ['activeNav' => 'dashboard', 'bodyClass' => ''])

@section('title', 'Dashboard | OOHX')

@section('content')
@php
    $cauHinhTD = [
        'apiTomTat'   => url('/api/v2/campaigns/summary'),
        'apiDanhSach' => url('/api/v2/campaigns'),
        'explore'     => url('/explore'),
        'xemBase'     => url('/my/campaigns'),

        // Số chiến dịch gần đây. Bản cũ là `->take(5)` trong controller; nay nó
        // là `per_page` của một endpoint có giới hạn cứng, nên một số vô lý ở
        // đây bị máy chủ kẹp lại chứ không thành một truy vấn nặng.
        'soGanDay'    => 5,

        // Ô thống kê nào hiện, theo thứ tự. CHỈ mã — chữ đến từ phản hồi
        // (`by_status[].label`), vì chữ là việc nghiệp vụ và nó đã có một bảng.
        //
        // Ba ô chứ không tám: tám con số là một bảng, không phải một chỉ dấu.
        // Đổi tập này là sửa một dòng, không phải sửa markup.
        'oThongKe'    => [
            \App\Models\Campaign::STATUS_DRAFT,
            \App\Models\Campaign::STATUS_PENDING,
            \App\Models\Campaign::STATUS_ACTIVE,
        ],
    ];
@endphp
<script type="application/json" data-td-config>@json($cauHinhTD, JSON_HEX_TAG | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)</script>

<div class="w" style="padding-top:24px;padding-bottom:64px">

    {{-- Welcome header --}}
    {{--
        Khối này vẫn do máy chủ vẽ, có chủ ý. Tên người và tên tổ chức không
        phải dữ liệu chiến dịch: thanh điều hướng trên MỌI trang đã in tên tổ
        chức theo đúng cách này, nên chuyển chỗ đây không đóng thêm lỗ hổng
        nào — mà lại phải nới `MeResource`, cái mà mọi trang gọi, để lấy thêm
        `type` cho đúng một dòng ở đúng một trang.
    --}}
    <div class="buyer-welcome">
        <div>
            <h1 class="buyer-welcome-title">Xin chào, {{ auth()->user()->name }}</h1>
            <p class="buyer-welcome-sub">{{ $org->name }} &middot; {{ ucfirst($org->type) }}</p>
        </div>
        <a href="{{ url('/explore') }}" class="btn btn-p btn-sm">
            <svg viewBox="0 0 24 24" fill="#fff" style="width:14px;height:14px;flex-shrink:0"><path d="M19 13h-6v6h-2v-6H5v-2h6V5h2v6h6v2z"/></svg>
            Tạo Campaign
        </a>
    </div>

    <div data-td-loi hidden>
        <div class="cart-alert" style="border-color:var(--red);color:var(--red)">
            <span data-td-loi-msg></span>
            <a href="{{ url('/login') }}" style="margin-left:8px">Đăng nhập lại</a>
        </div>
    </div>

    {{-- Stats cards --}}
    <div class="buyer-stats" data-td-thongke></div>

    {{-- Recent campaigns --}}
    <div class="buyer-section">
        <div class="buyer-section-head">
            <h2 class="buyer-section-title">Campaign gần đây</h2>
        </div>
        <div data-td-than>
            <div style="color:var(--t3);font-size:13px;padding:24px 0">Đang tải…</div>
        </div>
    </div>

    {{-- Quick links --}}
    <div class="buyer-quick">
        <a href="{{ url('/explore') }}" class="buyer-quick-card">
            <svg viewBox="0 0 24 24" fill="var(--bl)" style="width:24px;height:24px"><path d="M15.5 14h-.79l-.28-.27A6.47 6.47 0 0 0 16 9.5 6.5 6.5 0 1 0 9.5 16c1.61 0 3.09-.59 4.23-1.57l.27.28v.79l5 4.99L20.49 19l-4.99-5zm-6 0C7.01 14 5 11.99 5 9.5S7.01 5 9.5 5 14 7.01 14 9.5 11.99 14 9.5 14z"/></svg>
            <div class="buyer-quick-title">Khám phá Inventory</div>
            <div class="buyer-quick-sub">Tìm kiếm biển quảng cáo</div>
        </a>
        <a href="{{ url('/map') }}" class="buyer-quick-card">
            <svg viewBox="0 0 24 24" fill="var(--grn)" style="width:24px;height:24px"><path d="M20.5 3l-.16.03L15 5.1 9 3 3.36 4.9c-.21.07-.36.25-.36.48V20.5c0 .28.22.5.5.5l.16-.03L9 18.9l6 2.1 5.64-1.9c.21-.07.36-.25.36-.48V3.5c0-.28-.22-.5-.5-.5zM15 19l-6-2.11V5l6 2.11V19z"/></svg>
            <div class="buyer-quick-title">Bản đồ</div>
            <div class="buyer-quick-sub">Xem vị trí trên bản đồ</div>
        </a>
        <a href="{{ url('/owners') }}" class="buyer-quick-card">
            <svg viewBox="0 0 24 24" fill="var(--org)" style="width:24px;height:24px"><path d="M16 11c1.66 0 2.99-1.34 2.99-3S17.66 5 16 5c-1.66 0-3 1.34-3 3s1.34 3 3 3zm-8 0c1.66 0 2.99-1.34 2.99-3S9.66 5 8 5C6.34 5 5 6.34 5 8s1.34 3 3 3zm0 2c-2.33 0-7 1.17-7 3.5V19h14v-2.5c0-2.33-4.67-3.5-7-3.5zm8 0c-.29 0-.62.02-.97.05 1.16.84 1.97 1.97 1.97 3.45V19h6v-2.5c0-2.33-4.67-3.5-7-3.5z"/></svg>
            <div class="buyer-quick-title">Media Owners</div>
            <div class="buyer-quick-sub">Xem danh sách đối tác</div>
        </a>
    </div>
</div>

<script>
(function () {
    'use strict';

    var oCauHinh = document.querySelector('[data-td-config]');
    var than     = document.querySelector('[data-td-than]');

    if (! oCauHinh || ! than) {
        return;
    }

    var cf        = JSON.parse(oCauHinh.textContent);
    var oThongKe  = document.querySelector('[data-td-thongke]');
    var oLoi      = document.querySelector('[data-td-loi]');
    var oLoiMsg   = document.querySelector('[data-td-loi-msg]');

    // Màu do client sở hữu — việc trình bày, đổi theo chủ đề. CHỮ do máy chủ
    // sở hữu (`status_label` và `by_status[].label`), vì đó là việc nghiệp vụ.
    //
    // Bảng này giống `MAU` ở `campaigns.blade.php`: ba trang chiến dịch của khu
    // người mua tô cùng một quy ước.
    var MAU = {
        draft: 'b-gray', pending_approval: 'b-org', approved: 'b-bl', rejected: 'b-red',
        active: 'b-grn', paused: 'b-org', completed: 'b-gray', cancelled: 'b-red'
    };

    /** Màu CHỮ SỐ của ô thống kê — khác màu thẻ, nên là bảng riêng. */
    var MAU_SO = { pending_approval: 'var(--org)', active: 'var(--grn)' };

    // ── Giúp việc ────────────────────────────────────────────────────────────

    /** Thoát đủ NĂM ký tự — tên chiến dịch do người dùng tự gõ. */
    function chu(s) {
        return String(s === null || s === undefined ? '' : s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    /**
     * `YYYY-MM-DD` → `DD/MM/YYYY` bằng cách cắt chuỗi, KHÔNG qua `new Date()`.
     *
     * `new Date('2026-10-08')` đọc là nửa đêm UTC, nên ở múi giờ âm nó lùi một
     * ngày. Kỳ chạy là ngày theo lịch, không phải một mốc thời gian.
     */
    function ngay(s) {
        if (! s) { return '—'; }
        var p = String(s).split('-');
        return p.length === 3 ? p[2] + '/' + p[1] + '/' + p[0] : String(s);
    }

    // ── Gọi API ──────────────────────────────────────────────────────────────

    /**
     * 401 thì NÉM, không tải lại trang.
     *
     * Trang dùng guard `web`; `/api/v2` dùng `auth:sanctum` sau
     * `EnsureFrontendRequestsAreStateful`, và lớp đó chỉ vào việc khi
     * `Referer`/`Origin` khớp `sanctum.stateful`. Một phiên web còn hiệu lực mà
     * `Referer` bị tước cho ra trang 200 + API 401 cùng lúc — nạp lại là một
     * vòng lặp không lối ra.
     */
    function goi(u) {
        return fetch(u, {
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

    /**
     * Ô thống kê: số và chữ đều từ phản hồi.
     *
     * Mã nào cấu hình đòi mà phản hồi không có thì hiện `—` kèm chính mã đó,
     * **không** bỏ qua im lặng: phản hồi luôn trả đủ tám mã, nên thiếu một mã
     * nghĩa là máy chủ đã đổi — và một ô biến mất là thứ không ai để ý.
     */
    function veThongKe(j) {
        if (! oThongKe) { return; }

        var ds  = (j.data && j.data.by_status) || [];
        var tra = {};

        ds.forEach(function (m) { tra[m.status] = m; });

        var h = '';

        (cf.oThongKe || []).forEach(function (ma) {
            var m = tra[ma];
            var mau = MAU_SO[ma] ? ' style="color:' + MAU_SO[ma] + '"' : '';

            h += '<div class="buyer-stat">'
                + '<div class="buyer-stat-n"' + mau + '>' + (m ? Number(m.count) : '—') + '</div>'
                + '<div class="buyer-stat-l">' + chu(m ? m.label : ma) + '</div>'
                + '</div>';
        });

        h += '<div class="buyer-stat">'
            + '<div class="buyer-stat-n">' + (Number((j.data && j.data.total) || 0)) + '</div>'
            + '<div class="buyer-stat-l">Tổng cộng</div>'
            + '</div>';

        oThongKe.innerHTML = h;
    }

    function veHang(c) {
        var p = c.period || {};

        return '<a href="' + chu(cf.xemBase + '/' + c.id) + '" class="buyer-campaign-row"'
            + ' style="text-decoration:none;color:inherit">'
            + '<div style="flex:1;min-width:0">'
            + '<div style="font-size:14px;font-weight:700;color:var(--t1)">' + chu(c.name) + '</div>'
            + '<div style="font-size:12px;color:var(--t4);margin-top:2px">'
            + chu(c.code) + ' &middot; ' + chu(ngay(p.start_date)) + ' → ' + chu(ngay(p.end_date))
            + '</div>'
            + '</div>'
            // Nhãn chữ từ máy chủ; màu từ bảng trên.
            + '<span class="badge ' + (MAU[c.status] || 'b-gray') + '">'
            + chu(c.status_label || c.status) + '</span>'
            + '</a>';
    }

    function veDanhSach(j) {
        var ds = j.data || [];

        than.innerHTML = ds.length
            ? '<div class="buyer-campaign-list">' + ds.map(veHang).join('') + '</div>'
            : '<div class="buyer-empty">'
                + '<svg viewBox="0 0 24 24" fill="var(--t4)" style="width:48px;height:48px;margin-bottom:12px">'
                + '<path d="M14 2H6c-1.1 0-1.99.9-1.99 2L4 20c0 1.1.89 2 1.99 2H18c1.1 0 2-.9 2-2V8l-6-6zm2 16H8v-2h8v2zm0-4H8v-2h8v2zm-3-5V3.5L18.5 9H13z"/></svg>'
                + '<div style="font-size:15px;font-weight:600;color:var(--t2);margin-bottom:4px">Chưa có campaign nào</div>'
                + '<div style="font-size:13px;color:var(--t4);margin-bottom:16px">'
                + 'Bắt đầu bằng cách khám phá inventory và tạo campaign đầu tiên</div>'
                + '<a href="' + chu(cf.explore) + '" class="btn btn-p btn-sm">Khám phá Inventory</a>'
                + '</div>';
    }

    function veLoi(thong) {
        than.innerHTML = '';
        if (oThongKe) { oThongKe.innerHTML = ''; }
        if (oLoiMsg) { oLoiMsg.textContent = thong; }
        if (oLoi) { oLoi.hidden = false; }
    }

    // ── Chạy ─────────────────────────────────────────────────────────────────

    /**
     * Hai lời gọi song song, nhưng **một** thông báo lỗi.
     *
     * `Promise.all` chứ không hai `then` rời: hai khối của cùng một trang, nên
     * một khối hỏng mà khối kia vẫn vẽ là một trang nói nửa sự thật — người
     * dùng thấy ba con số và không biết danh sách bên dưới đã không tải được.
     */
    function tai() {
        var u = new URL(cf.apiDanhSach);
        u.searchParams.set('per_page', String(cf.soGanDay || 5));

        Promise.all([goi(cf.apiTomTat), goi(u.toString())])
            .then(function (kq) {
                if (oLoi) { oLoi.hidden = true; }
                veThongKe(kq[0]);
                veDanhSach(kq[1]);
            })
            .catch(function (e) {
                if (e.ma === 401) {
                    veLoi('Phiên đăng nhập đã hết. Đăng nhập lại để xem chiến dịch của bạn.');
                    return;
                }
                if (e.ma === 429) {
                    veLoi('Bạn gọi quá nhanh. Chờ một phút rồi tải lại trang.');
                    return;
                }

                veLoi(e.message || 'Không tải được dữ liệu trang. Thử tải lại trang.');
            });
    }

    tai();
})();
</script>
@endsection
