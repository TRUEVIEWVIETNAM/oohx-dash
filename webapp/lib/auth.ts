/**
 * Gọi `/api/v2/auth/*` từ trình duyệt — Sanctum dạng SPA.
 *
 * ══ Vì sao nhóm này cần CSRF mà nhóm phản ánh thì không ══
 *
 * `/api/v2/auth/*` mang `EnsureFrontendRequestsAreStateful`: nó **tạo phiên**.
 * Một endpoint tạo phiên mà không có CSRF là một endpoint người khác đăng nhập
 * hộ được. Nhóm công khai (`/api/v2/reflections`) không tạo phiên nên không
 * mang middleware đó, và biểu mẫu phản ánh gọi thẳng được.
 *
 * ══ Trình tự bắt buộc ══
 *
 *   1. `GET /sanctum/csrf-cookie` → Laravel đặt cookie `XSRF-TOKEN`.
 *   2. POST kèm header `X-XSRF-TOKEN` mang **giá trị đã giải mã** của cookie đó.
 *
 * Bước 2 là chỗ dễ sai: giá trị cookie được URL-encode, nên gửi nguyên văn thì
 * Laravel so không khớp và trả **419** — một mã trạng thái không nói gì về
 * nguyên nhân, và người đọc sẽ đi tìm lỗi ở thông tin đăng nhập.
 */

/** Giá trị cookie, đã giải mã. `null` khi không có. */
function cookie(ten: string): string | null {
    const m = document.cookie.match(new RegExp('(^|;\\s*)' + ten + '=([^;]*)'));

    return m ? decodeURIComponent(m[2]) : null;
}

export type LoiApi = {
    /** Thông điệp để hiện cho người dùng. Luôn có. */
    thongDiep: string;
    /** Lỗi từng trường, lấy từ `details[]` của envelope v2. Có thể rỗng. */
    chiTiet: string[];
    status: number;
};

export type NguoiDung = {
    name?: string;
    email?: string;
    has_organization?: boolean;
};

/**
 * Gửi một yêu cầu tới `/api/v2/auth/<duong>`, tự lo phần CSRF.
 *
 * Ném `LoiApi` khi thất bại, trả dữ liệu người dùng khi thành công.
 */
export async function goiAuth(duong: string, body: unknown): Promise<NguoiDung> {
    // Lấy cookie CSRF. Phải chờ xong mới POST — gọi song song thì POST có thể
    // chạy trước khi cookie được đặt, và lỗi đó chỉ hiện ra lúc máy chậm.
    await fetch('/sanctum/csrf-cookie', {
        credentials: 'same-origin',
        headers: { Accept: 'application/json' },
    });

    const xsrf = cookie('XSRF-TOKEN');

    const res = await fetch(`/api/v2/auth/${duong}`, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            ...(xsrf ? { 'X-XSRF-TOKEN': xsrf } : {}),
        },
        body: JSON.stringify(body),
    });

    let data: {
        data?: NguoiDung;
        message?: string;
        details?: { field?: string; message?: string }[];
    } | null = null;

    try {
        data = await res.json();
    } catch {
        // 419 và 500 từ tầng web có thể trả HTML. Giữ `null` thay vì để lỗi
        // phân tích JSON che mất mã trạng thái thật.
    }

    if (res.ok && data?.data) {
        return data.data;
    }

    throw {
        status: res.status,
        chiTiet: (data?.details ?? [])
            .map((d) => d.message)
            .filter((m): m is string => Boolean(m)),
        thongDiep: data?.message ?? thongDiepMacDinh(res.status),
    } satisfies LoiApi;
}

/**
 * Khi máy chủ không trả thông điệp nào, nói đúng cái biết được.
 *
 * 419 có câu riêng vì nó là mã dễ hiểu sai nhất ở đây: nó KHÔNG phải sai mật
 * khẩu, mà là phiên/CSRF hết hạn — và cách chữa là tải lại trang, không phải
 * gõ lại mật khẩu.
 */
function thongDiepMacDinh(status: number): string {
    if (status === 419) return 'Phiên làm việc đã hết hạn. Vui lòng tải lại trang rồi thử lại.';
    if (status === 429) return 'Bạn đã thử quá nhiều lần. Vui lòng chờ ít phút rồi thử lại.';

    return `Máy chủ trả mã ${status}. Vui lòng thử lại.`;
}

/**
 * Nơi đưa người dùng tới sau khi đăng nhập hoặc đăng ký.
 *
 * `/my` vẫn là Blade (giai đoạn 8 đang hoãn), nên đây là `location.assign`
 * chứ không phải điều hướng trong app — `next/router` sẽ đi tìm một route
 * không tồn tại trong app này.
 *
 * Chưa có tổ chức thì vẫn về `/my`: Blade tự đưa họ sang bước tạo tổ chức, và
 * quyết định đó thuộc về bên đang sở hữu luồng onboarding.
 */
export function veKhuNguoiMua(): void {
    window.location.assign('/my');
}
