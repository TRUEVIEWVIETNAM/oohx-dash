import type { NextConfig } from 'next';

/**
 * Giai đoạn 6 — trang công khai trên Next.js.
 *
 * ══ Chạy sau một proxy, không chạy một mình ══
 *
 * OpenLiteSpeed đứng trước và chỉ chuyển một số đường dẫn sang đây (xem
 * `docs/deploy/nextjs-proxy/`). Phần chưa chuyển vẫn do Laravel phục vụ, trên
 * **cùng một tên miền** — nên đường dẫn phải giữ nguyên tuyệt đối. CLAUDE.md
 * mục 3: "Giữ nguyên đường dẫn cũ khi thay trang Blade."
 *
 * Hệ quả cụ thể: không bật `trailingSlash`, không `basePath`, không
 * `redirects()` nào tự ý. Một dấu gạch chéo thêm vào là một URL khác với URL
 * Google đang index.
 */
const nextConfig: NextConfig = {
    // Vẫn để Next tự dò lỗi kiểu khi build. Tắt nó là biến CI thành thứ chỉ
    // kiểm được cú pháp — và với app này thì kiểm kiểu CHÍNH LÀ phép kiểm hợp
    // đồng API: kiểu sinh từ `docs/openapi/v2.yaml`, nên đặc tả đổi mà code
    // không đổi theo thì build đỏ.
    typescript: { ignoreBuildErrors: false },

    /**
     * `/map` khai tử — lát 7 của `docs/PLAN-GOP-EXPLORE-BAN-DO.md`.
     *
     * Bản đồ không còn là một trang riêng: nó là kiểu xem thứ hai của
     * `/explore` (lát 2). Giữ hai đường tới cùng một kho là giữ đúng cái bệnh
     * việc gộp vừa chữa — hai bộ lọc, hai bản đồ, hai cách bày danh sách.
     *
     * Docblock trên tệp này nói "không `redirects()` nào **tự ý**". Cái này
     * không tự ý: nó là một đường dẫn cũ được khai tử có chủ đích, và nó
     * **giữ nguyên** mọi đường dẫn khác.
     *
     * **301, không 308.** `permanent: true` của Next phát 308; ở đây khai
     * `statusCode` thẳng vì plan chốt 301, và 301 là mã mọi công cụ SEO cũ
     * đều hiểu. Khác biệt kỹ thuật duy nhất — 308 giữ nguyên method khi
     * chuyển hướng POST — không áp dụng cho một trang công khai chỉ nhận GET.
     *
     * **Đích là `?view=map`, không phải `/explore` trần** như plan viết.
     * Người bấm một backlink về bản đồ tới để xem bản đồ; ném họ vào lưới là
     * lấy mất thứ họ nhắm tới. Giá trị SEO vẫn dồn về `/explore` vì
     * `buildMetadata({ path: '/explore' })` đặt canonical là `/explore` cho
     * mọi tham số truy vấn.
     *
     * Next ghép thêm tham số truy vấn cũ vào đích, nên `/map?city=hcm` ra
     * `/explore?city=hcm&view=map` — bộ lọc trong backlink sống sót.
     *
     * Đường dẫn vẫn phải đi qua Next (OpenLiteSpeed vẫn chuyển `/map` sang
     * đây), nên `/map` **ở lại** trong `TRONG_APP`. Bỏ nó khỏi đó là để
     * Laravel nhận `/map` và trả 404 thay vì chuyển hướng.
     */
    async redirects() {
        return [
            { source: '/map', destination: '/explore?view=map', statusCode: 301 },
        ];
    },

    // Không khai `eslint` ở đây: Next 16 đã bỏ khoá đó khỏi `NextConfig` và
    // cảnh báo "Unrecognized key(s)". Lint là việc của lệnh riêng.

    // KHÔNG dùng `output: 'standalone'`.
    //
    // Nó gọn khi deploy bằng cách chép artefact sang máy khác. Ở đây thì
    // `deploy.sh` build NGAY trên máy chủ, nên `node_modules` đã có mặt và
    // `next start` chạy được — thêm `standalone` chỉ sinh thêm một bản sao
    // vài trăm MB mà không ai dùng.
    //
    // Và nó còn đổi cách khởi động: với `outputFileTracingRoot` trỏ ra gốc
    // repo, điểm vào thành `webapp/.next/standalone/webapp/server.js`. Một
    // đường dẫn như vậy trong unit systemd là chỗ sẽ sai khi cấu trúc thư mục
    // đổi, và sai kiểu service không khởi động được.
    //
    // Vẫn khai gốc truy vết: repo có HAI `package.json` (gốc cho Vite, và cái
    // này), nên thiếu dòng dưới Next sẽ tự đoán gốc workspace và cảnh báo.
    outputFileTracingRoot: require('path').join(__dirname, '..'),

    images: {
        // Ảnh màn hình do media owner tải lên, phục vụ từ chính tên miền này
        // qua Laravel. Không khai `remotePatterns` rộng: cho phép tối ưu ảnh từ
        // host bất kỳ là biến trình tối ưu thành một proxy tải ảnh hộ người lạ.
        //
        // ══ Vì sao danh sách chứ không một giá trị ══
        //
        // Bản trước viết `process.env.OOHX_PUBLIC_HOST ?? 'oohx.net'` — một
        // host duy nhất, do biến môi trường quyết định. Trên production biến
        // đó **không phải `oohx.net`**, nên danh sách cho phép không chứa
        // chính tên miền đang phục vụ ảnh.
        //
        // Hậu quả, đo ngay sau khi deploy #110 (18/10/2026): mười ảnh của hai
        // lưới đều đi qua `/_next/image` và **cả mười trả HTTP 400** kèm
        // `"url" parameter is not allowed`.
        //
        // Đã tái hiện tại chỗ để chắc nguyên nhân, không dừng ở suy luận:
        // chạy bản cấu hình cũ với `OOHX_PUBLIC_HOST=dash.oohx.net` cho ra
        // **đúng thông điệp ấy**; chạy bản này với cùng biến cho 200 WebP.
        // (Giả thuyết đầu của tôi là biến bị đặt rỗng — thử ra "Internal
        // Server Error", thông điệp khác, nên giả thuyết đó sai.)
        //
        // Trang vẫn trông bình thường suốt thời gian đó: thẻ loại địa điểm có
        // icon, thẻ tỉnh thành có dải gradient. Không có gì để thấy ngoài
        // việc ảnh không bao giờ về.
        //
        // Nên `oohx.net` nằm **cố định** trong danh sách, và biến môi trường
        // chỉ *thêm* một host nữa. Một biến đặt sai không còn gỡ được tên
        // miền chính ra khỏi cấu hình. Lọc chuỗi rỗng là phần phụ — `??` giữ
        // nguyên chuỗi rỗng, và cùng cái bẫy đó đã làm hỏng `MAPTILER_STYLE`
        // sáng cùng ngày.
        remotePatterns: [
            ...new Set(
                [process.env.OOHX_PUBLIC_HOST, 'oohx.net']
                    .map((h) => (h ?? '').trim())
                    .filter(Boolean),
            ),
        ].map((hostname) => ({
            protocol: 'https' as const,
            hostname,

            // Chỉ thư mục ảnh công khai của Laravel. Thiếu dòng này thì trình
            // tối ưu nhận mọi đường dẫn trên tên miền, kể cả những đường sau
            // này có thể trả tệp do người dùng tải lên mà không qua kiểm.
            pathname: '/storage/**',
        })),

        // ══ Vì sao phải bật một cờ tên là "dangerously" ══
        //
        // Máy chủ có dòng `127.0.0.1  oohx.net` trong `/etc/hosts` (dòng 8,
        // đọc ngày 18/10/2026). Nên khi trình tối ưu đi lấy
        // `https://oohx.net/storage/…`, Node phân giải tên đó về **chính
        // máy**, và Next 16 chặn mọi địa chỉ nội bộ theo mặc định.
        //
        // Nó chặn bằng **đúng thông điệp** dùng cho trường hợp không khớp
        // `remotePatterns`: `"url" parameter is not allowed`. Hai nguyên nhân
        // khác hẳn nhau, một câu trả lời.
        //
        // Lý do thật chỉ nằm trong log của service, không có trong phản hồi
        // HTTP:
        //
        //     ⨯ upstream image https://oohx.net/storage/… hostname resolved
        //       to private IP ["127.0.0.1"]. If this is expected and you
        //       understand SSRF risk, use images.dangerouslyAllowLocalIP =
        //       true to continue.
        //
        // Ghi nguyên văn ở đây vì nó là thứ đáng tra cứu trước tiên lần sau:
        // `journalctl -u oohx-webapp`. Tôi đã loại trừ ba nguyên nhân khác
        // bằng cách đọc phản hồi HTTP, và cả ba lần đều sai — câu trả lời
        // nằm ở phía máy chủ suốt thời gian đó.
        //
        // Vì sao chạy đúng trên máy lập trình: ở đó `oohx.net` phân giải ra
        // Cloudflare như mọi nơi khác, không có dòng hosts kia.
        //
        // ══ Rủi ro, và vì sao nó hẹp ══
        //
        // Cờ này mở đường cho SSRF: trình tối ưu ảnh trở thành thứ đi lấy URL
        // hộ người khác, kể cả địa chỉ trong mạng nội bộ. Thứ giữ nó hẹp là
        // `remotePatterns` ngay trên: chỉ `https`, chỉ host của sàn, và chỉ
        // đường `/storage/**` — tức đúng thư mục ảnh công khai mà Laravel
        // vẫn phục vụ cho mọi khách. Một yêu cầu không khớp cả ba điều kiện
        // đó bị từ chối trước khi tới bước này.
        //
        // Cụ thể: không với tới được `169.254.169.254` (metadata máy ảo),
        // không với tới cổng nội bộ nào khác, không đọc được tệp ngoài
        // `/storage`.
        //
        // Lối sạch hơn là gỡ dòng `/etc/hosts` ấy — nhưng đó là cấu hình hệ
        // thống do aaPanel dựng, và không rõ còn gì khác đang dựa vào nó. Nếu
        // sau này gỡ được thì xoá luôn cờ này.
        dangerouslyAllowLocalIP: true,
    },

    // Không để Next phát `X-Powered-By`.
    poweredByHeader: false,
};

export default nextConfig;
