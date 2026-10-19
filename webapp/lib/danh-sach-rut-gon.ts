/**
 * Danh sách rút gọn — những vị trí người mua khoanh lại để hỏi giá (lát 4).
 *
 * ══ Vì sao `localStorage` chứ không phải giỏ hàng ══
 *
 * `Cart`/`CartItem` đã có, nhưng không dùng lại được: giỏ buộc đăng nhập,
 * buộc thuộc một tổ chức (`middleware('buyer')`), và một dòng giỏ cần **giá**
 * để tồn tại. Với 85/104 vị trí chưa báo giá, nhét danh sách rút gọn vào giỏ
 * là tạo ra những dòng không định giá được.
 *
 * Danh sách rút gọn là thứ khác: nó là một **câu hỏi**, giỏ hàng là một
 * **đơn**. Và bắt đăng nhập trước khi người ta kịp khoanh năm vị trí là mất
 * họ ở bước rẻ nhất.
 *
 * ══ Lưu cả tên, không chỉ slug ══
 *
 * Để xem lại danh sách mà không phải gọi API: người dùng mở ngăn kéo ở trang
 * nào cũng thấy đủ tên, thành phố và media owner. Cái giá là dữ liệu có thể
 * cũ (owner đổi tên màn hình sau khi người mua tick) — chấp nhận được, vì
 * đây là ghi chú của người mua chứ không phải bản ghi của sàn. Khi gửi yêu
 * cầu báo giá (lát 5) thì **slug** mới là thứ máy chủ tin, và máy chủ tự tra
 * lại tên.
 *
 * ══ Mọi lối vào `localStorage` đều bọc try/catch ══
 *
 * Chế độ riêng tư, site data bị chặn, hoặc bộ nhớ đầy — cả ba đều ném. Trang
 * phải chạy tiếp, chỉ mất tính nhớ.
 */

export const KHOA = 'oohx_danh_sach_rut_gon';

/**
 * Giới hạn cứng, đúng bằng `MAX_PER_PAGE` của `/api/v2/screens`.
 *
 * Không phải con số tròn cho đẹp: lát 5 sẽ gửi danh sách này lên
 * `POST /api/v2/quote-requests`, nơi mỗi mục phải được xác thực là slug có
 * thật và đang công khai. Hai đầu cùng một giới hạn thì không có trạng thái
 * "người dùng khoanh được 80 nhưng gửi chỉ nhận 50".
 */
export const TOI_DA = 50;

export type MucDaChon = {
    slug: string;
    ten: string;
    thanhPho?: string;
    owner?: string;
};

export type KetQuaThem =
    | { ok: true }
    | { ok: false; lyDo: 'day' | 'da_co' };

/** Tên sự kiện phát trong cùng một tab. `storage` chỉ bắn sang tab khác. */
const SU_KIEN = 'oohx:danh-sach-rut-gon';

/**
 * Bản chụp hiện tại và chuỗi thô sinh ra nó.
 *
 * `useSyncExternalStore` gọi `doc()` sau **mỗi** lượt render và so sánh bằng
 * `Object.is`. Trả một mảng mới mỗi lần là một vòng lặp render vô tận, nên
 * giữ nguyên tham chiếu khi chuỗi trong `localStorage` không đổi.
 */
let banChup: MucDaChon[] = [];
let thoCuoi: string | null = null;

/** Mảng rỗng **ổn định** cho phía máy chủ, cùng lý do trên. */
const RONG: MucDaChon[] = [];

function hopLe(x: unknown): x is MucDaChon {
    if (typeof x !== 'object' || x === null) return false;

    const m = x as Record<string, unknown>;

    return typeof m.slug === 'string' && m.slug !== '' && typeof m.ten === 'string';
}

export function doc(): MucDaChon[] {
    if (typeof window === 'undefined') return RONG;

    let tho: string | null = null;

    try {
        tho = window.localStorage.getItem(KHOA);
    } catch {
        return RONG;
    }

    if (tho === thoCuoi) return banChup;

    thoCuoi = tho;

    if (!tho) {
        banChup = RONG;

        return banChup;
    }

    try {
        const doc = JSON.parse(tho) as unknown;

        // Lọc từng phần tử: dữ liệu này nằm trong tay người dùng, và một
        // phiên bản cũ của trang có thể đã ghi hình dạng khác. Một phần tử
        // hỏng không được làm vỡ cả danh sách.
        banChup = Array.isArray(doc) ? doc.filter(hopLe).slice(0, TOI_DA) : RONG;
    } catch {
        banChup = RONG;
    }

    return banChup;
}

function ghi(ds: MucDaChon[]): void {
    try {
        window.localStorage.setItem(KHOA, JSON.stringify(ds));
    } catch {
        // Không lưu được thì vẫn đổi được cho lượt này: cập nhật bản chụp
        // bằng tay, vì `doc()` sẽ không đọc lại được thứ chưa ghi xuống.
        thoCuoi = null;
    }

    banChup = ds;
    thoCuoi = (() => {
        try {
            return window.localStorage.getItem(KHOA);
        } catch {
            return null;
        }
    })();

    window.dispatchEvent(new Event(SU_KIEN));
}

export function daChon(slug: string): boolean {
    return doc().some((m) => m.slug === slug);
}

export function them(muc: MucDaChon): KetQuaThem {
    const ds = doc();

    if (ds.some((m) => m.slug === muc.slug)) return { ok: false, lyDo: 'da_co' };
    if (ds.length >= TOI_DA) return { ok: false, lyDo: 'day' };

    ghi([...ds, muc]);

    return { ok: true };
}

export function bo(slug: string): void {
    const ds = doc();
    const con = ds.filter((m) => m.slug !== slug);

    if (con.length !== ds.length) ghi(con);
}

/** Tick: có thì bỏ, chưa có thì thêm. Trả về kết quả của nhánh thêm. */
export function dao(muc: MucDaChon): KetQuaThem {
    if (daChon(muc.slug)) {
        bo(muc.slug);

        return { ok: true };
    }

    return them(muc);
}

export function xoaHet(): void {
    ghi([]);
}

/** Đếm vị trí, tỉnh thành và media owner — ba con số của thanh đáy. */
export function demTheoNhom(ds: MucDaChon[]): { viTri: number; tinh: number; owner: number } {
    return {
        viTri: ds.length,
        tinh: new Set(ds.map((m) => m.thanhPho).filter(Boolean)).size,
        owner: new Set(ds.map((m) => m.owner).filter(Boolean)).size,
    };
}

/**
 * Đăng ký nghe thay đổi — chữ ký của `useSyncExternalStore`.
 *
 * Hai sự kiện, không một: `storage` chỉ bắn ở **tab khác**, nên thiếu sự
 * kiện riêng thì ô tick ở thẻ và ô tick trên bản đồ trong cùng một tab không
 * thấy nhau.
 */
export function dangKy(goiLai: () => void): () => void {
    const quaTab = () => {
        // Buộc `doc()` đọc lại: tab khác ghi thì bản chụp ở tab này đã cũ,
        // nhưng chuỗi thô mà nó so sánh thì vẫn là chuỗi cũ nó tự nhớ.
        thoCuoi = null;
        goiLai();
    };

    window.addEventListener(SU_KIEN, goiLai);
    window.addEventListener('storage', quaTab);

    return () => {
        window.removeEventListener(SU_KIEN, goiLai);
        window.removeEventListener('storage', quaTab);
    };
}

/** Bản chụp cho phía máy chủ: luôn rỗng, và luôn **cùng một** mảng. */
export function docTrenMayChu(): MucDaChon[] {
    return RONG;
}

/** Chỉ dùng trong test: quên bản chụp để lượt đọc sau chạm `localStorage`. */
export function quenBanChup(): void {
    thoCuoi = null;
    banChup = RONG;
}
