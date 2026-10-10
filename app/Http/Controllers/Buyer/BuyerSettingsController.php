<?php

namespace App\Http\Controllers\Buyer;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use Illuminate\Http\Request;
use Illuminate\View\View;

class BuyerSettingsController extends Controller
{
    /**
     * Trang cài đặt — chỉ trả KHUNG, dữ liệu do trình duyệt gọi `/api/v2`.
     *
     * ══ Trang này từng do máy chủ render, và tôi từng bảo vệ việc đó ══
     *
     * Ba lý do tôi nêu đều về việc nó là một biểu mẫu: giá trị ban đầu của ô
     * nhập là thứ render máy chủ làm đúng; lấy qua API nghĩa là ô trống trong
     * một nhịp mà người dùng gõ được; và `old()` giữ lại thứ vừa nhập khi
     * validate thất bại.
     *
     * Quyết định là chuyển, và bản mới xử cả ba thay vì bỏ qua — khung chờ thay
     * cho ô trống, `disabled` cho tới khi điền xong, và biểu mẫu gửi qua API
     * nên không có vòng chuyển hướng nào để `old()` phục vụ. Chi tiết ở
     * `Api\V2\SettingsController` và trong chú thích đầu tệp Blade.
     *
     * ══ Vì sao vẫn kiểm quyền ở đây, dù trang không mang dữ liệu nào ══
     *
     * Không phải để che dữ liệu — không còn dữ liệu nào trong HTML để che. Mà
     * để người đã bị gỡ khỏi tổ chức nhận 403 ngay ở trang, thay vì thấy một
     * khung chờ rồi một thông báo lỗi. Hai chốt cho cùng một luật, và chốt
     * trong `SettingsController` mới là chốt chặn dữ liệu.
     */
    public function index(Request $request): View
    {
        $this->toChuc($request);

        // Cấu hình KHÔNG dựng ở đây — nó dựng trong khối `@php` của Blade, như
        // năm trang kia. Lý do không phải văn phong:
        //
        // `TruongTrangDocTest` trích đường API bằng cách tìm `url('/api/v2…')`
        // trong **tệp Blade**. Dựng cấu hình ở controller là lấy trang ra khỏi
        // tầm chốt đó: nó báo "không trích được đường API nào" và phép so
        // trường-đọc ↔ schema-endpoint không còn canh gì. Tôi đã gặp đúng thế
        // ở bản đầu của trang này.
        return view('buyer.dashboard.settings');
    }

    /**
     * Tổ chức đang chọn, đã kiểm tư cách thành viên.
     *
     * `currentOrganization` một mình không đủ: nó chỉ đọc một cột mà client đổi
     * được và không ai dọn khi một người bị gỡ khỏi tổ chức.
     */
    private function toChuc(Request $request): Organization
    {
        $org = $request->user()->currentOrganization;

        abort_unless($org !== null, 404);
        abort_unless($request->user()->can('view', $org), 403);

        return $org;
    }
}
