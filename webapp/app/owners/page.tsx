import type { Metadata } from 'next';
import Link from 'next/link';
import { listOwners, getFilters } from '@/lib/api';
import { buildMetadata, OgType } from '@/lib/seo';
import { SiteHeader } from '@/components/SiteHeader';
import { Pagination } from '@/components/Pagination';
import { OwnerCard } from '@/components/OwnerCard';

/**
 * `/owners` — danh sách media owner.
 *
 * ══ Không in một con số "verified" cho cả danh sách ══
 *
 * Bản Blade từng in "VERIFIED PARTNERS" và "N+ đối tác verified", trong khi
 * `getOwnersPaginated()` chỉ lọc `status = 'active'` — nó đếm owner đang hoạt
 * động rồi gọi chúng là đã xác minh. Đã sửa ở bản Blade ngày 03/10 (audit
 * F-15), và trang này không mang câu đó sang.
 *
 * ══ Dấu "Đã xác minh" trên thẻ owner cũng đã gỡ (18/10/2026) ══
 *
 * Từ 14/10/2026 thẻ owner có dấu "Đã xác minh" **cho từng owner**, đọc cột
 * `owners.verified`. Lập luận lúc đó: đó là một câu khác với "N+ đối tác
 * verified", vì nó nói về một owner cụ thể và nó có nguồn.
 *
 * Lập luận ấy đúng một nửa. Cột có thật, nhưng nó là **một cờ quản trị bật
 * tay** — không có quy trình kiểm định nào đứng sau, không có hồ sơ, không
 * có bước đối chiếu. `KhoiLoiHua.tsx` đã ghi đúng điều đó từ đầu: *"cột
 * `owners.verified` có thật nhưng là cờ thủ công, không phải một quy trình
 * kiểm định"*. Hai chỗ trong cùng một repo nói ngược nhau về cùng một cột.
 *
 * Chữ "đã xác minh" nói với người mua rằng **sàn** đã kiểm, và sàn thì
 * chưa. Lát 3 làm nó lộ ra: dấu đó đứng ngay dưới tiêu đề mới của trang chủ
 * — "Bạn đặt trực tiếp với chủ màn hình" — bên cạnh ba dòng bảo đảm mà từng
 * dòng đều chỉ ra được một điều có thật của sàn.
 *
 * Cột `owners.verified` **vẫn còn** trong CSDL và trong `OwnerSummary`; chỉ
 * có chỗ hiển thị đi. Khi nào có một quy trình kiểm định thật thì dấu này
 * quay lại cùng với nó, và lúc đó nó sẽ nói đúng.
 *
 * Cũng không có dấu "+" sau con số: `meta.total` là số đúng, không phải "hơn".
 */

const PER_PAGE = 24;

export const metadata: Metadata = buildMetadata({
    title: 'Media Owners | OOHX',
    description:
        'Danh sách chủ sở hữu màn hình OOH/DOOH đang hoạt động trên OOHX. ' +
        'Đặt booking trực tiếp, không qua trung gian.',
    path: '/owners',
});

type SearchParams = { page?: string; q?: string; type?: string };

export default async function OwnersPage({
    searchParams,
}: {
    searchParams: Promise<SearchParams>;
}) {
    const params = await searchParams;
    const page = Math.max(1, Number.parseInt(params.page ?? '1', 10) || 1);

    // Hai lời gọi song song: danh sách owner và bộ lọc cho chip loại hình.
    // `getFilters()` có `revalidate` riêng (dài hơn) vì nhóm loại hình đổi rất
    // ít, nên gộp chúng vào một endpoint là buộc hai nhịp cache thành một.
    const [{ data: owners, meta }, filters] = await Promise.all([
        listOwners({
            page,
            per_page: PER_PAGE,
            q: params.q,
            type: params.type,
        }),
        getFilters(),
    ]);

    const venueTypes = (filters?.venue_types ?? []).slice(0, 6);

    /** URL của một chip — giữ `q` đang lọc, bỏ `page` vì thứ tự đổi. */
    function duongDanChip(type?: string): string {
        const qs = new URLSearchParams();

        if (type) qs.set('type', type);
        if (params.q) qs.set('q', params.q);

        const s = qs.toString();

        return s ? `/owners?${s}` : '/owners';
    }

    return (
        <>
            <SiteHeader active="owners" />
            <OgType type="website" />

            <main>
                <div className="pg-hero">
                    <div className="w">
                        <div
                            style={{
                                fontSize: 13,
                                fontWeight: 700,
                                color: 'var(--bl)',
                                letterSpacing: '.3px',
                                marginBottom: 8,
                            }}
                        >
                            ĐỐI TÁC
                        </div>
                        <h1>Media Owners</h1>
                        <p>
                            {new Intl.NumberFormat('vi-VN').format(meta.total ?? 0)} đối tác đang
                            hoạt động. Đặt booking trực tiếp không qua trung gian.
                        </p>

                        {/*
                          Form GET, không `onChange` + state: mỗi lượt tìm là
                          một URL, nên chia sẻ được và quay lại được — và trang
                          vẫn là server component. `type` đi kèm dạng hidden để
                          tìm trong phạm vi chip đang chọn, đúng như bản Blade.
                        */}
                        <form className="pg-hero-search" method="GET" action="/owners">
                            <svg
                                viewBox="0 0 24 24"
                                fill="currentColor"
                                style={{ width: 18, height: 18, flexShrink: 0 }}
                                aria-hidden="true"
                            >
                                <path d="M15.5 14h-.79l-.28-.27A6.47 6.47 0 0 0 16 9.5 6.5 6.5 0 1 0 9.5 16c1.61 0 3.09-.59 4.23-1.57l.27.28v.79l5 4.99L20.49 19l-4.99-5zm-6 0C7.01 14 5 11.99 5 9.5S7.01 5 9.5 5 14 7.01 14 9.5 11.99 14 9.5 14z" />
                            </svg>
                            <input
                                name="q"
                                defaultValue={params.q ?? ''}
                                placeholder="Tìm media owner theo tên, khu vực, format…"
                                aria-label="Tìm media owner"
                            />
                            {params.type ? (
                                <input type="hidden" name="type" value={params.type} />
                            ) : null}
                        </form>
                    </div>
                </div>

                <div className="w">
                    {/*
                      Chip là **link**, không phải nút có JS. Bản Blade từng gắn
                      click chỉ để đổi class `on`: chip sáng lên như đã lọc
                      trong khi danh sách không đổi một dòng (audit F-15). Nay
                      chip nào đang chọn do `?type=` quyết, nên trạng thái hiển
                      thị không thể lệch khỏi dữ liệu đang hiện.
                    */}
                    {venueTypes.length > 0 ? (
                        <div className="cat-chips">
                            <Link
                                className={params.type ? 'cat-chip' : 'cat-chip on'}
                                href={duongDanChip()}
                            >
                                Tất cả
                            </Link>

                            {venueTypes.map((vt) => (
                                <Link
                                    key={vt.code}
                                    className={
                                        params.type === vt.code ? 'cat-chip on' : 'cat-chip'
                                    }
                                    href={duongDanChip(vt.code ?? undefined)}
                                >
                                    {vt.name} ({new Intl.NumberFormat('vi-VN').format(vt.count ?? 0)})
                                </Link>
                            ))}
                        </div>
                    ) : null}

                    <div className="results-header">
                        <div className="rh-count">
                            Hiển thị{' '}
                            <strong>
                                {new Intl.NumberFormat('vi-VN').format(meta.total ?? 0)}
                            </strong>{' '}
                            media owner
                        </div>
                    </div>

                    {owners.length === 0 ? (
                        <div
                            style={{ padding: '64px 20px', textAlign: 'center', color: 'var(--t4)' }}
                        >
                            Không có đối tác nào khớp bộ lọc.
                        </div>
                    ) : (
                        <div className="oc-grid oc-grid--full">
                            {owners.map((owner, i) => (
                                <OwnerCard key={owner.slug} owner={owner} index={i} />
                            ))}
                        </div>
                    )}

                    <Pagination basePath="/owners" params={params} meta={meta} />
                </div>
            </main>
        </>
    );
}
