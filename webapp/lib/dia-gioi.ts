/**
 * Tách chuỗi địa giới mà API trả về thành từng cấp.
 *
 * ══ Vì sao cần hàm này ══
 *
 * `GET /api/v2/screens/{slug}` trả `location.city` và `site.city` dưới dạng
 * **một chuỗi chứa nhiều cấp hành chính**, nối bằng dấu `>`:
 *
 *     "Cần Thơ > Phường Tân An"
 *
 * Trang chi tiết trước lát này in nguyên chuỗi đó ra giao diện — kể cả dấu
 * `>`. Người mua đọc thấy một ký tự kỹ thuật ở giữa tên tỉnh và tên phường.
 *
 * ══ Hàm này KHÔNG sửa dữ liệu ══
 *
 * Nó chỉ tách khi hiển thị. Chừng nào `sites.city` còn là một chuỗi gộp thì
 * câu truy vấn lọc theo tỉnh vẫn phải dùng `TRIM(SUBSTRING_INDEX(..., '>', 1))`
 * và vẫn tốn 164ms — đó là việc riêng, cần một cột `tinh` và một migration,
 * đã ghi trong lộ trình. Đừng dùng hàm này để biện minh cho việc không tách
 * cột.
 *
 * ══ Cấp giữa không bị bỏ ══
 *
 * Phần lớn dữ liệu có hai cấp, nhưng gặp ba cấp
 * ("Hồ Chí Minh > Quận 1 > Phường Bến Nghé") thì lấy cấp cuối và bỏ cấp giữa
 * là làm mất thông tin người mua cần — Quận 1 nói nhiều hơn Phường Bến Nghé
 * với một người lên kế hoạch truyền thông. Nên `duoiTinh` giữ **mọi** cấp còn
 * lại, nối bằng dấu phẩy.
 */

/** Dấu nối cấp hành chính trong dữ liệu. */
const DAU = '>';

export type DiaGioi = {
    /** Cấp cao nhất — tỉnh hoặc thành phố. Chuỗi rỗng khi không có gì. */
    tinh: string;
    /** Mọi cấp còn lại, nối bằng `, `. `null` khi chuỗi chỉ có một cấp. */
    duoiTinh: string | null;
};

export function tachDiaGioi(city: string | null | undefined): DiaGioi {
    if (typeof city !== 'string') return { tinh: '', duoiTinh: null };

    // `filter(Boolean)` sau `trim()` bỏ cả cấp rỗng do dữ liệu nhập lỗi:
    // "Cần Thơ >" và "Cần Thơ > > Tân An" đều gặp được, và nếu không bỏ thì
    // kết quả mang theo một dấu phẩy không có gì đứng sau nó.
    const cap = city
        .split(DAU)
        .map((p) => p.trim())
        .filter(Boolean);

    if (cap.length === 0) return { tinh: '', duoiTinh: null };

    return {
        tinh: cap[0],
        duoiTinh: cap.length > 1 ? cap.slice(1).join(', ') : null,
    };
}

/**
 * Chuỗi địa giới đã bỏ dấu `>` — dùng ở chỗ chỉ có một dòng để in.
 *
 * Trả `"Cần Thơ · Phường Tân An"`. Dấu `·` thay vì `>` vì hai cấp này không
 * phải một đường dẫn để bấm vào; chúng là hai nhãn ngang hàng nhau trong mắt
 * người đọc.
 */
export function moTaDiaGioi(city: string | null | undefined): string {
    const { tinh, duoiTinh } = tachDiaGioi(city);
    if (!tinh) return '';
    return duoiTinh ? `${tinh} · ${duoiTinh}` : tinh;
}
