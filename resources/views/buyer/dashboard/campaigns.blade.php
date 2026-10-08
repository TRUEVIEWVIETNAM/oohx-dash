{{--
    Danh sách chiến dịch — đọc qua `/api/v2` từ TRÌNH DUYỆT.

    Bên tiêu thụ thứ tư của nhóm cần quyền. Lý do không gọi từ PHP vẫn như ba
    trang kia: `Http::get()` là một request giữ một worker LSAPI trong khi chờ
    một request khác cũng cần worker; gọi qua HTTP kernel trong cùng tiến trình
    thì phải dựng giả phiên + Sanctum cho request con, và `throttle` đếm đôi mỗi
    lần vẽ trang.

    ══ Phân trang chuyển sang tham số URL ══

    Bản cũ dùng `$campaigns->links()` của Laravel, tức phân trang do máy chủ vẽ
    kèm HTML. Nay trang đọc `?page=` từ URL, gọi API với đúng số trang đó, rồi
    vẽ lại thanh điều hướng — và **ghi số trang vào URL** bằng `pushState`.

    Vì sao ghi vào URL chứ không giữ trong biến: một danh sách mà không chia sẻ
    được link tới trang 3, và bấm Quay lại thì mất chỗ đang đứng, là một danh
    sách tệ hơn bản cũ. Chuyển sang đọc API không được làm mất thứ bản render
    phía máy chủ vốn có.

    ══ Bộ lọc do máy chủ hiểu ══

    `status` và `q` đi vào query của API. Danh sách trạng thái hợp lệ do
    `ListCampaignsRequest` quyết; trang không tự dựng lại danh sách đó — nó lấy
    từ chính phản hồi (`status_label`) và từ cấu hình máy chủ truyền xuống.

    ══ Hai cái bẫy của Blade ══

    1. Blade biên dịch chỉ thị TRƯỚC khi bỏ chú thích, nên không viết tên chỉ
       thị nào trong khối chú thích này.
    2. Blade cắt tham số chỉ thị tại dấu `)` đầu tiên, nên mảng cấu hình dựng ở
       khối PHP bên dưới rồi truyền vào bằng MỘT biến.
--}}
@extends('frontpage.layouts.app', ['activeNav' => 'dashboard', 'bodyClass' => ''])

@section('title', 'Chiến dịch | OOHX')

@section('content')
@php
    $cauHinhDS = [
        'api'       => url('/api/v2/campaigns'),
        'explore'   => url('/explore'),
        'xemBase'   => url('/my/campaigns'),
        'dangNhap'  => url('/login'),

        // Nhãn trạng thái từ MÁY CHỦ, cùng bảng chữ mà API trả trong
        // `status_label`. Dùng để dựng ô chọn bộ lọc — chỗ duy nhất trang cần
        // biết danh sách trạng thái trước khi có dữ liệu.
        'trangThai' => \App\Models\Campaign::STATUS_LABELS,
    ];
@endphp
<script type="application/json" data-ds-config>@json($cauHinhDS, JSON_HEX_TAG | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)</script>

<div class="w" style="padding-top:24px;padding-bottom:64px">
    <div class="buyer-welcome">
        <div><h1 class="buyer-welcome-title">Chiến dịch</h1></div>
        <a href="{{ url('/explore') }}" class="btn btn-p btn-sm">
            <svg viewBox="0 0 24 24" fill="#fff" style="width:14px;height:14px"><path d="M19 13h-6v6h-2v-6H5v-2h6V5h2v6h6v2z"/></svg>
            Tạo mới
        </a>
    </div>

    <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:16px">
        <input type="search" data-ds-tim placeholder="Tìm theo tên hoặc mã…" maxlength="100"
               style="flex:1;min-width:200px;height:36px;padding:0 12px;border:1px solid var(--bd);border-radius:10px;background:var(--bg);color:var(--t1);font-size:13px">
        <select data-ds-trangthai
                style="height:36px;padding:0 10px;border:1px solid var(--bd);border-radius:10px;background:var(--bg);color:var(--t1);font-size:13px"></select>
    </div>

    <div data-ds-loi hidden>
        <div class="cart-alert" style="border-color:var(--red);color:var(--red)">
            <span data-ds-loi-msg></span>
            <a href="{{ url('/login') }}" style="margin-left:8px">Đăng nhập lại</a>
        </div>
    </div>

    <div data-ds-than>
        <div style="color:var(--t3);font-size:13px;padding:24px 0">Đang tải danh sách chiến dịch…</div>
    </div>

    <div style="margin-top:20px" data-ds-trang></div>
</div>

<script>
(function () {
    'use strict';

    var oCauHinh = document.querySelector('[data-ds-config]');
    var than     = document.querySelector('[data-ds-than]');

    if (! oCauHinh || ! than) {
        return;
    }

    var cf        = JSON.parse(oCauHinh.textContent);
    var oTrang    = document.querySelector('[data-ds-trang]');
    var oTim      = document.querySelector('[data-ds-tim]');
    var oTrangThai = document.querySelector('[data-ds-trangthai]');
    var oLoi      = document.querySelector('[data-ds-loi]');
    var oLoiMsg   = document.querySelector('[data-ds-loi-msg]');

    // Màu do client sở hữu — việc trình bày, đổi theo chủ đề. CHỮ do máy chủ
    // sở hữu (`status_label`), vì đó là việc nghiệp vụ.
    var MAU = {
        draft: 'b-gray', pending_approval: 'b-org', approved: 'b-bl', rejected: 'b-red',
        active: 'b-grn', paused: 'b-org', completed: 'b-gray', cancelled: 'b-red'
    };

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

    /** Đọc trạng thái hiện tại từ URL, để F5 và link chia sẻ giữ đúng chỗ. */
    function tuUrl() {
        var u = new URL(window.location.href);

        return {
            page: Math.max(1, parseInt(u.searchParams.get('page') || '1', 10) || 1),
            status: u.searchParams.get('status') || '',
            q: u.searchParams.get('q') || ''
        };
    }

    function ghiUrl(t, thayVi) {
        var u = new URL(window.location.href);

        // Chỉ ghi tham số có giá trị: `?page=1&status=&q=` là một URL dài hơn
        // mà không nói thêm gì, và nó làm link chia sẻ trông như có bộ lọc.
        ['page', 'status', 'q'].forEach(function (k) {
            var v = t[k];
            if (v && ! (k === 'page' && Number(v) === 1)) {
                u.searchParams.set(k, String(v));
            } else {
                u.searchParams.delete(k);
            }
        });

        // `replaceState` cho lần đầu và cho popstate, `pushState` cho các lần
        // người dùng tự đổi. Lần đầu chỉ là chuẩn hoá URL hiện tại, không phải
        // một bước điều hướng — `pushState` ở đó khiến Quay lại trả về **đúng
        // trang đang đứng**, tức nút Quay lại trông như bị kẹt.
        try {
            window.history[thayVi ? 'replaceState' : 'pushState']({}, '', u.toString());
        } catch (e) { /* trình duyệt chặn thì vẫn chạy, chỉ mất chia sẻ link */ }
    }

    // ── Gọi API ──────────────────────────────────────────────────────────────

    /**
     * 401 thì NÉM, không tải lại trang.
     *
     * Trang dùng guard `web`; `/api/v2` dùng `auth:sanctum` sau
     * `EnsureFrontendRequestsAreStateful`, và lớp đó chỉ vào việc khi
     * `Referer`/`Origin` khớp `sanctum.stateful`. Một phiên web còn hiệu lực mà
     * `Referer` bị tước cho ra trang 200 + API 401 cùng lúc.
     */
    function goi(t) {
        var u = new URL(cf.api);
        u.searchParams.set('page', String(t.page));
        if (t.status) { u.searchParams.set('status', t.status); }
        if (t.q) { u.searchParams.set('q', t.q); }

        return fetch(u.toString(), {
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
                loi.chiTiet = body.details || [];
                throw loi;
            });
        });
    }

    // ── Vẽ ───────────────────────────────────────────────────────────────────

    /**
     * Dựng ô chọn một lần, nhưng **đồng bộ giá trị mỗi lần**.
     *
     * Hai việc khác nhau, và gộp chúng là một lỗi: dựng lại `<option>` mỗi lần
     * là vô ích, nhưng không đồng bộ giá trị thì sau một `popstate` hoặc sau
     * khi bấm "Xóa bộ lọc", ô chọn và ô tìm vẫn giữ chữ cũ trong khi kết quả
     * đã đổi — giao diện nói một đằng, dữ liệu một nẻo.
     */
    function veBoLoc(t) {
        if (oTrangThai && ! oTrangThai.options.length) {
            var h = '<option value="">Mọi trạng thái</option>';

            // Danh sách trạng thái từ máy chủ, không chép cứng ở đây: thêm một
            // trạng thái mới là ô chọn tự có, không phải sửa hai chỗ.
            Object.keys(cf.trangThai || {}).forEach(function (ma) {
                h += '<option value="' + chu(ma) + '">' + chu(cf.trangThai[ma]) + '</option>';
            });

            oTrangThai.innerHTML = h;
        }

        if (oTrangThai) { oTrangThai.value = t.status || ''; }

        // Không ghi đè trong lúc người dùng đang gõ: ô tìm gọi `tai()` sau
        // 400ms, và lúc đó con trỏ vẫn ở trong ô.
        if (oTim && document.activeElement !== oTim) { oTim.value = t.q || ''; }
    }

    function veTrong(t) {
        // Hai câu khác nhau cho hai tình huống khác nhau. "Chưa có campaign
        // nào" khi đang lọc là nói sai: họ có campaign, chỉ không có cái nào
        // khớp bộ lọc — và câu sai đó dẫn họ đi tạo cái mới thay vì xoá lọc.
        if (t.status || t.q) {
            return '<div class="buyer-empty">'
                + '<div style="font-size:15px;font-weight:600;color:var(--t2);margin-bottom:16px">'
                + 'Không có campaign nào khớp bộ lọc</div>'
                + '<button type="button" class="btn btn-s btn-sm" data-ds-xoaloc>Xóa bộ lọc</button>'
                + '</div>';
        }

        return '<div class="buyer-empty">'
            + '<div style="font-size:15px;font-weight:600;color:var(--t2);margin-bottom:16px">'
            + 'Chưa có campaign nào</div>'
            + '<a href="' + chu(cf.explore) + '" class="btn btn-p btn-sm">Khám phá Inventory</a>'
            + '</div>';
    }

    function veHang(c) {
        var p = c.period || {};
        var t = c.totals || {};

        return '<a href="' + chu(cf.xemBase + '/' + c.id) + '" class="buyer-campaign-row"'
            + ' style="text-decoration:none;color:inherit">'
            + '<div style="flex:1;min-width:0">'
            + '<div style="font-size:14px;font-weight:700;color:var(--t1)">' + chu(c.name) + '</div>'
            + '<div style="font-size:12px;color:var(--t4);margin-top:2px">'
            + chu(c.code) + ' &middot; ' + chu(ngay(p.start_date)) + ' → ' + chu(ngay(p.end_date))
            + ' &middot; ' + (t.screens || 0) + ' screens</div>'
            + '</div>'
            // Nhãn chữ từ máy chủ; màu từ bảng trên.
            + '<span class="badge ' + (MAU[c.status] || 'b-gray') + '">'
            + chu(c.status_label || c.status) + '</span>'
            + '</a>';
    }

    /**
     * Thanh điều hướng trang.
     *
     * Chỉ hiện khi có nhiều hơn một trang. Một thanh phân trang trên một danh
     * sách một trang là nhiễu, và bản cũ của Laravel cũng không vẽ nó.
     */
    function veThanhTrang(meta, t) {
        if (! oTrang) { return; }

        var cuoi = Number(meta.last_page) || 1;

        if (cuoi <= 1) {
            oTrang.innerHTML = '';
            return;
        }

        var hienTai = Number(meta.page) || 1;

        oTrang.innerHTML = '<div style="display:flex;align-items:center;gap:10px;font-size:13px">'
            + '<button type="button" class="btn btn-s btn-sm" data-ds-lui'
            + (hienTai <= 1 ? ' disabled' : '') + '>Trước</button>'
            + '<span style="color:var(--t3)">Trang ' + hienTai + ' / ' + cuoi
            + ' &middot; ' + (Number(meta.total) || 0) + ' campaign</span>'
            + '<button type="button" class="btn btn-s btn-sm" data-ds-tien'
            + (hienTai >= cuoi ? ' disabled' : '') + '>Sau</button>'
            + '</div>';
    }

    function ve(j, t) {
        var ds   = j.data || [];
        var meta = j.meta || {};

        if (oLoi) { oLoi.hidden = true; }

        than.innerHTML = ds.length
            ? '<div class="buyer-campaign-list">' + ds.map(veHang).join('') + '</div>'
            : veTrong(t);

        veThanhTrang(meta, t);
        gan(t);
    }

    function veLoi(thong) {
        than.innerHTML = '';
        if (oTrang) { oTrang.innerHTML = ''; }
        if (oLoiMsg) { oLoiMsg.textContent = thong; }
        if (oLoi) { oLoi.hidden = false; }
    }

    // ── Gắn sự kiện ──────────────────────────────────────────────────────────

    function gan(t) {
        var lui  = oTrang ? oTrang.querySelector('[data-ds-lui]') : null;
        var tien = oTrang ? oTrang.querySelector('[data-ds-tien]') : null;

        if (lui) {
            lui.addEventListener('click', function () { tai({ page: t.page - 1, status: t.status, q: t.q }); });
        }
        if (tien) {
            tien.addEventListener('click', function () { tai({ page: t.page + 1, status: t.status, q: t.q }); });
        }

        var xoa = than.querySelector('[data-ds-xoaloc]');
        if (xoa) {
            xoa.addEventListener('click', function () { tai({ page: 1, status: '', q: '' }); });
        }
    }

    // ── Chạy ─────────────────────────────────────────────────────────────────

    function tai(t, thayViUrl) {
        veBoLoc(t);
        ghiUrl(t, thayViUrl === true);

        than.innerHTML = '<div style="color:var(--t3);font-size:13px;padding:24px 0">Đang tải…</div>';

        goi(t)
            .then(function (j) { ve(j, t); })
            .catch(function (e) {
                if (e.ma === 401) {
                    veLoi('Phiên đăng nhập đã hết. Đăng nhập lại để xem danh sách chiến dịch.');
                    return;
                }
                if (e.ma === 429) {
                    veLoi('Bạn gọi quá nhanh. Chờ một phút rồi tải lại trang.');
                    return;
                }

                veLoi(e.message || 'Không tải được danh sách chiến dịch. Thử tải lại trang.');
            });
    }

    var batDau = tuUrl();

    if (oTrangThai) {
        oTrangThai.addEventListener('change', function () {
            // Đổi bộ lọc thì về trang 1: giữ `page=5` khi kết quả chỉ còn 2
            // trang là hiện một trang rỗng và trông như mất dữ liệu.
            tai({ page: 1, status: oTrangThai.value, q: oTim ? oTim.value.trim() : '' });
        });
    }

    if (oTim) {
        // Gõ xong rồi mới gọi — chờ 400ms sau lần gõ cuối. Gọi mỗi lần nhấn
        // phím là một yêu cầu cho mỗi chữ cái, và hạn mức tần suất sẽ chặn
        // đúng người đang gõ nhanh nhất.
        var hen = null;

        oTim.addEventListener('input', function () {
            clearTimeout(hen);
            hen = setTimeout(function () {
                tai({ page: 1, status: oTrangThai ? oTrangThai.value : '', q: oTim.value.trim() });
            }, 400);
        });
    }

    // Bấm Quay lại/Tiến của trình duyệt phải đi đúng về trang đã xem. `true` ở
    // đây là `replaceState`: trình duyệt vừa điều hướng rồi, thêm một bước nữa
    // là làm lịch sử dài gấp đôi.
    window.addEventListener('popstate', function () { tai(tuUrl(), true); });

    // Lần đầu: `replaceState`, chỉ chuẩn hoá URL hiện tại.
    tai(batDau, true);
})();
</script>
@endsection
