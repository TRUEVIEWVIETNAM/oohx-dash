import Link from 'next/link';
import type { OwnerSummary } from '@/lib/home';

/**
 * Thẻ một media owner.
 *
 * ══ Vì sao tệp này tồn tại ══
 *
 * Bản trước vẽ thẻ owner ngay trong `/owners` và trong trang chủ, bằng sáu tên
 * class **không ai định nghĩa**: `oc`, `oc-cover`, `oc-body`, `oc-name`,
 * `oc-stats`, `oc-type`. Không có trong `frontpage.css`, không có trong khối
 * `<style>` nào. Nên thẻ owner ra DOM mà không có một kiểu nào — kể cả khung.
 *
 * `frontpage.css` dòng 1836 còn ghi lại chính chuyện đó:
 *
 *     /* Old .owners-layout / .oc removed — replaced by .oc-card system above *\/
 *
 * Bộ đúng là `oc-card-*`, và `/agency` trong cùng repo vẫn đang dùng nó. Tệp
 * này là bản dựng lại của `partials/owner-card.blade.php` cho hai biến thể mà
 * trang công khai cần: `full` (trang `/owners`) và `compact` (trang chủ).
 *
 * ══ Biến thể `mini` cố ý chưa dựng ══
 *
 * Bản Blade có biến thể thứ ba, `mini`, dùng ở **trang chi tiết màn hình** để
 * giới thiệu chủ sở hữu. Trang đó chưa có khối owner nào (lát 4 của
 * `docs/PLAN-DUNG-LAI-GIAO-DIEN-CU.md`), nên thêm biến thể vào đây bây giờ là
 * thêm mã không có chỗ gọi.
 *
 * ══ Nút "Liên hệ" chưa dựng lại, và đó là một quyết định ══
 *
 * Chân thẻ của bản Blade có hai nút: "Xem inventory" và "Liên hệ". Cả hai là
 * `<span>` trong cùng một `<a>` trỏ về trang owner — tức "Liên hệ" thật ra chỉ
 * mở trang owner, nơi có khối thông tin liên hệ. Trang owner bản Next **chưa**
 * có khối đó (lát 5), nên một nút "Liên hệ" hôm nay dẫn tới một trang không có
 * gì để liên hệ. Giữ lại một nút cho tới khi lát 5 dựng khối liên hệ.
 */
/**
 * Bảng màu cho ảnh bìa và logo chữ khi owner chưa tải ảnh lên.
 *
 * Chép đúng bản Blade, kể cả thứ tự: nó chọn theo **chỉ số trong danh sách**,
 * nên hai owner cạnh nhau không bao giờ cùng màu. Màu không mang thông tin gì
 * — nó chỉ để một lưới thẻ chưa có ảnh không thành một khối xám.
 */
const MAU = [
    ['#E8EAFF', '#2A4FF6'],
    ['#E3F8E8', '#34C759'],
    ['#F3E8FF', '#AF52DE'],
    ['#FFE8E8', '#FF3B30'],
    ['#E8F4FF', '#0071E3'],
    ['#FFF3E8', '#FF9F0A'],
];

function chuDau(ten: string): string {
    return ten
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((w) => [...w][0] ?? '')
        .join('')
        .toUpperCase();
}

export function OwnerCard({
    owner,
    index = 0,
    variant = 'full',
}: {
    owner: OwnerSummary & { tagline?: string | null };
    index?: number;
    variant?: 'full' | 'compact';
}) {
    const full = variant === 'full';
    const [bg, fg] = MAU[index % MAU.length];
    const ten = owner.name ?? '';
    const tags = (owner.venue_types ?? []).slice(0, full ? 3 : 2);
    const classThe =
        'oc-card' +
        (full ? '' : ' oc-card--compact') +
        (owner.featured ? ' oc-card--feat' : '');

    return (
        <Link href={`/owners/${owner.slug}`} className={classThe}>
            {owner.featured ? <div className="oc-card-feat-badge">Featured</div> : null}

            <div className="oc-card-cover">
                {owner.cover_url ? (
                    // eslint-disable-next-line @next/next/no-img-element
                    <img src={owner.cover_url} alt={ten} loading="lazy" />
                ) : (
                    // Bản Blade gọi placehold.co cho ảnh bìa trống. Ở đây dùng
                    // một khối màu tại chỗ: một trang công khai không nên phụ
                    // thuộc một dịch vụ ngoài để vẽ xong, và ảnh thay thế đó
                    // không mang thông tin nào.
                    <div style={{ width: '100%', height: '100%', background: bg }} />
                )}
                <div className="oc-card-cover-ov" />
                <div className="oc-card-cover-badges">
                    <span className="badge b-bl">
                        {new Intl.NumberFormat('vi-VN').format(owner.screen_count ?? 0)} màn hình
                    </span>
                </div>
            </div>

            <div className="oc-card-head">
                {owner.logo_url ? (
                    // eslint-disable-next-line @next/next/no-img-element
                    <img src={owner.logo_url} className="oc-card-logo" alt={ten} loading="lazy" />
                ) : (
                    <div
                        className="oc-card-logo oc-card-logo--txt"
                        style={{ background: bg, color: fg }}
                    >
                        {chuDau(ten)}
                    </div>
                )}

                <div className="oc-card-name-wrap">
                    <div className="oc-card-name">{ten}</div>
                    {owner.verified ? (
                        <div className="oc-card-ver">
                            <svg
                                viewBox="0 0 24 24"
                                fill="var(--bl)"
                                style={{ width: 12, height: 12, flexShrink: 0 }}
                                aria-hidden="true"
                            >
                                <path d="M23 12l-2.44-2.79.34-3.69-3.61-.82-1.89-3.2L12 2.96 8.6 1.5 6.71 4.69 3.1 5.5l.34 3.7L1 12l2.44 2.79-.34 3.7 3.61.82 1.89 3.2L12 21.04l3.4 1.46 1.89-3.19 3.61-.82-.34-3.69L23 12zm-12.91 4.72l-3.8-3.81 1.48-1.48 2.32 2.33 5.85-5.87 1.48 1.48-7.33 7.35z" />
                            </svg>
                            Đã xác minh
                        </div>
                    ) : null}
                </div>
            </div>

            {full && owner.tagline ? <div className="oc-card-desc">{owner.tagline}</div> : null}

            {tags.length > 0 ? (
                <div className="oc-card-tags">
                    {tags.map((t) => (
                        <span className="oc-card-tag" key={t}>
                            {t}
                        </span>
                    ))}
                </div>
            ) : null}

            <div className="oc-card-stats">
                <div className="oc-card-stat">
                    <div className="oc-card-stat-n">
                        {new Intl.NumberFormat('vi-VN').format(owner.screen_count ?? 0)}
                    </div>
                    <div className="oc-card-stat-l">Màn hình</div>
                </div>

                {/*
                  `city_count` null nghĩa là **không đếm**, khác 0 nghĩa là đếm
                  rồi và không có tỉnh nào. Không có số thì không in ô — in "0"
                  cho trường hợp đầu là bịa một con số (audit F-15).
                */}
                {owner.city_count !== null && owner.city_count !== undefined ? (
                    <div className="oc-card-stat">
                        <div className="oc-card-stat-n">
                            {new Intl.NumberFormat('vi-VN').format(owner.city_count)}
                        </div>
                        <div className="oc-card-stat-l">Tỉnh thành</div>
                    </div>
                ) : null}
            </div>

            {full ? (
                <div className="oc-card-foot">
                    <span
                        className="btn btn-s btn-sm"
                        style={{ flex: 1, justifyContent: 'center' }}
                    >
                        Xem inventory
                    </span>
                </div>
            ) : null}
        </Link>
    );
}
