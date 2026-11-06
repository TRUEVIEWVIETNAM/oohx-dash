/**
 * Mọi lời gọi **ghi** đi từ trình duyệt lên Laravel.
 *
 * Hai việc, cùng một tệp vì chúng là cùng một thủ tục: lấy token CSRF, rồi
 * gửi kèm nó. Tách ra hai tệp thì `gio-hang` phải `import` `csrf`, và một
 * import giá trị giữa hai tệp `.ts` là thứ `node --test` không nạp được —
 * Node không tự thêm đuôi `.ts` khi resolve, nên chốt ở
 * `tests/js/nut-booking.test.mjs` sẽ chết với `ERR_MODULE_NOT_FOUND` thay vì
 * chạy. Đổi quy ước import của cả dự án cho một tệp thì tệ hơn là để hai hàm
 * cùng chủ đề ở cạnh nhau.
 *
 * ══ TOKEN CSRF ══
 *
 * Nhóm `/api/v2` mang `EnsureFrontendRequestsAreStateful` (`routes/api.php`),
 * nên với người xem đã đăng nhập bằng cookie phiên thì Laravel xử lý lời gọi
 * như request **stateful** — `VerifyCsrfToken` có hiệu lực. Một
 * `POST /api/v2/cart/items` không kèm token trả **419**, không phải 401, và
 * thông điệp của 419 không nói gì về nguyên nhân.
 *
 * Laravel đặt `XSRF-TOKEN` như cookie **đọc được bằng JavaScript** (khác
 * `laravel_session`, vốn httpOnly), đúng để client đọc lại và gửi vào header
 * `X-XSRF-TOKEN`; `VerifyCsrfToken` chấp nhận header đó bên cạnh `_token`.
 *
 * Giá trị cookie được mã hoá kiểu URL. Gửi lại nguyên chuỗi đã mã hoá thì
 * token không khớp và Laravel trả 419 — một lỗi trông y hệt "phiên hết hạn",
 * nên rất dễ đi sửa nhầm chỗ.
 *
 * Trước 06/11/2026 thủ tục này nằm trong `AuthState.dangXuat()`. Nút "Thêm
 * vào kế hoạch" cần đúng nó, và chép sang là hai bản của một thủ tục bảo mật
 * (CLAUDE.md §1).
 *
 * ══ GIỎ HÀNG — đường duy nhất từ trang công khai vào giỏ ══
 *
 * ══ Vì sao một tệp riêng chứ không `fetch` ngay trong component ══
 *
 * Ba nút gọi nó: "Mua cả gói" và "Chọn lẻ" ở trang gói, "Thêm vào kế hoạch" ở
 * trang màn hình. Ba bản `fetch` là ba chỗ có thể quên `credentials`, quên
 * header CSRF, hoặc đọc mã lỗi theo ba kiểu — và cả ba cái quên ấy đều hỏng
 * **im lặng** với người xem (419 và 403 đều hiện ra như "bấm không ăn").
 *
 * ══ Máy chủ tính tiền, client không gửi tiền ══
 *
 * CLAUDE.md §5. Thân request ở đây không có trường số tiền nào, và không thể
 * có: `AddToCartRequest` không khai trường nào như vậy, còn `CartService`
 * tính lại `estimated_cost` từ cấu hình kho. Con số panel hiện trước khi bấm
 * là **ước tính**; con số thật về trong phản hồi.
 *
 * ══ Slug, không phải khoá nội bộ ══
 *
 * `AddToCartRequest::prepareForValidation()` nhận `screen_slug`,
 * `product_slug`, `selected_screen_slugs` và tự quy đổi qua
 * `publiclyVisible()`. Trang Next chỉ có slug trong tay, và đó là đúng: một
 * client ngoài không nên cầm ULID của kho.
 *
 * ══ Những trường cố ý KHÔNG gửi ══
 *
 * - `duration_units`: máy chủ suy từ khoảng ngày (`PeriodService::ioUnits`).
 *   Gửi kèm chỉ tạo cơ hội lệch với `end_date` mình vừa tính, và lệch xuống
 *   thì nhận 422.
 * - `quantity`: ô "Số màn hình" của panel **không** có nghĩa tương ứng ở máy
 *   chủ. `estimateCost()` lấy `screen_count` từ `inventory->effective_screen_count`
 *   và chú thích nói rõ "screen_count KHÔNG nhận từ client". Gửi một con số
 *   mà bên kia hiểu khác nghĩa thì tệ hơn không gửi.
 */

/** Đọc cookie `XSRF-TOKEN` đang có, đã giải mã. `null` khi chưa có. */
function tokenDangCo(): string | null {
    if (typeof document === 'undefined') return null;

    const tho = document.cookie
        .split('; ')
        .find((c) => c.startsWith('XSRF-TOKEN='))
        ?.split('=')[1];

    return tho ? decodeURIComponent(tho) : null;
}

/**
 * Token CSRF, xin của Laravel nếu chưa có cookie.
 *
 * Chỉ gọi `/sanctum/csrf-cookie` khi **thiếu** cookie: người vừa đăng nhập
 * qua `/login` đã có sẵn nó, và một lượt đi mạng thừa trước mỗi lần bấm nút
 * là một nhịp trễ người dùng cảm thấy được.
 *
 * Trả chuỗi rỗng khi vẫn không lấy được. Không ném: chỗ gọi đã phải xử lý
 * phản hồi lỗi của máy chủ rồi, và thêm một đường ném riêng chỉ làm hai nhánh
 * cho cùng một kết cục.
 */
export async function layTokenCsrf(): Promise<string> {
    const dangCo = tokenDangCo();

    if (dangCo) return dangCo;

    try {
        await fetch('/sanctum/csrf-cookie', { credentials: 'include' });
    } catch {
        return '';
    }

    return tokenDangCo() ?? '';
}

/** Thân request, đúng những trường `/api/v2/cart/items` khai trong OpenAPI. */
export type ThanThemGio = {
    screen_slug?: string;
    product_slug?: string;
    selected_screen_slugs?: string[];
    buy_mode?: 'package' | 'individual';
    start_date?: string;
    end_date?: string;
    pricing_model?: 'cpm' | 'io';
    booked_cpms?: number;
};

/**
 * Vì sao hỏng — để nút chọn câu nói, không phải để hiện ra nguyên văn.
 *
 * `khach` tách khỏi `khong-phai-nguoi-mua` vì hai thứ đó dẫn tới hai hành
 * động khác hẳn: một bên mời đăng nhập, một bên nói tài khoản này không mua
 * hàng được. Gộp chúng thành "lỗi" là đẩy người dùng đi đăng nhập lại bằng
 * đúng tài khoản vừa bị từ chối.
 */
export type MaLoiGio =
    | 'khach'
    | 'khong-phai-nguoi-mua'
    | 'du-lieu'
    | 'qua-nhieu'
    | 'mang';

export type KetQuaThemGio =
    | { ok: true; soMon: number; tongTam: number | null }
    | { ok: false; ma: MaLoiGio; loi: string };

/** Câu mặc định khi máy chủ không gửi câu nào. */
const CAU_MAC_DINH: Record<MaLoiGio, string> = {
    khach: 'Phiên đăng nhập đã hết hạn. Vui lòng đăng nhập lại.',
    'khong-phai-nguoi-mua':
        'Tài khoản này không phải tài khoản người mua, nên chưa đặt chỗ được. '
        + 'Vui lòng dùng tài khoản người mua hoặc liên hệ với chúng tôi.',
    'du-lieu': 'Thông tin đặt chỗ chưa hợp lệ. Vui lòng kiểm tra lại ngày và số lượng.',
    'qua-nhieu': 'Anh/chị thao tác hơi nhanh. Vui lòng thử lại sau ít giây.',
    mang: 'Không gửi được yêu cầu. Vui lòng kiểm tra kết nối rồi thử lại.',
};

/** Mã HTTP → mã lỗi của ta. */
function maTu(status: number): MaLoiGio {
    if (status === 401 || status === 419) return 'khach';
    if (status === 403) return 'khong-phai-nguoi-mua';
    if (status === 429) return 'qua-nhieu';

    return 'du-lieu';
}

/**
 * Câu lỗi của máy chủ, ưu tiên hơn câu mặc định của ta.
 *
 * `/api/v2/*` trả `{error, message, code, details[]}` (CLAUDE.md §2). Hai câu
 * 422 đáng giá nhất của giỏ hàng nằm đúng ở `message`: "Khoảng ngày … tương
 * ứng N kỳ, không thể đặt M kỳ" và "… tương ứng tối thiểu N CPM". Chúng là
 * con số thật của kho, và nuốt chúng để hiện một câu chung là bỏ đi thứ duy
 * nhất giúp người mua sửa được thao tác của mình.
 */
function cauCuaMayChu(than: unknown): string | null {
    if (typeof than !== 'object' || than === null) return null;

    const o = than as { message?: unknown; details?: unknown };

    if (typeof o.message === 'string' && o.message.trim() !== '') {
        return o.message;
    }

    if (Array.isArray(o.details)) {
        const dau = o.details.find(
            (d): d is { message: string } =>
                typeof d === 'object'
                && d !== null
                && typeof (d as { message?: unknown }).message === 'string',
        );

        if (dau) return dau.message;
    }

    return null;
}

export async function themVaoGio(than: ThanThemGio): Promise<KetQuaThemGio> {
    const token = await layTokenCsrf();

    let phanHoi: Response;

    try {
        phanHoi = await fetch('/api/v2/cart/items', {
            method: 'POST',
            // Bắt buộc, dù cùng tên miền — cùng lý do đã ghi ở `AuthState`:
            // khai rõ để không ai đổi origin rồi mất phiên mà không hiểu vì sao.
            credentials: 'include',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-XSRF-TOKEN': token,
            },
            body: JSON.stringify(than),
        });
    } catch {
        return { ok: false, ma: 'mang', loi: CAU_MAC_DINH.mang };
    }

    let body: unknown = null;

    try {
        body = await phanHoi.json();
    } catch {
        // Phản hồi không phải JSON. Giữ `body` là `null` và để mã HTTP quyết
        // định — một 201 không đọc được thân vẫn là đã thêm xong.
    }

    if (!phanHoi.ok) {
        const ma = maTu(phanHoi.status);

        return { ok: false, ma, loi: cauCuaMayChu(body) ?? CAU_MAC_DINH[ma] };
    }

    const tomTat = (body as { data?: { summary?: { item_count?: unknown; subtotal?: unknown } } })
        ?.data?.summary;

    return {
        ok: true,
        soMon: typeof tomTat?.item_count === 'number' ? tomTat.item_count : 0,
        tongTam: typeof tomTat?.subtotal === 'number' ? tomTat.subtotal : null,
    };
}
