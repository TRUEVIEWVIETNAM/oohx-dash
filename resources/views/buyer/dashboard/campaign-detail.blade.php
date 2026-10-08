{{--
    Chi tiết chiến dịch — đọc qua `/api/v2` từ TRÌNH DUYỆT.

    Bên tiêu thụ thứ ba của nhóm cần quyền, sau trang giỏ và trang thanh toán,
    và là **action đọc cuối cùng** của khu người mua còn dựng dữ liệu từ model.
    Lý do không gọi từ PHP vẫn như hai trang kia: `Http::get()` là một request
    giữ một worker LSAPI trong khi chờ một request khác cũng cần worker; gọi qua
    HTTP kernel trong cùng tiến trình thì phải dựng giả phiên + Sanctum cho
    request con, và `throttle` đếm đôi mỗi lần vẽ trang.

    ══ Một đường, không hai ══

    `GET /api/v2/campaigns/{campaign}` trả đủ: chiến dịch, dòng đặt chỗ, số
    liệu, báo giá hoàn tiền, chính sách hủy, owner còn đánh giá được, đánh giá
    đã viết, và lịch sử hoạt động. Khác trang thanh toán — nơi phải tách hai
    đường vì quyền khác nhau — ở đây mọi thứ đều sau cùng một quyền `view`, trừ
    `cancel_quotes` mà máy chủ tự trả rỗng khi thiếu quyền `cancel`.

    ══ Ba đường GHI vẫn ở Blade ══

    Hủy dòng đặt chỗ và gửi đánh giá vẫn POST về route Blade như cũ: CSRF, phân
    quyền, chuyển hướng kèm thông báo — đường đã chạy và đã có test. Chuyển một
    đường ghi có hệ quả tiền sang `fetch` là việc riêng.

    ══ Hai cái bẫy của Blade ══

    1. Blade biên dịch chỉ thị TRƯỚC khi bỏ chú thích, nên một chỉ thị viết
       trong khối chú thích này vẫn được biên dịch thành PHP. Không viết tên
       chỉ thị nào ở đây.
    2. Blade cắt tham số chỉ thị tại dấu `)` đầu tiên, nên mảng cấu hình dựng ở
       khối PHP bên dưới rồi truyền vào bằng MỘT biến.
--}}
@extends('frontpage.layouts.app', ['activeNav' => 'dashboard', 'bodyClass' => ''])

@section('title', $campaign->name . ' | OOHX')

@section('content')
@php
    $cauHinhChiTiet = [
        'api'        => url('/api/v2/campaigns/' . $campaign->id),
        'duongHuy'   => url('/my/campaigns/' . $campaign->id . '/lines'),
        'duongDanhGia' => route('buyer.campaigns.reviews.store', $campaign),
        'duongBaoCao'  => route('buyer.campaigns.report', $campaign),
        'duongThanhToan' => route('buyer.payment', $campaign),
        'duongTatCa' => route('buyer.campaigns'),
        'dangNhap'   => url('/login'),
        'csrf'       => csrf_token(),
    ];
@endphp
<script type="application/json" data-cd-config>@json($cauHinhChiTiet, JSON_HEX_TAG | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)</script>

<div class="w" style="padding-top:24px;padding-bottom:64px">

    @if(session('success'))
    <div class="cart-alert">{{ session('success') }}</div>
    @endif

    @if(session('error'))
    <div class="cart-alert" style="border-color:var(--red);color:var(--red)">{{ session('error') }}</div>
    @endif

    @error('rating')
    <div class="cart-alert" style="border-color:var(--red);color:var(--red)">{{ $message }}</div>
    @enderror

    <div class="buyer-welcome">
        <div>
            <div style="font-size:12px;color:var(--t4);font-weight:600;margin-bottom:4px">{{ $campaign->code }}</div>
            <h1 class="buyer-welcome-title">{{ $campaign->name }}</h1>
            <div style="display:flex;align-items:center;gap:8px;margin-top:6px" data-cd-trangthai></div>
        </div>
        <div style="display:flex;gap:8px" data-cd-nut>
            <a href="{{ route('buyer.campaigns') }}" class="btn btn-s btn-sm">Tất cả campaigns</a>
        </div>
    </div>

    <div data-cd-loi hidden>
        <div class="cart-alert" style="border-color:var(--red);color:var(--red)">
            <span data-cd-loi-msg></span>
            <a href="{{ url('/login') }}" style="margin-left:8px">Đăng nhập lại</a>
        </div>
    </div>

    <div data-cd-than>
        <div style="color:var(--t3);font-size:13px;padding:24px 0">Đang tải chi tiết chiến dịch…</div>
    </div>
</div>

<script>
(function () {
    'use strict';

    var oCauHinh = document.querySelector('[data-cd-config]');
    var than     = document.querySelector('[data-cd-than]');

    if (! oCauHinh || ! than) {
        return;
    }

    var cf       = JSON.parse(oCauHinh.textContent);
    var oTrangThai = document.querySelector('[data-cd-trangthai]');
    var oNut     = document.querySelector('[data-cd-nut]');
    var oLoi     = document.querySelector('[data-cd-loi]');
    var oLoiMsg  = document.querySelector('[data-cd-loi-msg]');

    var dinhDang = new Intl.NumberFormat('vi-VN');

    // Chỉ MÀU ở client. Chữ đến từ máy chủ (`status_label`), vì hai enum khác
    // nhau — chiến dịch dùng `pending_approval`, dòng đặt chỗ dùng `pending` —
    // và một bảng chữ dùng cho cả hai làm dòng `pending` hiện ra nguyên văn
    // tiếng Anh. Bảng màu dưới đây phủ cả hai mã, và màu thì trùng nhau được
    // vì nó chỉ nói "việc này đang chờ".
    var MAU_TRANG_THAI = {
        draft: 'b-gray', pending: 'b-org', pending_approval: 'b-org', approved: 'b-bl',
        rejected: 'b-red', active: 'b-grn', paused: 'b-org', completed: 'b-gray',
        cancelled: 'b-red'
    };

    // ── Giúp việc ────────────────────────────────────────────────────────────

    /**
     * Thoát đủ NĂM ký tự, không phải ba.
     *
     * Tên màn hình vào CSDL qua `ScreenImport` từ tệp CSV của media owner; tên
     * owner và nhận xét đánh giá do người dùng tự gõ. Cả ba đi vào thân HTML
     * lẫn giá trị thuộc tính, và cách quen tay — gán `textContent` rồi đọc
     * `innerHTML` — KHÔNG thoát dấu nháy kép.
     */
    function chu(s) {
        return String(s === null || s === undefined ? '' : s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function tien(n) {
        return dinhDang.format(Number(n) || 0);
    }

    /**
     * `YYYY-MM-DD` → `DD/MM/YYYY` bằng cách cắt chuỗi, KHÔNG qua `new Date()`.
     *
     * `new Date('2026-10-08')` đọc là nửa đêm UTC, nên ở múi giờ âm nó lùi một
     * ngày. Ngày chạy của chiến dịch là ngày theo lịch, không phải một mốc thời
     * gian, nên không được đi qua múi giờ nào.
     */
    function ngay(s) {
        if (! s) { return '—'; }
        var p = String(s).split('-');
        return p.length === 3 ? p[2] + '/' + p[1] + '/' + p[0] : String(s);
    }

    /** `DD/MM` — dùng cho dòng đặt chỗ, nơi năm là dư thừa. */
    function ngayNgan(s) {
        if (! s) { return '—'; }
        var p = String(s).split('-');
        return p.length === 3 ? p[2] + '/' + p[1] : String(s);
    }

    /**
     * Mốc thời gian có giờ — hiện theo giờ Việt Nam, cố định.
     *
     * `app.timezone` là `UTC`, nên bản render cũ hiện giờ UTC, lệch 7 tiếng so
     * với lúc việc thật xảy ra. Vẽ phía client buộc phải chọn một múi giờ —
     * không có lựa chọn "để nguyên" — nên chọn đúng, và cố định chứ không theo
     * máy người xem, để hai người ở hai nước nói về cùng một con số.
     *
     * Hai bộ định dạng chứ không một: một bộ khai cả ngày lẫn giờ thì `vi-VN`
     * trả về giờ TRƯỚC ngày, và thứ tự do locale quyết là thứ tự mình không
     * kiểm được.
     */
    function gio(s) {
        if (! s) { return ''; }
        var d = new Date(s);
        if (isNaN(d.getTime())) { return ''; }
        try {
            var p = { timeZone: 'Asia/Ho_Chi_Minh' };
            var n = new Intl.DateTimeFormat('vi-VN', Object.assign({
                day: '2-digit', month: '2-digit', year: 'numeric'
            }, p)).format(d);
            var g = new Intl.DateTimeFormat('vi-VN', Object.assign({
                hour: '2-digit', minute: '2-digit', hour12: false
            }, p)).format(d);

            return n + ' ' + g;
        } catch (e) {
            return d.toISOString().slice(0, 16).replace('T', ' ');
        }
    }

    /** Thẻ trạng thái: màu từ bảng trên, chữ từ máy chủ. */
    function the(ma, nhan, cuaThem) {
        return '<span class="badge ' + (MAU_TRANG_THAI[ma] || 'b-gray') + '"'
             + (cuaThem || '') + '>' + chu(nhan || ma) + '</span>';
    }

    // ── Gọi API ──────────────────────────────────────────────────────────────

    /**
     * 401 thì NÉM, không tải lại trang.
     *
     * Trang dùng guard `web`; `/api/v2` dùng `auth:sanctum` sau
     * `EnsureFrontendRequestsAreStateful`, và lớp đó chỉ vào việc khi
     * `Referer`/`Origin` khớp `sanctum.stateful`. Một phiên web còn hiệu lực mà
     * `Referer` bị tước cho ra trang 200 + API 401 cùng lúc, nên `reload()` ở
     * đây là vòng lặp nạp trang vô tận.
     */
    function goi(url) {
        return fetch(url, {
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

    function veDauTrang(d) {
        var c = d.campaign || {};
        var p = c.period || {};

        if (oTrangThai) {
            oTrangThai.innerHTML = the(c.status, c.status_label)
                + '<span style="font-size:13px;color:var(--t3)">'
                + chu(ngay(p.start_date)) + ' → ' + chu(ngay(p.end_date)) + '</span>';
        }

        if (! oNut) { return; }

        var h = '';

        // Nút nào hiện do TRẠNG THÁI máy chủ trả về quyết, không do client
        // đoán. Hai nút này chỉ là lối đi — việc chặn thật nằm ở controller
        // đích, nên ẩn nút không phải phân quyền.
        if (['active', 'completed', 'paused'].indexOf(c.status) !== -1) {
            h += '<a href="' + chu(cf.duongBaoCao) + '" class="btn btn-p btn-sm">Xem báo cáo</a>';
        }
        if (c.status === 'approved') {
            h += '<a href="' + chu(cf.duongThanhToan) + '" class="btn btn-p btn-sm">Thanh toán</a>';
        }

        h += '<a href="' + chu(cf.duongTatCa) + '" class="btn btn-s btn-sm">Tất cả campaigns</a>';

        oNut.innerHTML = h;
    }

    function veSoLieu(s) {
        s = s || {};

        return '<div class="buyer-stats">'
            + '<div class="buyer-stat"><div class="buyer-stat-n">' + (s.line_count || 0) + '</div>'
            + '<div class="buyer-stat-l">Màn hình</div></div>'
            + '<div class="buyer-stat"><div class="buyer-stat-n">' + tien(s.estimated_cost) + '</div>'
            + '<div class="buyer-stat-l">Chi phí ước tính (₫)</div></div>'
            + '<div class="buyer-stat"><div class="buyer-stat-n">' + tien(s.actual_impressions) + '</div>'
            + '<div class="buyer-stat-l">Impressions thực tế</div></div>'
            + '<div class="buyer-stat"><div class="buyer-stat-n">' + (Number(s.delivery_rate_pct) || 0) + '%</div>'
            + '<div class="buyer-stat-l">Delivery rate</div></div>'
            + '</div>';
    }

    /**
     * Một dòng đặt chỗ, kèm nút hủy nếu máy chủ có trả báo giá cho nó.
     *
     * Không có báo giá nghĩa là **một trong hai**: dòng ở trạng thái không hủy
     * được, hoặc người xem không có quyền `cancel`. Cả hai đều dẫn tới cùng
     * một việc ở đây — không vẽ nút — nên client không cần phân biệt, và cũng
     * không được tự suy ra điều kiện nào trong hai điều kiện đó.
     */
    function veDong(line, tra) {
        var sc  = line.screen || {};
        var loc = sc.location || {};
        var per = line.period || {};
        var est = line.estimate || {};
        var bao = tra[line.id] || null;

        var h = '<div class="buyer-campaign-row">'
            + '<div style="display:flex;align-items:center;gap:10px;flex:1;min-width:0">'
            + '<img src="' + chu(sc.photo_url || '') + '" alt="' + chu(sc.name) + '"'
            + ' style="width:40px;height:40px;border-radius:8px;object-fit:cover;background:var(--bg2);flex-shrink:0">'
            + '<div style="min-width:0">'
            + '<div style="font-size:13px;font-weight:700;color:var(--t1);overflow:hidden;text-overflow:ellipsis;white-space:nowrap">'
            + chu(sc.name) + '</div>'
            + '<div style="font-size:11px;color:var(--t4)">'
            + chu((sc.owner && sc.owner.name) || '') + ' &middot; '
            + chu(ngayNgan(per.start_date)) + ' → ' + chu(ngayNgan(per.end_date))
            + '</div></div></div>'
            + '<div style="text-align:right;flex-shrink:0">'
            + '<div style="font-size:13px;font-weight:700;color:var(--t1)">' + tien(est.cost) + ' ₫</div>'
            + the(line.status, line.status_label, ' style="font-size:10px"');

        if (bao) {
            h += veNutHuy(line, bao);
        }

        return h + '</div></div>';
    }

    /**
     * Nút hủy, kèm con số hoàn **do máy chủ tính**.
     *
     * Gọi là "dự kiến" vì tiền đã trả được phân bổ theo các dòng còn mở: hủy
     * dòng khác trước sẽ làm con số này đổi. Con số quyết định là con số
     * `CancellationService::cancelLine()` tính lại trong transaction có khóa —
     * và máy chủ nói điều đó ra bằng `is_estimate`, nên client không phải tự
     * biết.
     */
    function veNutHuy(line, bao) {
        var hoiLai = bao.refundable > 0
            ? 'Hủy đặt chỗ trên màn hình này? Còn ' + bao.days_before
              + ' ngày tới ngày chạy, bạn được hoàn ' + bao.refund_pct
              + '% — dự kiến ' + tien(bao.refundable) + ' ₫.'
            : 'Hủy đặt chỗ trên màn hình này? Còn ' + bao.days_before
              + ' ngày tới ngày chạy nên theo chính sách hủy, lần hủy này KHÔNG được hoàn tiền.';

        return '<form method="POST" action="' + chu(cf.duongHuy + '/' + line.id + '/cancel') + '"'
            + ' style="margin-top:6px" data-cd-huy data-cd-hoi="' + chu(hoiLai) + '">'
            + '<input type="hidden" name="_token" value="' + chu(cf.csrf) + '">'
            + '<button type="submit" style="background:none;border:none;padding:0;font-size:11px;'
            + 'font-weight:600;color:var(--red);cursor:pointer;text-decoration:underline">Hủy đặt chỗ</button>'
            + '<div style="font-size:10px;color:var(--t4);margin-top:2px">'
            + (bao.refundable > 0
                ? 'Hoàn dự kiến ' + tien(bao.refundable) + ' ₫ (' + bao.refund_pct + '%)'
                : 'Không được hoàn tiền')
            + '</div></form>';
    }

    /**
     * Chính sách hủy, đọc từ phản hồi máy chủ.
     *
     * Bản cũ đọc `config('pricing.refund_tiers')` trong Blade. Chép cứng ở
     * client là để con số trên màn hình lệch khỏi con số máy chủ đang áp dụng
     * mà không ai biết — nên nó đi qua API như mọi thứ khác.
     */
    function veChinhSach(tiers) {
        if (! tiers || ! tiers.length) { return ''; }

        var phan = tiers.map(function (t, i) {
            var moTa = t.min_days_before > 0
                ? 'từ ' + t.min_days_before + ' ngày trở lên'
                : 'dưới ' + ((tiers[i - 1] && tiers[i - 1].min_days_before) || 0) + ' ngày';

            return moTa + ' hoàn ' + t.refund_pct + '%';
        }).join('; ');

        return '<div style="font-size:11px;color:var(--t4);margin-top:10px;line-height:1.6">'
            + 'Chính sách hủy, tính theo số ngày còn lại tới ngày chạy của từng màn hình: '
            + chu(phan) + '. Sàn không giữ tiền — media owner hoàn trực tiếp cho bạn.'
            + '</div>';
    }

    function veDanhSachMan(d) {
        var lines = d.lines || [];

        // Bảng tra báo giá theo `booking_line_id`, dựng một lần. Máy chủ trả
        // mảng chứ không trả object khóa theo id: một object khóa bằng ULID
        // không diễn tả được trong đặc tả OpenAPI, và client nào cũng phải
        // dựng lại bảng tra như đây.
        var tra = {};
        (d.cancel_quotes || []).forEach(function (q) { tra[q.booking_line_id] = q; });

        var h = '<div class="buyer-section"><h2 class="buyer-section-title">Danh sách màn hình</h2>'
              + '<div class="buyer-campaign-list">';

        lines.forEach(function (l) { h += veDong(l, tra); });

        h += '</div>';

        if (Object.keys(tra).length) {
            h += veChinhSach(d.refund_policy);
        }

        return h + '</div>';
    }

    function veDanhGia(d) {
        var coTheDanhGia = d.reviewable_owners || [];
        var daDanhGia    = d.my_reviews || [];

        if (! coTheDanhGia.length && ! daDanhGia.length) {
            return '';
        }

        var h = '<div class="buyer-section"><h2 class="buyer-section-title">Đánh giá media owner</h2>';

        daDanhGia.forEach(function (r) {
            var sao = Math.max(0, Math.min(5, Number(r.rating) || 0));

            h += '<div class="rv-done"><div class="rv-done-hd">'
               + '<span class="rv-done-owner">' + chu((r.owner && r.owner.name) || '') + '</span>'
               + '<span class="rv-stars" aria-label="' + sao + ' trên 5 sao">'
               + '★'.repeat(sao) + '☆'.repeat(5 - sao) + '</span></div>';

            if (r.comment) {
                h += '<p class="rv-done-cmt">' + chu(r.comment) + '</p>';
            }

            // Nhãn trạng thái do máy chủ đưa ra (`status_label`), không dịch
            // lại ở đây: hai bộ chữ cho cùng một trạng thái sẽ lệch nhau ngay
            // lần đổi đầu tiên.
            h += '<div class="rv-done-st">' + chu(r.status_label || r.status) + '</div></div>';
        });

        coTheDanhGia.forEach(function (o) {
            h += '<form method="POST" action="' + chu(cf.duongDanhGia) + '" class="rv-form">'
               + '<input type="hidden" name="_token" value="' + chu(cf.csrf) + '">'
               + '<input type="hidden" name="owner_id" value="' + chu(o.id) + '">'
               + '<div class="rv-form-hd">Đánh giá <strong>' + chu(o.name) + '</strong></div>'
               + '<div class="rv-rate">';

            for (var i = 5; i >= 1; i--) {
                var id = 'r-' + chu(o.id) + '-' + i;
                h += '<input type="radio" name="rating" id="' + id + '" value="' + i + '" required>'
                   + '<label for="' + id + '" title="' + i + ' sao">★</label>';
            }

            h += '</div><textarea name="comment" rows="3" maxlength="2000"'
               + ' placeholder="Nhận xét về chất lượng dịch vụ, đúng hẹn, hỗ trợ... (không bắt buộc)"></textarea>'
               + '<button type="submit" class="btn btn-p rv-submit">Gửi đánh giá</button>'
               + '</form>';
        });

        return h + '</div>';
    }

    function veLichSu(d) {
        var ds = d.activities || [];

        var h = '<div class="buyer-section"><h2 class="buyer-section-title">Lịch sử hoạt động</h2>'
              + '<div class="buyer-campaign-list">';

        ds.forEach(function (a) {
            h += '<div class="buyer-campaign-row"><div>'
               + '<div style="font-size:13px;font-weight:600;color:var(--t1)">' + chu(a.description) + '</div>'
               + '<div style="font-size:11px;color:var(--t4)">' + chu(gio(a.created_at))
               + ' &middot; ' + chu((a.actor && a.actor.name) || 'Hệ thống') + '</div>'
               + '</div></div>';
        });

        h += '</div>';

        // Máy chủ cắt ở 50 dòng và nói tổng số. Nói ra thay vì im lặng cắt:
        // một lịch sử bị cắt mà không ghi gì là một lịch sử người đọc tưởng là
        // đầy đủ.
        if (Number(d.activity_count) > ds.length) {
            h += '<div style="font-size:11px;color:var(--t4);margin-top:8px">'
               + 'Đang hiện ' + ds.length + ' hoạt động mới nhất trong tổng '
               + Number(d.activity_count) + '.</div>';
        }

        return h + '</div>';
    }

    function ve(d) {
        veDauTrang(d);

        than.innerHTML = veSoLieu(d.stats)
                       + veDanhSachMan(d)
                       + veDanhGia(d)
                       + veLichSu(d);

        // Hỏi lại trước khi hủy, gắn sau khi vẽ.
        //
        // Dùng `addEventListener` chứ không `onsubmit="…"` trong chuỗi HTML:
        // chuỗi đó mang câu hỏi có số tiền và tên màn hình, và nhúng nó vào một
        // thuộc tính thực thi được là mở một đường chạy mã từ dữ liệu. Ở đây
        // câu hỏi nằm trong `data-cd-hoi`, chỉ được đọc ra chứ không chạy.
        [].forEach.call(than.querySelectorAll('[data-cd-huy]'), function (f) {
            f.addEventListener('submit', function (e) {
                if (! window.confirm(f.getAttribute('data-cd-hoi'))) {
                    e.preventDefault();
                }
            });
        });
    }

    function veLoi(thong) {
        than.innerHTML = '';
        if (oLoiMsg) { oLoiMsg.textContent = thong; }
        if (oLoi) { oLoi.hidden = false; }
    }

    // ── Chạy ─────────────────────────────────────────────────────────────────

    goi(cf.api)
        .then(function (j) { ve(j.data || {}); })
        .catch(function (e) {
            if (e.ma === 401) {
                veLoi('Phiên đăng nhập đã hết. Đăng nhập lại để xem chi tiết chiến dịch.');
                return;
            }
            if (e.ma === 429) {
                veLoi('Bạn gọi quá nhanh. Chờ một phút rồi tải lại trang.');
                return;
            }

            veLoi(e.message || 'Không tải được chi tiết chiến dịch. Thử tải lại trang.');
        });
})();
</script>
@endsection
