<?php

namespace App\Http\Controllers\Buyer;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\View\View;

class BuyerDashboardController extends Controller
{
    /**
     * Trang đầu khu người mua.
     *
     * ══ Không còn dựng dữ liệu chiến dịch ở đây ══
     *
     * Bản cũ chạy bốn `COUNT(*)` rồi lấy năm chiến dịch gần nhất, tất cả qua
     * `$org->campaigns()` với `$org = $user->currentOrganization`. Hai vấn đề
     * trong một dòng đó:
     *
     *  1. `currentOrganization` là một `belongsTo` thuần trên
     *     `current_organization_id` — **không** kiểm tư cách thành viên. Người
     *     bị gỡ khỏi tổ chức, mà cột vẫn trỏ ở đó, đọc được số đếm và năm
     *     chiến dịch gần nhất của tổ chức ấy. `/my/campaigns` đã đóng khe này
     *     từ PR #40 bằng `CampaignService::listForUser()`; trang này thì chưa,
     *     nên cùng một người thấy 0 ở danh sách và 12 ở ô thống kê.
     *  2. Đây là bản chép thứ hai của phép scope, và CLAUDE.md mục 1 nói thẳng
     *     vì sao đừng: `InventoryController` và `FrontpageService` đã trôi khỏi
     *     nhau đúng theo kiểu này và gây rò rỉ dữ liệu chưa duyệt.
     *
     * Nay trang đọc `GET /api/v2/campaigns/summary` và
     * `GET /api/v2/campaigns?per_page=5` **từ trình duyệt**, nên cả hai khối đi
     * qua đúng một cổng phân quyền với `/my/campaigns`.
     *
     * `$org` còn lại cho đúng dòng chào: tên người và tên tổ chức không phải dữ
     * liệu chiến dịch, và thanh điều hướng trên mọi trang đã in tên tổ chức
     * theo đúng cách này.
     */
    public function index(Request $request): View
    {
        return view('buyer.dashboard.index', [
            'org' => $request->user()->currentOrganization,
        ]);
    }
}
