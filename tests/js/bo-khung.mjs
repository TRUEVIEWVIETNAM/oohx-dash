import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';
import { JSDOM, VirtualConsole } from 'jsdom';

const GOC = resolve(dirname(fileURLToPath(import.meta.url)), '../..');

export const MOC_DOM = JSON.parse(
    readFileSync(resolve(GOC, 'tests/js/moc-dom.json'), 'utf8'),
);

/**
 * Trích khối `<script>` HÀNH VI ra khỏi một tệp `.blade.php`.
 *
 * Mỗi trang có đúng hai thẻ script: khối cấu hình
 * `<script type="application/json">` và khối hành vi `<script>` không thuộc
 * tính. Biểu thức dưới đây chỉ khớp khối thứ hai, và **đòi đúng một** — hai
 * khối hành vi nghĩa là tệp đã đổi hình dạng và test đang canh một nửa mà
 * không nói ra.
 *
 * Vì sao trích chứ không chép: chép là có hai bản, và bản trong test sẽ không
 * đổi khi bản trên production đổi. Lúc đó test vẫn xanh về một mã không còn
 * chạy ở đâu — tệ hơn là không có test.
 */
export function layScriptHanhVi(duongDanTuongDoi) {
    const nguon = readFileSync(resolve(GOC, duongDanTuongDoi), 'utf8');

    const khop = [...nguon.matchAll(/<script>\n([\s\S]*?)\n<\/script>/g)];

    if (khop.length !== 1) {
        throw new Error(
            `${duongDanTuongDoi}: cần đúng một khối <script> hành vi, tìm thấy ${khop.length}. `
            + 'Nếu trang thật đổi hình dạng thì sửa bộ khung này cùng lượt, '
            + 'đừng để test canh một nửa.',
        );
    }

    const than = khop[0][1];

    // Cú pháp echo của Blade trong khối script nghĩa là mã thật phụ thuộc PHP,
    // và bản trích ra ở đây không còn là mã đó nữa. Nói ngay thay vì để nó vỡ
    // thành một lỗi cú pháp JS không ai hiểu.
    for (const dau of ['{{', '{!!']) {
        if (than.includes(dau)) {
            throw new Error(
                `${duongDanTuongDoi}: khối script chứa "${dau}" của Blade. `
                + 'Mọi giá trị từ PHP phải đi qua khối cấu hình json, không nhúng thẳng vào JS.',
            );
        }
    }

    return than;
}

/**
 * Mọi móc `[data-*]` mà script THẬT đi tìm.
 *
 * ══ Vì sao cần phép kiểm này, dù đã có phép kiểm phía PHP ══
 *
 * Tôi đã thử đột biến: đổi tên `data-cart-total` trong Blade (cả thẻ lẫn
 * `querySelector`) và để `moc-dom.json` nguyên. Phía PHP đỏ ngay — đúng như
 * thiết kế. Nhưng phía JS chỉ đỏ **một** ca trong mười bốn, vì script bắt
 * `null` ở `elTotal.textContent` **bên trong một `.then()`**, nên chính
 * `.catch()` của nó hứng lấy và trang rơi vào nhánh "không tải được". Mười ba
 * ca còn lại vẫn xanh.
 *
 * Tức một móc biến mất không làm trang vỡ ồn ào; nó làm trang hiện một thông
 * báo lỗi sai nguyên nhân. Đó là triệu chứng khó lần nhất.
 *
 * Nên phía JS cũng cần một phép kiểm **đối xứng** với phía PHP: tập móc script
 * đi tìm phải trùng khít tập móc được khai. Thiếu thì đỏ, thừa thì cũng đỏ —
 * thêm một `querySelector` mới mà quên khai là thêm một móc không ai canh.
 */
export function mocScriptDiTim(trang) {
    const than = layScriptHanhVi(trang);

    return [...new Set([...than.matchAll(/\[(data-[a-z0-9-]+)\]/g)].map((m) => m[1]))].sort();
}

/**
 * Tập móc được KHAI cho một trang: khối cấu hình cộng các móc trang phải có.
 *
 * `tuTao` KHÔNG nằm trong tập này. Đó là các móc script tự gắn vào phần tử do
 * chính nó vẽ ra (ví dụ `data-cd-huy` trên form hủy), nên trang Blade không có
 * chúng và phía PHP không đòi chúng. Nhưng chúng vẫn phải được khai, vì phép
 * kiểm trùng khít so với tập móc script ĐI TÌM — không khai thì nó báo "thừa".
 */
export function mocDuocKhai(trang) {
    const d = MOC_DOM[trang];

    if (! d) {
        throw new Error(`Chưa khai móc DOM cho ${trang} trong tests/js/moc-dom.json`);
    }

    return [d.cauHinh, ...d.moc].sort();
}

/** Móc script tự tạo — khai trong `tuTao`, loại khỏi phép so trùng khít. */
export function mocTuTao(trang) {
    return [...(MOC_DOM[trang]?.tuTao ?? [])].sort();
}

/**
 * Tập móc script đi tìm, đã TRỪ các móc nó tự tạo.
 *
 * Đây là tập đem so với `mocDuocKhai()`. Trừ ở một chỗ, không trừ trong từng
 * tệp test: trừ trong test là mỗi test một cách trừ.
 */
export function mocScriptCanTrang(trang) {
    const tuTao = new Set(mocTuTao(trang));

    return mocScriptDiTim(trang).filter((m) => ! tuTao.has(m));
}

/**
 * Dựng một trang giả rồi chạy script thật trên đó.
 *
 * Bộ khung DOM dựng **từ `moc-dom.json`**, không viết tay: cùng tệp mà
 * `MocDomTrangBladeTest` dùng để đòi trang thật có đủ các móc đó. Hai phía đọc
 * một nguồn nên không trôi khỏi nhau được.
 *
 * @param {string} trang      đường dẫn tệp blade, như khóa trong `moc-dom.json`
 * @param {object} cauHinh    nội dung khối cấu hình
 * @param {Function} traLoi   (url) => { status, body } | Promise, hoặc ném lỗi mạng
 */
export function dungTrang(trang, { cauHinh, traLoi }) {
    const dinhNghia = MOC_DOM[trang];

    if (! dinhNghia) {
        throw new Error(`Chưa khai móc DOM cho ${trang} trong tests/js/moc-dom.json`);
    }

    const khung = dinhNghia.moc.map((m) => `<div ${m}></div>`).join('\n');

    /**
     * Mọi thứ jsdom từ chối làm, giữ lại thay vì để nó trôi ra stderr.
     *
     * Đây là cách duy nhất bắt được `location.reload()`: jsdom không cho ghi
     * lên `location.reload` (thuộc tính chỉ đọc), nên không stub được nó. Thay
     * vào đó jsdom phát một `jsdomError` kiểu "Not implemented: navigation",
     * và đó là bằng chứng script đã cố nạp lại trang.
     *
     * Nó cũng bắt luôn mọi lỗi script không ai hứng — một `TypeError` ở giữa
     * hàm vẽ sẽ để DOM dở dang, và nếu không nghe ở đây thì test chỉ thấy
     * "phần tử rỗng" mà không biết vì sao.
     */
    const loiJsdom = [];
    const banDieuKhien = new VirtualConsole();
    banDieuKhien.on('jsdomError', (e) => loiJsdom.push(String(e && e.message ? e.message : e)));

    const dom = new JSDOM(
        `<!doctype html><html><body>
            <script type="application/json" ${dinhNghia.cauHinh}>${JSON.stringify(cauHinh)}</script>
            ${khung}
        </body></html>`,
        {
            runScripts: 'outside-only',
            url: 'https://oohx.net/trang-thu',
            virtualConsole: banDieuKhien,
        },
    );

    const win = dom.window;

    /** Mọi URL script đã gọi, theo thứ tự — để test khẳng định nó gọi ĐÚNG đường. */
    const daGoi = [];

    win.fetch = (url, tuyChon) => {
        daGoi.push(String(url));

        let kq;
        try {
            kq = traLoi(String(url), tuyChon);
        } catch (e) {
            return Promise.reject(e);
        }

        return Promise.resolve(kq).then((r) => {
            if (r === null || r === undefined) {
                return Promise.reject(new Error('mạng hỏng'));
            }

            const status = r.status ?? 200;

            return {
                ok: status >= 200 && status < 300,
                status,
                // `json()` trả promise, và phải TỪ CHỐI được: script có nhánh
                // riêng cho "máy chủ trả dữ liệu không đọc được", và nhánh đó
                // cần test.
                json: () => (r.khongPhaiJson
                    ? Promise.reject(new Error('không phải JSON'))
                    : Promise.resolve(r.body)),
            };
        });
    };

    win.eval(layScriptHanhVi(trang));

    return { dom, win, tai: win.document, daGoi, loiJsdom };
}

/**
 * Nhường lượt cho chuỗi promise của script chạy xong.
 *
 * Script gọi `fetch` ngay khi nạp, và không phơi ra promise nào để chờ. Xả vài
 * vòng macrotask là cách duy nhất từ bên ngoài; 20 vòng dư cho ba tầng `then`
 * sâu nhất của hai trang.
 */
export async function choVeXong(vong = 20) {
    for (let i = 0; i < vong; i++) {
        await new Promise((r) => setImmediate(r));
    }
}

/** Nội dung chữ của phần tử theo móc, đã bỏ khoảng trắng hai đầu. */
export function chuCua(tai, moc) {
    const el = tai.querySelector(`[${moc}]`);

    if (! el) {
        throw new Error(`Không có phần tử [${moc}] — bộ khung và moc-dom.json lệch nhau`);
    }

    return el.textContent.trim();
}
