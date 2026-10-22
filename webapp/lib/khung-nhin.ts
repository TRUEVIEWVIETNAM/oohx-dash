import type { MapPin } from './ban-do';
import type { MetaPin } from './dung-ban-do';

/**
 * Hai phép quyết định của khung nhìn bản đồ — tách khỏi DOM để chốt bằng số.
 *
 * Lát 4 của `docs/PLAN-GOP-EXPLORE-BAN-DO.md`:
 *
 *  - **`canNapLai`** — kéo bản đồ có làm dữ liệu cũ đi không? Trước lát này
 *    mỗi `moveend` là một lời gọi API; từ lát này nó chỉ bật một cái nút.
 *  - **`tomTatKhung`** — bốn con số của dải tổng quan, tính từ đúng tập pin
 *    đang hiện, không từ một lời gọi thứ hai.
 *
 * Cả hai là hàm thuần. Lý do: cái thứ nhất quyết định khi nào người dùng
 * thấy một cái nút, và một ngưỡng sai ở đó thì hoặc nút không bao giờ hiện
 * (bản đồ đứng im với dữ liệu cũ) hoặc nó hiện sau mỗi cú nhích 2px. Không
 * mở trình duyệt thì không đo được; gọi bằng số thì đo được.
 */

export type Khung = {
    north: number;
    south: number;
    east: number;
    west: number;
};

/**
 * Dung sai, tính theo **cạnh của khung đã nạp**.
 *
 * Không dùng số độ tuyệt đối: ở mức thu phóng cả nước một cạnh là ~15°, ở
 * mức đường phố nó là ~0,005°. Một ngưỡng tuyệt đối đúng ở đầu này thì sai
 * ba bậc ở đầu kia.
 *
 * 0,5% để một cú nhích vài pixel hoặc một lần `invalidateSize` không bật nút.
 */
const DUNG_SAI = 0.005;

/**
 * Khung nhìn hiện tại đã ra ngoài vùng dữ liệu đang có chưa?
 *
 * Phép so là **chứa trong**, không phải khoảng cách tâm: dữ liệu còn đủ chừng
 * nào khung mới vẫn nằm gọn trong khung đã nạp. Thu nhỏ lại hay nhích nhẹ
 * trong vùng cũ thì mọi pin cần thiết đã nằm sẵn trong tay — bật nút lúc đó
 * là mời người dùng trả một lời gọi API để nhận lại đúng thứ họ đang nhìn.
 *
 * `daCat` lật luật đó: lần nạp trước đã chạm trần 500 pin của
 * `GET /api/v2/screens/map`, nên tập đang có **không** đầy đủ ngay cả trong
 * vùng cũ. Thu nhỏ lại khung thì máy chủ có chỗ trả những pin đã bị cắt, nên
 * mọi cú di chuyển đều đáng nạp lại.
 *
 * `daNap` là `null` khi chưa nạp lần nào — trả `false`, vì lúc đó lượt nạp
 * đầu tiên đang trên đường và một cái nút "Tìm trong khu vực này" trên một
 * bản đồ trống là vô nghĩa.
 *
 * **Không** xử kinh tuyến 180: `east < west` khi khung vắt qua đó, và phép so
 * dưới đây sẽ sai. Bản đồ này chỉ có kho Việt Nam, và viết nhánh cho một
 * trường hợp không xảy ra là thêm một nhánh không ai chạy để kiểm.
 */
export function canNapLai(daNap: Khung | null, hienTai: Khung, daCat = false): boolean {
    if (!daNap) return false;

    const docSai = Math.abs(daNap.north - daNap.south) * DUNG_SAI;
    const ngangSai = Math.abs(daNap.east - daNap.west) * DUNG_SAI;

    if (hienTai.north > daNap.north + docSai) return true;
    if (hienTai.south < daNap.south - docSai) return true;
    if (hienTai.east > daNap.east + ngangSai) return true;
    if (hienTai.west < daNap.west - ngangSai) return true;

    return daCat;
}

/**
 * Tiền viết gọn cho một ô rộng ~90px.
 *
 * Dải tổng quan có bốn ô trong một panel 400px, nên một khoảng giá đầy đủ
 * (`5.000.000 ₫ – 40.000.000 ₫`, 29 ký tự) không có cửa. Rút về `5tr – 40tr`.
 *
 * Chỉ rút VND. Giá CPM có thể là `2,5 USD` và rút một con số một chữ số thì
 * không rút được gì, chỉ thêm một chỗ để sai.
 *
 * Làm tròn ở đây là làm tròn để **đọc**, không phải để tính: đây là khoảng
 * giá tham khảo của một khung nhìn, không phải một con số trên hoá đơn. Mọi
 * phép tính tiền thật vẫn ở máy chủ, bằng VND số nguyên.
 *
 * ══ Số lẻ chỉ giữ khi nó còn mang thông tin ══
 *
 * Đo trên production ngày 22/10/2026, ngay sau khi lát 4 lên: khoảng giá
 * thật của toàn kho là `54,2tr–729,2tr` — **96px** trong một ô rộng 73px,
 * nên nó ra màn hình dưới dạng `54,2tr–7…`.
 *
 * Số lẻ của `729,2tr` là 0,03% giá trị: nó không nói gì mà vẫn lấy hai ký
 * tự. Của `54,2tr` thì là 0,4% và phân biệt được 54 với 55. Nên từ 100 trở
 * lên thì bỏ phần lẻ.
 */
export function gonTien(so: number, tienTe: string | null): string {
    if ((tienTe || 'VND') !== 'VND') {
        return `${new Intl.NumberFormat('vi-VN', { maximumFractionDigits: 2 }).format(so)} ${tienTe}`;
    }

    const rut = (chia: number, hau: string) => {
        const gt = so / chia;

        return `${new Intl.NumberFormat('vi-VN', {
            maximumFractionDigits: gt >= 100 ? 0 : 1,
        }).format(gt)}${hau}`;
    };

    if (so >= 1_000_000_000) return rut(1_000_000_000, ' tỷ');
    if (so >= 1_000_000) return rut(1_000_000, 'tr');
    if (so >= 1_000) return rut(1_000, 'k');

    return `${new Intl.NumberFormat('vi-VN').format(so)} ₫`;
}

/**
 * Nhãn ngắn của ô khoảng giá.
 *
 * `price.unit` ra khỏi API dưới dạng người đọc đầy đủ — `màn hình/tháng` —
 * và trong một ô rộng 90px nó bị cắt thành `màn hình/th…`, tức mất đúng chữ
 * phân biệt tháng với tuần. Bỏ tiền tố, giữ kỳ: `Giá/tháng`.
 *
 * Không có bảng tra cứng: cắt tiền tố nếu thấy, còn lại in nguyên. Chuỗi này
 * do `ScreenInventory::getDisplayPriceUnitAttribute()` sinh, và nếu một ngày
 * nó đổi thì ô hiện một nhãn dài hơn mong muốn — chứ không hiện sai kỳ hay
 * trống trơn.
 */
export function nhanDonVi(donVi: string | null): string {
    if (!donVi) return 'Khoảng giá';

    const TIEN_TO = 'màn hình/';

    return donVi.startsWith(TIEN_TO) ? `Giá/${donVi.slice(TIEN_TO.length)}` : `Giá ${donVi}`;
}

export type TomTat = {
    /** Số **màn hình** khớp bộ lọc trong khung — `meta.total` của API. */
    tong: number;

    /** Lần nạp trước có chạm trần 500 pin không. */
    daCat: boolean;

    /** Bao nhiêu pin trong khung có giá thật. */
    daBaoGia: number;

    /** Khoảng giá của nhóm đơn vị đông nhất. `null` khi không có giá nào. */
    giaMin: number | null;
    giaMax: number | null;

    /** Đơn vị đo và đơn vị tiền của khoảng giá trên. */
    donVi: string | null;
    tienTe: string | null;
};

type GiaPin = { amount?: number | null; currency?: string | null; unit?: string | null };

/**
 * Bốn con số của dải tổng quan, tính từ tập pin đang hiện.
 *
 * ══ Khoảng giá chỉ lấy MỘT nhóm đơn vị, và nói rõ nhóm nào ══
 *
 * `price.unit` ra khỏi API dưới ba dạng: `màn hình/tháng`, `màn hình/tuần`,
 * `CPM` — và `price.currency` có cả VND lẫn USD, vì `floor_cpm` lưu USD
 * trong dữ liệu thật. Lấy min–max của tất cả là in một khoảng trộn giá tháng
 * với giá CPM tính bằng đô: một con số đọc được, hoàn toàn sai, và không có
 * gì trên màn hình nói rằng nó trộn.
 *
 * Nên hàm này gom theo cặp (đơn vị đo, đơn vị tiền), lấy **nhóm đông nhất**,
 * và trả kèm tên nhóm để giao diện in ra. Một khoảng giá luôn đi với nhãn
 * của chính nó.
 *
 * Hoà thì lấy nhóm gặp trước — thứ tự pin do máy chủ quyết, nên nó ổn định
 * giữa hai lần nạp cùng khung nhìn.
 */
export function tomTatKhung(pins: MapPin[], meta: MetaPin | null): TomTat {
    const nhom = new Map<string, { gia: number[]; donVi: string | null; tienTe: string }>();

    let daBaoGia = 0;

    for (const pin of pins) {
        const price = pin.price as GiaPin | undefined;
        const amount = price?.amount;

        if (!amount || amount <= 0) continue;

        daBaoGia += 1;

        const donVi = price?.unit ?? null;
        const tienTe = price?.currency || 'VND';
        const khoa = `${donVi ?? ''}|${tienTe}`;

        const o = nhom.get(khoa) ?? { gia: [], donVi, tienTe };
        o.gia.push(amount);
        nhom.set(khoa, o);
    }

    let dongNhat: { gia: number[]; donVi: string | null; tienTe: string } | null = null;

    for (const o of nhom.values()) {
        if (!dongNhat || o.gia.length > dongNhat.gia.length) dongNhat = o;
    }

    return {
        tong: meta?.total ?? pins.length,
        daCat: meta?.truncated ?? false,
        daBaoGia,
        giaMin: dongNhat ? Math.min(...dongNhat.gia) : null,
        giaMax: dongNhat ? Math.max(...dongNhat.gia) : null,
        donVi: dongNhat?.donVi ?? null,
        tienTe: dongNhat?.tienTe ?? null,
    };
}
