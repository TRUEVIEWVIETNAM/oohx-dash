<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Http\Requests\GhiDoDacRequest;
use App\Services\DoDacService;
use Illuminate\Http\Response;

/**
 * Đường nhận của máy đếm — lát 1 của `docs/PLAN-DO-DAC-TOI-THIEU.md`.
 *
 * ══ 204, thân rỗng ══
 *
 * Không có gì để dò. Một phản hồi mang `{"ok": true, "so_luot": 47}` biến
 * đường này thành chỗ đọc được số liệu của sàn bằng cách gửi vào một lượt —
 * và nó công khai, không đăng nhập.
 *
 * Thân rỗng cũng là điều đúng cho phía gọi: client `fetch` mà **không**
 * `await`, nuốt lỗi im lặng (lát 2). Một phép đo làm chậm tính năng nó đo là
 * một phép đo tự huỷ, nên không ai chờ phản hồi này cả.
 *
 * ══ Ba lớp chặn, vì đây là đường GHI mở cho người lạ ══
 *
 *  1. `throttle:30,60` ở route — cao hơn `5,60` của phản ánh vì đây là sự
 *     kiện tự động chứ không phải người gõ biểu mẫu, nhưng vẫn là một trần.
 *  2. Danh sách trắng cứng theo **cặp** sự kiện–giá trị (`GhiDoDacRequest`):
 *     giá trị lạ → 422, không ghi hàng nào.
 *  3. Bảng đích không lớn theo số lượt: khoá duy nhất bốn cột gộp mọi lượt
 *     của cùng một ô vào một hàng.
 *
 * Lớp thứ ba là lớp quan trọng nhất, vì hai lớp đầu chỉ làm việc bơm rác
 * **khó hơn**, còn lớp ba làm nó **vô ích**.
 */
class DoDacController extends Controller
{
    public function __construct(private readonly DoDacService $doDac)
    {
    }

    public function store(GhiDoDacRequest $request): Response
    {
        $this->doDac->ghiNhan(
            (string) $request->input('su_kien'),
            (string) $request->input('gia_tri'),
            (string) $request->input('thiet_bi'),
        );

        return response()->noContent();
    }
}
