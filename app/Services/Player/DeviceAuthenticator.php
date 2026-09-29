<?php

namespace App\Services\Player;

use App\Models\Screen;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Xác thực thiết bị phát.
 *
 * Trước thay đổi này, endpoint player chỉ hỏi `screen_uuid` **trong thân yêu
 * cầu**. UUID không phải bí mật: nó nằm trong cấu hình thiết bị, trong log, và
 * lộ ra là bất cứ ai cũng bơm được lượt hiển thị cho màn hình đó — tức là chế
 * ra bằng chứng phát sóng, và qua đó chế ra doanh thu. Cột `device_token` đã
 * tồn tại trên bảng `screens` từ đầu nhưng **chưa từng được dùng**.
 *
 * Token lưu dưới dạng băm, như mật khẩu: đọc được CSDL cũng không giả mạo được
 * thiết bị. Bản rõ chỉ hiện đúng một lần, lúc cấp.
 */
class DeviceAuthenticator
{
    /**
     * Màn hình tương ứng với yêu cầu, nếu thiết bị chứng minh được danh tính.
     *
     * @throws HttpException 401 khi thiếu hoặc sai token, 404 khi không có màn hình
     */
    public function authenticate(Request $request, string $screenUuid): Screen
    {
        $screen = Screen::withoutGlobalScopes()->where('uuid', $screenUuid)->first();

        if (! $screen) {
            // 404 chứ không 401: UUID không tồn tại là chuyện cấu hình, và nói
            // rõ giúp người lắp đặt sửa nhanh. UUID không phải bí mật nên không
            // có gì bị lộ thêm ở đây.
            throw new HttpException(404, 'Không tìm thấy màn hình với UUID này.');
        }

        $token = $this->tokenFrom($request);

        if ($token === null || $token === '') {
            throw new HttpException(401, 'Thiếu X-Device-Token.');
        }

        if (! $screen->device_token) {
            // Chưa cấp token thì thiết bị chưa được phép gửi gì. Cố ý **không**
            // cho qua: mở ngoại lệ "chưa cấu hình thì bỏ kiểm" là giữ nguyên
            // đúng lỗ hổng vừa bịt.
            throw new HttpException(401, 'Màn hình này chưa được cấp token thiết bị.');
        }

        if (! Hash::check($token, $screen->device_token)) {
            throw new HttpException(401, 'Token thiết bị không đúng.');
        }

        return $screen;
    }

    /**
     * Cấp token mới cho một màn hình. Trả về **bản rõ**, chỉ lần này.
     */
    public function issueToken(Screen $screen): string
    {
        $plain = Str::random(48);

        $screen->forceFill(['device_token' => Hash::make($plain)])->save();

        return $plain;
    }

    private function tokenFrom(Request $request): ?string
    {
        $header = $request->header('X-Device-Token');

        if (is_string($header) && $header !== '') {
            return $header;
        }

        // Bearer cũng chấp nhận: một số player chỉ cấu hình được header
        // Authorization tiêu chuẩn.
        $bearer = $request->bearerToken();

        return is_string($bearer) && $bearer !== '' ? $bearer : null;
    }
}
