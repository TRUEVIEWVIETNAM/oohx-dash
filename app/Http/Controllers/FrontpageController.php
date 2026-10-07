<?php

namespace App\Http\Controllers;

use App\Services\FrontpageService;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Trang công khai còn lại trên Blade.
 *
 * ══ Chỉ còn một trang ══
 *
 * Giai đoạn 7 (07/10/2026) đã gỡ `index`, `listing`, `detail`, `map`, `owners`,
 * `ownerDetail` — Next.js phục vụ những đường đó từ giai đoạn 6, và giữ bản
 * Blade song song nghĩa là giữ hai nơi có thể trôi khỏi nhau.
 *
 * `/agency` ở lại vì nó **chưa được chuyển**: không có context nào cho nó trong
 * `nextjs.conf`, và không có trang nào cho nó trong `webapp/`. Nó là trang công
 * khai duy nhất Laravel còn phục vụ, nên `frontpage/layouts/app.blade.php` và
 * các partial khung (`header`, `footer`, `mobile-nav`, `seo-meta`,
 * `trial-notice`, `company-legal`) cũng ở lại vì nó.
 *
 * ══ `FrontpageService` KHÔNG đi theo ══
 *
 * Lộ trình viết "gỡ phần render của FrontpageService", nhưng khảo sát 07/10
 * cho thấy nó không phải lớp render: `Api\V2\CatalogController`,
 * `Api\V2\BookingController`, `CampaignPolicy`, `AvailabilityService`,
 * `CreativeService` đều dùng nó. Nó là lớp truy vấn dùng chung — và chính vì
 * dùng chung mà bản Next và bản Blade trước đây không trả về hai tập dữ liệu
 * khác nhau.
 */
class FrontpageController extends Controller
{
    public function __construct(private FrontpageService $fp) {}

    public function agency(Request $request): View
    {
        return view('frontpage.agency', [
            'agencies' => $this->fp->getAgenciesPaginated($request),
        ]);
    }
}
