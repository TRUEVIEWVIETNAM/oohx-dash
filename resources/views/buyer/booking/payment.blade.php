{{--
    Trang thanh toán — đọc qua `/api/v2` từ TRÌNH DUYỆT.

    Bên tiêu thụ thứ hai của nhóm cần quyền, sau trang giỏ. Cùng một lý lẽ, nên
    chỉ nhắc ngắn: gọi `Http::get()` từ PHP là một request giữ một worker LSAPI
    trong khi chờ một request khác cũng cần worker — hồ worker có giới hạn, và
    dưới tải thì nó cạn. Gọi qua HTTP kernel trong cùng tiến trình thì phải dựng
    giả phiên + Sanctum cho request con, và `throttle` đếm đôi mỗi lần vẽ trang.
    Gọi từ trình duyệt đi qua nhiều tầng thật hơn cả hai cách trên, không cần
    thêm đường ống nào phía máy chủ, và đúng hình dạng mà giai đoạn 8 sẽ dùng.

    ══ HAI đường, không phải một ══

    `GET payments`           — tiền: tổng kết, công nợ từng owner, lịch sử.
    `GET payment-recipients` — nơi nhận tiền: tên pháp lý, MST, tài khoản.

    Tách vì quyền khác nhau. Đường tiền cần quyền `view`; đường nhận tiền cần
    quyền `pay` (`manage_payments`). Nên vai trò `viewer` thấy công nợ nhưng
    KHÔNG thấy nơi nhận tiền — và trang này phải vẽ đúng trường hợp đó thay vì
    chết trắng. Trước đây trang render phía máy chủ cho `viewer` xem hết.

    Hai lời gọi chạy song song nên không mất thêm vòng mạng.

    ══ Đường GHI vẫn ở Blade ══

    Form xác nhận chuyển khoản vẫn POST về `buyer.payment.process` như cũ: CSRF,
    ghi nhận đồng ý quy chế, chuyển hướng sang trang thành công — đường đã chạy
    và đã có test. Chuyển một đường GHI tiền sang `fetch` là việc riêng, không
    gộp vào một lần đổi cách đọc.

    ══ Hai cái bẫy của Blade, đã mắc ở trang giỏ ══

    1. Blade biên dịch chỉ thị TRƯỚC khi bỏ chú thích, nên một chỉ thị viết
       trong khối chú thích này vẫn được biên dịch thành PHP. Không viết tên
       chỉ thị nào ở đây.
    2. Blade cắt tham số chỉ thị tại dấu `)` đầu tiên, nên một mảng nhiều dòng
       có lời gọi lồng bên trong sẽ bị cắt giữa mảng. Vì vậy mảng cấu hình dựng
       ở khối PHP bên dưới rồi truyền vào bằng MỘT biến.
--}}
@extends('frontpage.layouts.app', ['activeNav' => 'dashboard', 'bodyClass' => ''])

{{-- Bản cũ viết cú pháp echo của Blade bên trong chuỗi PHP, nên thẻ tiêu đề
     của trình duyệt hiện ra nguyên văn cú pháp đó thay vì tên chiến dịch. Nối
     chuỗi mới là cách truyền một giá trị vào đây. --}}
@section('title', 'Thanh toán — ' . $campaign->name . ' | OOHX')

@section('content')
@php
    $cauHinhTra = [
        'apiTien'     => url('/api/v2/campaigns/' . $campaign->id . '/payments'),
        'apiNhanTien' => url('/api/v2/campaigns/' . $campaign->id . '/payment-recipients'),
        'duongGhi'    => route('buyer.payment.process', $campaign),
        'duongCampaign' => route('buyer.campaigns.show', $campaign),
        'quyChe'      => url('/quy-che-hoat-dong'),
        'dangNhap'    => url('/login'),
        'csrf'        => csrf_token(),
        'hotline'     => config('policies.company.hotline'),

        // Chỉ là NHÃN, không phải phép tính tiền. Ba con số tiền đều đọc
        // nguyên từ API, nơi `PaymentService::withVat()` là chỗ duy nhất cộng
        // VAT. Trang này không nhân, không chia, không làm tròn gì.
        'vatNhan'     => rtrim(rtrim(number_format((float) config('pricing.vat_rate') * 100, 2, '.', ''), '0'), '.'),
    ];
@endphp
<script type="application/json" data-pay-config>@json($cauHinhTra, JSON_HEX_TAG | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)</script>

<div class="w" style="padding-top:24px;padding-bottom:64px">

    <h1 class="wz-title">Thanh toán</h1>
    <p class="wz-sub">{{ $campaign->name }} &middot; {{ $campaign->code }}</p>

    @if($errors->any())
    <div class="auth-error" style="margin-bottom:16px">@foreach($errors->all() as $e)<div>{{ $e }}</div>@endforeach</div>
    @endif

    <div class="wz-layout">
        <div class="wz-main" data-pay-main>
            <div class="wz-card" data-pay-loading>
                <div style="color:var(--t3);font-size:13px">Đang tải thông tin thanh toán…</div>
            </div>
        </div>

        <div class="wz-sidebar">
            <div class="wz-actions-card">
                <div style="font-size:14px;font-weight:700;color:var(--t1);margin-bottom:8px">{{ $campaign->name }}</div>
                <div style="font-size:12px;color:var(--t4);margin-bottom:16px" data-pay-meta>&nbsp;</div>
                <a href="{{ route('buyer.campaigns.show', $campaign) }}" class="btn btn-s" style="width:100%;justify-content:center;border-radius:10px">Xem Campaign</a>
            </div>
            <div style="margin-top:12px;padding:14px;background:var(--bg2);border-radius:12px;font-size:12px;color:var(--t3);line-height:1.6">
                <svg viewBox="0 0 24 24" fill="var(--grn)" style="width:14px;height:14px;vertical-align:middle"><path d="M12 1L3 5v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V5l-9-4z"/></svg>
                Thanh toán bảo mật bởi OOHX. Campaign sẽ tự động kích hoạt sau khi xác nhận thanh toán.
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    'use strict';

    var oCauHinh = document.querySelector('script[data-pay-config]');
    var khung    = document.querySelector('[data-pay-main]');

    if (! oCauHinh || ! khung) {
        return;
    }

    var cf   = JSON.parse(oCauHinh.textContent);
    var oMeta = document.querySelector('[data-pay-meta]');

    // ── Giúp việc ────────────────────────────────────────────────────────────

    /**
     * Thoát đủ NĂM ký tự, không phải ba.
     *
     * Chuỗi ở đây đi vào cả phần nội dung lẫn giá trị thuộc tính HTML (tên
     * owner, số tài khoản, `owner_id` trong input ẩn). Thiếu `"` và `'` là mở
     * đường thoát khỏi thuộc tính — và tên owner đến từ dữ liệu người dùng
     * nhập ở khu publisher, không phải hằng số trong code.
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
        return (Number(n) || 0).toLocaleString('vi-VN');
    }

    /**
     * Cắt chuỗi ngày theo chữ, KHÔNG dùng `new Date()`.
     *
     * `new Date('2026-10-08')` đọc là nửa đêm UTC, nên ở múi giờ âm nó lùi
     * sang ngày 07. Ngày bắt đầu/kết thúc chiến dịch là ngày theo lịch, không
     * phải một mốc thời gian, nên không được đi qua múi giờ nào.
     */
    function ngay(s) {
        if (! s) { return '—'; }
        var p = String(s).split('-');
        return p.length === 3 ? p[2] + '/' + p[1] + '/' + p[0] : String(s);
    }

    /**
     * Thời điểm tạo khoản — hiện theo giờ Việt Nam, cố định.
     *
     * `app.timezone` là `UTC`, nên bản render phía máy chủ trước đây hiện giờ
     * UTC, tức lệch 7 tiếng so với lúc người mua thật sự bấm nút. Vẽ phía
     * client buộc phải chọn một múi giờ — không có lựa chọn "để nguyên" — nên
     * chọn đúng: `Asia/Ho_Chi_Minh` cố định, không theo máy người xem, để hai
     * người ở hai nước nói về cùng một con số khi đối soát.
     */
    function gio(s) {
        if (! s) { return ''; }
        var d = new Date(s);
        if (isNaN(d.getTime())) { return ''; }
        try {
            // Hai bộ định dạng chứ không một.
            //
            // Một bộ khai cả ngày lẫn giờ thì `vi-VN` trả về "09:11 08/10/2026"
            // — giờ TRƯỚC ngày. Bản render cũ của trang là `d/m/Y H:i`, tức
            // ngày trước. Thứ tự do locale quyết là thứ tự mình không kiểm
            // được, và nó đổi theo phiên bản ICU.
            //
            // Test `thời điểm tạo khoản hiện theo giờ Việt Nam, ngày trước giờ`
            // canh cả ba cách sai: bỏ ghim múi giờ, để nguyên UTC, và để
            // locale tự chọn thứ tự.
            var p = { timeZone: 'Asia/Ho_Chi_Minh' };
            var ngayVN = new Intl.DateTimeFormat('vi-VN', Object.assign({
                day: '2-digit', month: '2-digit', year: 'numeric'
            }, p)).format(d);
            var gioVN = new Intl.DateTimeFormat('vi-VN', Object.assign({
                hour: '2-digit', minute: '2-digit', hour12: false
            }, p)).format(d);

            return ngayVN + ' ' + gioVN;
        } catch (e) {
            return d.toISOString().slice(0, 16).replace('T', ' ');
        }
    }

    function ma() {
        try {
            if (window.crypto && typeof window.crypto.randomUUID === 'function') {
                return window.crypto.randomUUID();
            }
        } catch (e) { /* đi tiếp */ }

        return 'n-' + Date.now() + '-' + Math.random().toString(36).slice(2, 12);
    }

    // ── Gọi API ──────────────────────────────────────────────────────────────

    /**
     * 401 thì NÉM, không tải lại trang.
     *
     * Trang này dùng guard `web`; `/api/v2` dùng `auth:sanctum` sau
     * `EnsureFrontendRequestsAreStateful`, và lớp đó chỉ vào việc khi
     * `Referer`/`Origin` khớp `sanctum.stateful`. Một phiên web còn hiệu lực mà
     * `Referer` bị tước cho ra trang 200 + API 401 cùng lúc, nên `reload()` ở
     * đây là vòng lặp tải lại vô tận.
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

    function veLoi(thong, themDangNhap) {
        var h = '<div class="wz-card"><div class="auth-error">' + chu(thong) + '</div>';

        if (themDangNhap) {
            h += '<p style="margin-top:12px;font-size:13px">'
               + '<a href="' + chu(cf.dangNhap) + '">Đăng nhập lại</a></p>';
        }

        khung.innerHTML = h + '</div>';
    }

    function veTongKet(s) {
        var h = '<div class="wz-card">'
              + '<div class="wz-card-title">Chi tiết thanh toán</div>'
              + '<div class="pay-rows">'
              + '<div class="pay-row"><span>Tổng chi phí booking</span><span>' + tien(s.total_cost) + ' ₫</span></div>'
              + '<div class="pay-row"><span>VAT (' + chu(cf.vatNhan) + '%)</span><span>' + tien(s.vat) + ' ₫</span></div>'
              + '<div class="pay-row pay-row-total"><span>Tổng cộng</span><span>' + tien(s.total_cost_vat) + ' ₫</span></div>';

        if (Number(s.total_paid) > 0) {
            h += '<div class="pay-row" style="color:var(--grn)"><span>Đã thanh toán</span><span>-' + tien(s.total_paid) + ' ₫</span></div>';
        }
        if (Number(s.pending) > 0) {
            h += '<div class="pay-row" style="color:var(--org)"><span>Đang xử lý</span><span>-' + tien(s.pending) + ' ₫</span></div>';
        }
        if (Number(s.remaining) > 0) {
            h += '<div class="pay-row pay-row-total" style="color:var(--bl)"><span>Còn lại</span><span>' + tien(s.remaining) + ' ₫</span></div>';
        }

        return h + '</div></div>';
    }

    function veDaTraDu() {
        return '<div class="wz-card" style="margin-top:16px;text-align:center;padding:32px">'
             + '<svg viewBox="0 0 24 24" fill="var(--grn)" style="width:48px;height:48px;margin-bottom:12px">'
             + '<path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z"/></svg>'
             + '<div style="font-size:18px;font-weight:700;color:var(--t1)">Đã thanh toán đủ</div>'
             + '<div style="font-size:13px;color:var(--t3);margin-top:6px">Campaign sẽ tự động kích hoạt</div>'
             + '</div>';
    }

    /**
     * Một khối owner.
     *
     * Đi theo `by_owner` — danh sách CÔNG NỢ — rồi mới tra nơi nhận tiền, chứ
     * không ngược lại. Thứ tự đó đúng về nghiệp vụ: người mua trả cho người
     * mình đang nợ. Nếu một owner có nợ mà không tra ra nơi nhận tiền thì phải
     * nói ra, không được lặng lẽ bỏ khối đó đi — biến mất khỏi danh sách là
     * cách để một khoản nợ không bao giờ được trả.
     */
    function veOwner(row, nguoiNhan, noiDungCK, coQuyenXemNhanTien) {
        var o    = row.owner || {};
        var nhan = nguoiNhan[o.id] || null;
        var ten  = (nhan && nhan.legal_name) ? nhan.legal_name : (o.name || '—');

        var h = '<div class="pay-owner' + (row.is_paid ? ' is-paid' : '') + '">'
              + '<div class="pay-owner-hd"><div>'
              + '<div class="pay-owner-name">' + chu(ten) + '</div>';

        if (nhan && nhan.tax_code) {
            h += '<div class="pay-owner-tax">MST: ' + chu(nhan.tax_code) + '</div>';
        }

        h += '</div>';
        h += row.is_paid ? '<span class="badge b-green">Đã ghi nhận</span>' : '';
        h += '</div><div class="pay-bank-info">';

        if (! coQuyenXemNhanTien) {
            h += '<div class="pay-bank-missing">'
               + 'Bạn không có quyền xem thông tin nhận tiền. Cần quyền quản lý thanh toán '
               + 'trong tổ chức — liên hệ quản trị viên tổ chức của bạn.'
               + '</div>';
        } else if (nhan && nhan.has_bank_details) {
            h += '<div class="pay-bank-row"><span>Ngân hàng</span><span>' + chu(nhan.bank_name) + '</span></div>'
               + '<div class="pay-bank-row"><span>Số tài khoản</span><span style="font-weight:700;letter-spacing:1px">' + chu(nhan.bank_account_number) + '</span></div>'
               + '<div class="pay-bank-row"><span>Chủ tài khoản</span><span>' + chu(nhan.bank_account_name) + '</span></div>';

            if (nhan.bank_branch) {
                h += '<div class="pay-bank-row"><span>Chi nhánh</span><span>' + chu(nhan.bank_branch) + '</span></div>';
            }

            h += '<div class="pay-bank-row"><span>Nội dung CK</span><span style="font-weight:700;color:var(--bl)">' + chu(noiDungCK) + '</span></div>';
        } else {
            // Nói thẳng ra thay vì hiện một khối trống hoặc số giả.
            h += '<div class="pay-bank-missing">'
               + 'Media owner này chưa cung cấp thông tin tài khoản nhận tiền. '
               + 'Vui lòng liên hệ OOHX qua hotline ' + chu(cf.hotline) + ' để được hỗ trợ.'
               + '</div>';
        }

        h += '<div class="pay-bank-row"><span>Chi phí</span><span>' + tien(row.cost) + ' ₫</span></div>'
           + '<div class="pay-bank-row"><span>VAT (' + chu(cf.vatNhan) + '%)</span><span>' + tien(row.vat) + ' ₫</span></div>'
           + '<div class="pay-bank-row"><span>Cần chuyển</span><span style="font-weight:700">' + tien(row.remaining) + ' ₫</span></div>'
           + '</div>';

        if (! row.is_paid && coQuyenXemNhanTien && nhan && nhan.has_bank_details) {
            h += veForm(row, o, nhan);
        }

        return h + '</div>';
    }

    /**
     * Form xác nhận — POST về route Blade, không về API.
     *
     * `payment_nonce` sinh ở client: nó là mã riêng của CHÍNH lần gửi này.
     * Bấm hai lần trên cùng form gửi lại cùng mã nên không tạo hai khoản; tải
     * lại trang để trả tiếp phần còn lại thì có mã mới. Bản cũ dùng token
     * phiên, mà token đó không đổi giữa các lần trả nên lần trả thứ hai nhận
     * lại đúng khoản đã hoàn tất (Codex R07).
     *
     * `amount` gửi lên chỉ là ĐỀ NGHỊ: `PaymentService::createPayment()` quyết
     * số tiền thật, và gửi vượt công nợ thì nó trả 422 kèm số đúng. Nên việc
     * con số này đến từ client không mở ra đường tự đặt giá (CLAUDE.md mục 5).
     */
    function veForm(row, o, nhan) {
        return '<form method="POST" action="' + chu(cf.duongGhi) + '">'
             + '<input type="hidden" name="_token" value="' + chu(cf.csrf) + '">'
             + '<input type="hidden" name="method" value="bank_transfer">'
             + '<input type="hidden" name="owner_id" value="' + chu(o.id) + '">'
             + '<input type="hidden" name="amount" value="' + chu(row.remaining) + '">'
             + '<input type="hidden" name="payment_nonce" value="' + chu(ma()) + '">'
             + '<label class="consent">'
             + '<input type="checkbox" name="accept_terms" value="1" required>'
             + '<span>Bằng cách thanh toán, tôi đồng ý với '
             + '<a href="' + chu(cf.quyChe) + '" target="_blank" rel="noopener">Quy chế hoạt động</a>'
             + '</span></label>'
             + '<button type="submit" class="btn btn-p" style="width:100%;justify-content:center;border-radius:10px;height:44px;font-size:14px">'
             + 'Xác nhận đã chuyển khoản cho ' + chu(nhan.name || o.name) + '</button>'
             + '</form>';
    }

    function veLichSu(ds) {
        if (! ds || ! ds.length) { return ''; }

        var mau  = { pending: 'b-org', processing: 'b-org', completed: 'b-grn', failed: 'b-red', refunded: 'b-gray' };
        var nhan = { pending: 'Chờ xác nhận', processing: 'Đang xử lý', completed: 'Thành công', failed: 'Thất bại', refunded: 'Hoàn tiền' };

        var h = '<div class="wz-card" style="margin-top:16px">'
              + '<div class="wz-card-title">Lịch sử thanh toán</div><div class="pay-history">';

        ds.forEach(function (p) {
            var cach = String(p.method || '').replace(/_/g, ' ');
            cach = cach ? cach.charAt(0).toUpperCase() + cach.slice(1) : '';

            h += '<div class="pay-history-row"><div>'
               + '<div style="font-weight:600;color:var(--t1);font-size:13px">' + chu(p.invoice_number || p.transaction_ref || p.id) + '</div>'
               + '<div style="font-size:11px;color:var(--t4)">' + chu(cach) + ' &middot; ' + chu(gio(p.created_at)) + '</div>'
               + '</div><div style="text-align:right">'
               + '<div style="font-weight:700;color:var(--t1)">' + tien(p.amount) + ' ₫</div>'
               + '<span class="badge ' + (mau[p.status] || 'b-gray') + '" style="font-size:10px">' + chu(nhan[p.status] || p.status) + '</span>'
               + '</div></div>';
        });

        return h + '</div></div>';
    }

    function veThieuQuyen() {
        return '<div class="wz-card" style="margin-top:16px">'
             + '<div class="pay-bank-missing">'
             + 'Bạn xem được công nợ của campaign này nhưng không có quyền xem thông tin nhận tiền '
             + 'của media owner. Quyền đó là <strong>quản lý thanh toán</strong> — liên hệ quản trị '
             + 'viên tổ chức của bạn.'
             + '</div></div>';
    }

    function ve(tien_, nhanTien) {
        var s = tien_.summary || {};
        var coQuyen = ! nhanTien.thieuQuyen;

        // Dựng bảng tra theo `id` một lần, không quét lại mảng cho mỗi owner.
        var tra = {};
        (nhanTien.recipients || []).forEach(function (r) { tra[r.id] = r; });

        var h = veTongKet(s);

        if (s.is_fully_paid) {
            h += veDaTraDu();
        } else {
            h += '<div class="wz-card" style="margin-top:16px">'
               + '<div class="wz-card-title">Thanh toán</div>'
               + '<p class="pay-note">Bạn chuyển khoản <strong>trực tiếp cho từng media owner</strong>. '
               + 'OOHX không thu hộ, chỉ ghi nhận giao dịch và đối soát.';

            var ds = tien_.by_owner || [];

            if (ds.length > 1) {
                h += ' Campaign này gồm màn hình của ' + ds.length + ' media owner, nên cần '
                   + ds.length + ' lần chuyển khoản riêng.';
            }

            h += '</p>';

            ds.forEach(function (row) {
                h += veOwner(row, tra, nhanTien.transfer_note, coQuyen);
            });

            h += '</div>';

            if (! coQuyen) {
                h += veThieuQuyen();
            }
        }

        h += veLichSu(tien_.payments);

        khung.innerHTML = h;

        if (oMeta) {
            var c = tien_.campaign || {};
            oMeta.textContent = (c.line_count || 0) + ' màn hình · '
                              + ngay(c.start_date) + ' → ' + ngay(c.end_date);
        }
    }

    // ── Chạy ─────────────────────────────────────────────────────────────────

    // Đường nhận tiền được phép thất bại bằng 403 mà KHÔNG làm sập trang: đó
    // là câu trả lời đúng cho vai trò `viewer`, không phải một lỗi. Mọi mã
    // khác vẫn nổi lên để `catch` bên dưới xử lý.
    var layNhanTien = goi(cf.apiNhanTien).then(
        function (r) { return r.data || {}; },
        function (e) {
            if (e.ma === 403) {
                return { thieuQuyen: true, recipients: [] };
            }
            throw e;
        }
    );

    Promise.all([goi(cf.apiTien), layNhanTien])
        .then(function (kq) {
            ve(kq[0].data || {}, kq[1]);
        })
        .catch(function (e) {
            if (e.ma === 401) {
                veLoi('Phiên đăng nhập đã hết. Đăng nhập lại để xem thông tin thanh toán.', true);
                return;
            }
            if (e.ma === 429) {
                veLoi('Bạn gọi quá nhanh. Chờ một phút rồi tải lại trang.');
                return;
            }

            veLoi(e.message || 'Không tải được thông tin thanh toán. Thử tải lại trang.');
        });
})();
</script>
@endsection
