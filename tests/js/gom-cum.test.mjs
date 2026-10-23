import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

/**
 * Lát A (23/10/2026) — bản đồ gom cụm ở máy chủ.
 *
 * Ba thứ tệp này canh, và cả ba là những chỗ lát A sập **mà vẫn trông chạy**:
 *
 *  1. Client đọc `meta.mode`, không suy chế độ từ số lượng.
 *  2. Lưới an toàn đưa người dùng từ cụm xuống pin còn nguyên.
 *  3. Cụm dùng lại vòng tròn sẵn có, không đẻ vốn từ thị giác thứ hai.
 */

const hook = readFileSync(new URL('../../webapp/lib/dung-ban-do.ts', import.meta.url), 'utf8');
const api = readFileSync(new URL('../../webapp/lib/pin-api.ts', import.meta.url), 'utf8');
const dai = readFileSync(new URL('../../webapp/components/DaiTongQuan.tsx', import.meta.url), 'utf8');

test('chế độ đọc từ meta.mode, KHÔNG suy từ độ dài clusters hay data', () => {
    assert.match(
        hook,
        /body\.meta\?\.mode === 'cluster'/,
        'Hook không đọc `meta.mode` — chế độ đang được suy ra từ chỗ khác.',
    );

    // Suy theo độ dài là cái bẫy: một khung nhìn rỗng ở chế độ pin cũng có
    // `clusters` rỗng VÀ `data` rỗng, nên "không có gì ở đây" và "quá dày nên
    // trả cụm" trông giống hệt nhau — và chúng cần hai câu trả lời ngược nhau.
    assert.doesNotMatch(
        hook,
        /clusters\s*(\?\.|\.)\s*length\s*[><=]/,
        'Hook đang suy chế độ từ `clusters.length` — xem docblock của `MetaPin`.',
    );

    assert.doesNotMatch(
        hook,
        /body\.data\.length\s*===\s*0/,
        'Hook đang suy chế độ từ `data.length`.',
    );
});

test('cờ nạp lại bật theo chế độ cụm, không theo cờ truncated đã chết', () => {
    // `truncated` nay luôn `false` từ máy chủ. Một client còn đọc nó sẽ không
    // bao giờ bật lưới an toàn, và bản đồ kẹt ở cụm khi người dùng phóng to
    // vào giữa vùng đã nạp — khung mới nằm gọn trong khung cũ nên luật "chứa
    // trong" nói không cần nạp lại.
    assert.match(
        hook,
        /chuaDayDuRef\.current = body\.meta\?\.mode === 'cluster'/,
        'Lưới an toàn cụm→pin không còn, hoặc đang đọc một cờ khác.',
    );

    assert.doesNotMatch(
        hook,
        /chuaDayDuRef\.current\s*=\s*body\.meta\?\.truncated/,
        '`truncated` nay luôn false; đọc nó là tắt lưới an toàn trong im lặng.',
    );
});

test('bấm vào cụm phải phóng to — đó là đường duy nhất đi xuống pin', () => {
    // Một cụm không mở được thẻ chi tiết (nó không là một màn hình), nên nếu
    // cú bấm không làm gì thì người dùng ở khung nhìn cả nước không có cách
    // nào xuống tới một vị trí cụ thể ngoài việc tự biết phải phóng to.
    //
    // Khớp thẳng trên cả tệp, không cắt cửa sổ quanh `mode === 'cluster'`:
    // bản đầu của ca này cắt 1.600 ký tự và `marker.on('click'` của cụm nằm ở
    // ký tự thứ 1.956, nên ca đỏ oan. Hai `marker.on('click')` trong tệp phân
    // biệt được bằng chính thân hàm — cụm gọi `map.flyTo`, pin gọi `chonRef`.
    assert.match(
        hook,
        /marker\.on\('click', \(\) => \{\s*map\.flyTo/,
        'Cú bấm vào cụm không phóng to bản đồ.',
    );
});

test('cụm dùng lại cumHtml, không đẻ vòng tròn thứ hai', () => {
    // Hai nguồn cụm (máy chủ ở lát A, `leaflet.markercluster` ở phía trình
    // duyệt) nói CÙNG một điều với người dùng: "n vị trí ở đây, bấm để phóng
    // to". Vẽ chúng khác nhau là dạy một vốn từ thị giác không có nghĩa.
    assert.match(hook, /const \{ html, px \} = cumHtml\(c\.count\)/);

    assert.doesNotMatch(
        hook,
        /className: 'oohx-cum'/,
        'Có một lớp CSS riêng cho cụm máy chủ — dùng lại `.oohx-cluster`.',
    );
});

test('kiểu Cum chỉ có ba trường, không có gì nhận dạng được', () => {
    const khoi = api.slice(api.indexOf('export type Cum'), api.indexOf('export type Cum') + 200);

    // Ở mức thu phóng rộng một ô có thể chứa đúng một màn hình, và khi đó tâm
    // cụm là toạ độ của nó. Thêm `slug` hay `name` vào đây là mở một đường
    // danh sách mà không ai nghĩ là đường danh sách.
    for (const cam of ['slug', 'name', 'price', 'owner', 'address']) {
        assert.doesNotMatch(
            khoi,
            new RegExp(`\\b${cam}\\b`),
            `Kiểu \`Cum\` mang trường \`${cam}\` — một cụm là một phép đếm, không phải một đối tượng.`,
        );
    }

    assert.match(khoi, /lat: number/);
    assert.match(khoi, /lng: number/);
    assert.match(khoi, /count: number/);
});

test('dải tổng quan nói ra khi đang ở chế độ cụm', () => {
    // Ba trong bốn ô của dải đọc từ pin. Ở chế độ cụm không có pin nào, nên ô
    // "Khoảng giá —" đọc thành "vùng này chưa ai báo giá" trong khi sự thật là
    // chưa ai hỏi tới từng vị trí.
    assert.match(dai, /cheDoCum/, 'Dải tổng quan không biết về chế độ cụm.');
    assert.match(dai, /Đang xem theo cụm/);

    assert.doesNotMatch(
        dai,
        /Đã cắt ở 500 vị trí/,
        'Còn dòng "Đã cắt ở 500" — máy chủ không cắt nữa, dòng đó nay là một lời nói sai.',
    );
});
