/**
 * Hỏi một câu duy nhất: **hồ sơ này đủ để ra quyết định mua chưa?**
 *
 * ══ Vì sao cần hàm này ══
 *
 * Đo trên production ngày 24/10/2026, `oohx-led-cto-can-tho` có 8 trong 18
 * trường một người mua DOOH cần. Thiếu nặng nhất là hai thứ đi cùng nhau: giá
 * và số khán giả. Không có cả hai thì **không tính được CPM**, nên vị trí ấy
 * không đặt cạnh vị trí nào khác để so sánh được.
 *
 * Trước lát này, người mua chỉ biết điều đó sau khi đọc hết trang và tự ghép
 * các khoảng trống lại. Khối này nói ra ngay, và nói rõ **thiếu cái gì** — vì
 * đó là thứ họ đòi được từ chủ vị trí.
 *
 * ══ Một hàm, hai nơi dùng ══
 *
 * Hàm nhận một `ScreenDetail` và không đọc gì khác: không `window`, không
 * `fetch`, không trạng thái. Nên `/publisher` dùng lại được để chủ vị trí thấy
 * đúng danh sách cần bổ sung cho hồ sơ của mình. Lát này **chưa** dựng giao
 * diện đó; nó chỉ đảm bảo hàm không vướng gì của trang công khai.
 *
 * ══ Ba trạng thái, không hai ══
 *
 * `dang_ngo` tồn tại vì hai chỗ trên hồ sơ thật **có** dữ liệu nhưng dữ liệu
 * ấy tự mâu thuẫn:
 *
 *   - Ảnh đến từ `storage/owners/covers/…` — ảnh bìa của chủ sở hữu dùng thay
 *     ảnh biển. Có một ảnh, nhưng nó không cho người mua thấy mặt biển.
 *   - `1080×1920 px` là tỉ lệ 0,5625 còn `23,04×28,8 m` là 0,8. Hai con số
 *     không thể cùng đúng; một tấm LED không có bước điểm ngang 21,3 mm và
 *     dọc 15 mm.
 *
 * Gọi chúng là "có" thì hồ sơ trông đủ hơn thực tế. Gọi là "thiếu" thì chủ vị
 * trí được yêu cầu nhập lại thứ họ đã nhập. Trạng thái thứ ba nói đúng việc
 * cần làm: **xác minh**.
 *
 * ══ Điểm đếm cả `dang_ngo` ══
 *
 * `diem = co + dang_ngo`. Một trường đáng ngờ vẫn là một trường có dữ liệu để
 * đối chiếu, khác hẳn một trường trống. Nhưng khối giao diện phải hiện riêng
 * số `dang_ngo` — nếu không, 8/18 của hồ sơ này trông giống 8/18 của một hồ sơ
 * có tám trường sạch.
 */

/** Trạng thái một trường trong hồ sơ. */
export type TrangThai = 'co' | 'thieu' | 'dang_ngo';

export type TruongHoSo = {
    /** Khoá ổn định — dùng cho `key` React và cho chốt test. */
    ma: string;
    nhan: string;
    trangThai: TrangThai;
    /** Câu ngắn nói giá trị hiện tại, hoặc vì sao đáng ngờ. Rỗng khi trống. */
    ghiChu: string;
};

export type NhomHoSo = {
    ma: 'vi_tri' | 'ky_thuat' | 'khan_gia' | 'thuong_mai';
    nhan: string;
    truong: TruongHoSo[];
};

export type DanhGiaHoSo = {
    nhom: NhomHoSo[];
    /** Tổng số trường được xét. Luôn 18. */
    tong: number;
    co: number;
    dangNgo: number;
    thieu: number;
    /** `co + dangNgo`. */
    diem: number;
    /** Một câu kết luận cho người mua. */
    ketLuan: string;
    /** Vì sao kết luận như vậy. */
    vi: string;
};

/** Ngưỡng lệch tỉ lệ coi là đáng ngờ. 2% chịu được sai số làm tròn của mét. */
const NGUONG_LECH_TI_LE = 0.02;

/** Đường dẫn ảnh bìa của chủ sở hữu — không phải ảnh vị trí. */
const ANH_BIA_OWNER = '/owners/covers/';

function so(v: unknown): boolean {
    return typeof v === 'number' && v > 0;
}

function chu(v: unknown): boolean {
    return typeof v === 'string' && v.trim() !== '';
}

function coNoiDung(o: unknown): boolean {
    if (o === null || typeof o !== 'object') return false;

    return Object.values(o as Record<string, unknown>).some((v) => {
        if (v === null || v === undefined) return false;
        if (typeof v === 'string') return v.trim() !== '';
        if (Array.isArray(v)) return v.length > 0;
        if (typeof v === 'number') return v !== 0;
        if (typeof v === 'object') return coNoiDung(v);
        return Boolean(v);
    });
}

/**
 * Tỉ lệ vật lý và tỉ lệ điểm ảnh có khớp nhau không.
 *
 * Trả `null` khi thiếu một trong bốn số — không kết luận được thì không nói
 * gì, chứ không nói "khớp".
 */
export function lechTiLe(
    rongM: unknown,
    caoM: unknown,
    rongPx: unknown,
    caoPx: unknown,
): number | null {
    if (!so(rongM) || !so(caoM) || !so(rongPx) || !so(caoPx)) return null;

    const tlMet = (rongM as number) / (caoM as number);
    const tlPx = (rongPx as number) / (caoPx as number);

    return Math.abs(tlMet - tlPx) / tlPx;
}

function truong(ma: string, nhan: string, trangThai: TrangThai, ghiChu = ''): TruongHoSo {
    return { ma, nhan, trangThai, ghiChu };
}

export function danhGiaHoSo(screen: unknown): DanhGiaHoSo {
    const s = (screen ?? {}) as Record<string, any>;

    const anh: unknown[] = Array.isArray(s.photo_urls)
        ? s.photo_urls
        : chu(s.photo_url)
          ? [s.photo_url]
          : [];
    const anhDauLaBia = chu(anh[0]) && (anh[0] as string).includes(ANH_BIA_OWNER);

    const lech = lechTiLe(s.size?.width_m, s.size?.height_m, s.specs?.width_px, s.specs?.height_px);
    const coKichThuoc = so(s.size?.width_m) && so(s.size?.height_m);

    const ins = s.insights ?? {};

    const viTri: TruongHoSo[] = [
        anh.length === 0
            ? truong('anh', 'Ảnh vị trí', 'thieu')
            : anhDauLaBia
              ? truong(
                    'anh',
                    'Ảnh vị trí',
                    'dang_ngo',
                    `${anh.length} ảnh, nhưng ảnh đầu là ảnh bìa chủ sở hữu`,
                )
              : truong('anh', 'Ảnh vị trí', 'co', `${anh.length} ảnh`),

        chu(s.site?.address)
            ? truong('dia_chi', 'Địa chỉ', 'co', s.site.address)
            : truong('dia_chi', 'Địa chỉ', 'thieu'),

        so(s.site?.lat) || so(s.site?.lng)
            ? truong('toa_do', 'Toạ độ', 'co', `${s.site.lat}, ${s.site.lng}`)
            : truong('toa_do', 'Toạ độ', 'thieu'),

        chu(s.description)
            ? truong('mo_ta', 'Mô tả vị trí', 'co')
            : truong('mo_ta', 'Mô tả vị trí', 'thieu'),

        Array.isArray(s.nearby_pois) && s.nearby_pois.length > 0
            ? truong('poi', 'Điểm lân cận', 'co', `${s.nearby_pois.length} điểm`)
            : truong('poi', 'Điểm lân cận', 'thieu'),
    ];

    const kyThuat: TruongHoSo[] = [
        !coKichThuoc
            ? truong('kich_thuoc', 'Kích thước vật lý', 'thieu')
            : lech !== null && lech > NGUONG_LECH_TI_LE
              ? truong(
                    'kich_thuoc',
                    'Kích thước vật lý',
                    'dang_ngo',
                    `${s.size.width_m}×${s.size.height_m} m — tỉ lệ không khớp độ phân giải`,
                )
              : truong('kich_thuoc', 'Kích thước vật lý', 'co', `${s.size.width_m}×${s.size.height_m} m`),

        so(s.specs?.width_px) && so(s.specs?.height_px)
            ? truong('phan_giai', 'Độ phân giải', 'co', `${s.specs.width_px}×${s.specs.height_px} px`)
            : truong('phan_giai', 'Độ phân giải', 'thieu'),

        s.specs?.allow_image || s.specs?.allow_video
            ? truong(
                  'dinh_dang',
                  'Định dạng nhận',
                  'co',
                  [s.specs?.allow_image ? 'ảnh' : null, s.specs?.allow_video ? 'video' : null]
                      .filter(Boolean)
                      .join(', '),
              )
            : truong('dinh_dang', 'Định dạng nhận', 'thieu'),

        so(s.specs?.spot_length_s)
            ? truong('thoi_luong', 'Thời lượng spot', 'co', `${s.specs.spot_length_s} giây`)
            : truong('thoi_luong', 'Thời lượng spot', 'thieu'),
    ];

    const khanGia: TruongHoSo[] = [
        so(ins.daily_impressions_est)
            ? truong('impressions', 'Impressions / ngày', 'co', dinhDangSo(ins.daily_impressions_est))
            : truong('impressions', 'Impressions / ngày', 'thieu'),

        so(ins.monthly_reach)
            ? truong('reach', 'Reach / tháng', 'co', dinhDangSo(ins.monthly_reach))
            : truong('reach', 'Reach / tháng', 'thieu'),

        so(ins.daily_footfall)
            ? truong('footfall', 'Lưu lượng qua điểm', 'co', dinhDangSo(ins.daily_footfall))
            : truong('footfall', 'Lưu lượng qua điểm', 'thieu'),

        coNoiDung(ins.audience_profile)
            ? truong('chan_dung', 'Chân dung khán giả', 'co')
            : truong('chan_dung', 'Chân dung khán giả', 'thieu'),

        coNoiDung(ins.time_performance)
            ? truong('theo_gio', 'Hiệu quả theo giờ', 'co')
            : truong('theo_gio', 'Hiệu quả theo giờ', 'thieu'),
    ];

    const thuongMai: TruongHoSo[] = [
        so(s.pricing?.io_rate?.amount) || so(s.pricing?.floor_cpm?.amount)
            ? truong('gia', 'Giá thuê', 'co')
            : truong('gia', 'Giá thuê', 'thieu'),

        so(s.specs?.io_kpi_spots_per_day)
            ? truong('spots', 'Số spot / ngày', 'co', `${s.specs.io_kpi_spots_per_day} spot`)
            : truong('spots', 'Số spot / ngày', 'thieu'),

        so(s.specs?.sov_max_pct)
            ? truong('sov', 'SOV tối đa', 'co', `${s.specs.sov_max_pct}%`)
            : truong('sov', 'SOV tối đa', 'thieu'),

        s.owner?.verified === true
            ? truong('xac_minh', 'Xác minh chủ sở hữu', 'co')
            : truong('xac_minh', 'Xác minh chủ sở hữu', 'thieu'),
    ];

    const nhom: NhomHoSo[] = [
        { ma: 'vi_tri', nhan: 'Nhận diện & vị trí', truong: viTri },
        { ma: 'ky_thuat', nhan: 'Thông số kỹ thuật', truong: kyThuat },
        { ma: 'khan_gia', nhan: 'Khán giả', truong: khanGia },
        { ma: 'thuong_mai', nhan: 'Thương mại', truong: thuongMai },
    ];

    const het = nhom.flatMap((n) => n.truong);
    const co = het.filter((t) => t.trangThai === 'co').length;
    const dangNgo = het.filter((t) => t.trangThai === 'dang_ngo').length;
    const thieu = het.filter((t) => t.trangThai === 'thieu').length;

    // Kết luận đọc theo HAI trường quyết định, không theo điểm tổng. Một hồ sơ
    // 14/18 mà thiếu giá và số khán giả vẫn không ra quyết định được, trong khi
    // một hồ sơ 10/18 có đủ hai thứ đó thì so sánh được với vị trí khác.
    const coGia = thuongMai.find((t) => t.ma === 'gia')?.trangThai === 'co';
    const coKhanGia = khanGia.some((t) => t.trangThai === 'co');

    const { ketLuan, vi } = ketLuanTu(coGia, coKhanGia);

    return {
        nhom,
        tong: het.length,
        co,
        dangNgo,
        thieu,
        diem: co + dangNgo,
        ketLuan,
        vi,
    };
}

function ketLuanTu(coGia: boolean, coKhanGia: boolean): { ketLuan: string; vi: string } {
    if (coGia && coKhanGia) {
        return {
            ketLuan: 'Đủ để so sánh',
            vi: 'Có cả giá và số khán giả, nên tính được CPM và đặt được cạnh vị trí khác.',
        };
    }

    if (!coGia && !coKhanGia) {
        return {
            ketLuan: 'Chưa đủ để báo giá',
            vi: 'Thiếu cả giá và số khán giả — hai thứ không có thì không tính được CPM, nên chưa so sánh được vị trí này với bất kỳ vị trí nào khác.',
        };
    }

    if (!coGia) {
        return {
            ketLuan: 'Thiếu giá',
            vi: 'Có số khán giả nhưng chưa niêm yết giá, nên chưa suy ra được CPM.',
        };
    }

    return {
        ketLuan: 'Thiếu số khán giả',
        vi: 'Có giá nhưng chưa có lượt người thấy, nên chưa suy ra được CPM.',
    };
}

function dinhDangSo(n: number): string {
    return new Intl.NumberFormat('vi-VN').format(n);
}
