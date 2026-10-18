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
    },

    // Không để Next phát `X-Powered-By`.
    poweredByHeader: false,
};

export default nextConfig;
