'use client';

import { useEffect, useState } from 'react';
import { useRouter } from 'next/navigation';
import type { ProductFilters } from '@/lib/api';

/**
 * Sidebar lọc của `/products`, kèm drawer cho mobile.
 *
 * Dựng lại phần bộ lọc của `frontpage/products/index.blade.php`: bốn thẻ
 * `sb-card` (tìm kiếm, loại sản phẩm, danh mục, thành phố), nút "Áp dụng",
 * nút "Xoá bộ lọc", nút nổi `filter-float` và tay cầm `drawer-drag` để đóng
 * trên mobile.
 *
 * Trước lát 3, `/products` của bản Next là một lưới phẳng: **không bộ lọc
 * nào**, dù `/api/v2/products` nhận `q`, `category`, `type`, `city`, `owner`,
 * `min_price`, `max_price`, `sort`.
 *
 * ══ Vì sao URL dùng dấu phẩy, không dùng `category[]` ══
 *
 * Bản Blade đặt `name="category[]"`, nên PHP nhận mảng. Ở đây tên trường là
 * `category` và nhiều giá trị được ghép thành `category=led,lcd` — hợp đồng
 * `style: form, explode: false` đã khai trong `docs/openapi/v2.yaml`, và cùng
 * dạng mà `MegaSearch` sinh ra từ lát 2. Hai dạng trong cùng một ứng dụng thì
 * sớm muộn có người đọc một dạng và quên dạng kia.
 *
 * ══ Form vẫn là `<form method="GET">`, và điều đó có chủ ý ══
 *
 * Phép gửi được chặn để tự ghép dấu phẩy, nhưng thẻ `<form>` thật với
 * `action="/products"` vẫn ở đó: không có JS thì nút "Áp dụng" vẫn chuyển
 * trang, chỉ là mỗi khóa lặp lại và PHP lấy **giá trị cuối** — một bộ lọc
 * hẹp hơn mong đợi, chứ không phải một nút chết. Suy giảm êm, không phải suy
 * giảm im lặng.
 *
 * ══ `filter-backdrop` KHÔNG được dựng lại ══
 *
 * `frontpage.css:1145` đặt `.filter-backdrop{display:none}` rồi `display:none
 * !important` ở desktop, kèm chú thích "removed, panel is opaque white". Ở bản
 * Blade nó là một div không bao giờ hiện, mà JS vẫn gắn `click` để đóng
 * drawer — tức một trình xử lý không bao giờ chạy. Dựng lại là dựng lại một
 * thứ chết.
 */

type ThamSo = Record<string, string | undefined>;

/** `"a,b"` → `Set{a,b}`. Nhận cả `|` vì `ProductListingRequest` cũng nhận. */
function tachDanhSach(gia_tri?: string): Set<string> {
    if (!gia_tri) return new Set();

    return new Set(
        gia_tri
            .split(/[,|]/)
            .map((s) => s.trim())
            .filter(Boolean),
    );
}

export function BoLocGoi({
    filters,
    params = {},
}: {
    filters: ProductFilters | null;
    params?: ThamSo;
}) {
    const router = useRouter();

    const [mo, setMo] = useState(false);
    const [q, setQ] = useState(params.q ?? '');
    const [type, setType] = useState(params.type ?? '');
    const [categories, setCategories] = useState<Set<string>>(() => tachDanhSach(params.category));
    const [cities, setCities] = useState<Set<string>>(() => tachDanhSach(params.city));

    const soBoLoc =
        categories.size + cities.size + (q.trim() ? 1 : 0) + (type ? 1 : 0);

    // Khoá cuộn trang khi drawer mở, và trả lại khi đóng hoặc khi component
    // rời khỏi cây. Thiếu phần dọn thì rời trang trong lúc drawer mở để lại
    // `overflow:hidden` trên `<body>` và trang sau không cuộn được nữa.
    useEffect(() => {
        if (!mo) return;

        const truoc = document.body.style.overflow;
        document.body.style.overflow = 'hidden';

        return () => {
            document.body.style.overflow = truoc;
        };
    }, [mo]);

    function doiTap(tap: Set<string>, gia_tri: string): Set<string> {
        const moi = new Set(tap);
        moi.has(gia_tri) ? moi.delete(gia_tri) : moi.add(gia_tri);

        return moi;
    }

    function apDung() {
        const url = new URLSearchParams();

        if (q.trim()) url.set('q', q.trim());
        if (type) url.set('type', type);
        if (categories.size) url.set('category', [...categories].join(','));
        if (cities.size) url.set('city', [...cities].join(','));

        // `sort` giữ lại: nó thuộc thanh kết quả, không thuộc sidebar, và đổi
        // bộ lọc không có nghĩa là người dùng muốn bỏ cách sắp xếp đã chọn.
        // `page` thì bỏ — trang 7 của bộ lọc cũ không còn nghĩa gì.
        if (params.sort) url.set('sort', params.sort);

        const qs = url.toString();
        setMo(false);
        router.push(qs ? `/products?${qs}` : '/products');
    }

    const coBoLoc = soBoLoc > 0;

    return (
        <>
            <form
                method="GET"
                action="/products"
                className={mo ? 'sidebar open' : 'sidebar'}
                onSubmit={(e) => {
                    e.preventDefault();
                    apDung();
                }}
            >
                {/*
                  Tay cầm của drawer. CSS chỉ hiện nó khi `.sidebar.open`, và
                  chỉ dưới 1024px — ở desktop sidebar là một cột tĩnh.
                */}
                <div
                    className="drawer-drag"
                    onClick={() => setMo(false)}
                    role="button"
                    tabIndex={0}
                    aria-label="Đóng bộ lọc"
                    onKeyDown={(e) => {
                        if (e.key === 'Enter' || e.key === ' ') setMo(false);
                    }}
                >
                    <div className="drawer-drag-bar" />
                </div>

                <div className="sidebar-scroll">
                    <div className="sb-card">
                        <div className="sb-title">Tìm kiếm</div>
                        {/*
                          `sb-input`, không `sb-search-inp`: bản Blade dùng tên
                          thứ hai cùng một đống style nội tuyến — và
                          `frontpage.css:1179` đã có `.sb-input` với **đúng**
                          bộ thuộc tính đó, cộng thêm `:focus`. Tên class kia
                          không có định nghĩa nào.
                        */}
                        <input
                            type="text"
                            name="q"
                            value={q}
                            onChange={(e) => setQ(e.target.value)}
                            placeholder="Tên sản phẩm, khu vực..."
                            className="sb-input"
                            aria-label="Tìm kiếm sản phẩm"
                        />
                    </div>

                    {filters?.types?.length ? (
                        <div className="sb-card">
                            <div className="sb-title">Loại sản phẩm</div>
                            <div className="sb-group">
                                {filters.types.map((t) =>
                                    t.slug ? (
                                        <label
                                            key={t.slug}
                                            className={type === t.slug ? 'sb-item on' : 'sb-item'}
                                        >
                                            {/*
                                              Radio thật, ẩn bằng CSS nội tuyến
                                              như bản Blade: `.sb-checkbox` là
                                              ô vuông vẽ bằng CSS, nhưng phần
                                              tử nhận bàn phím và đọc được bởi
                                              trình đọc màn hình vẫn phải là
                                              input thật.

                                              Bấm lại lựa chọn đang chọn thì
                                              bỏ chọn — bản Blade không làm
                                              được (radio HTML không bỏ chọn
                                              được), nên "Loại sản phẩm" ở đó
                                              là một lựa chọn vào rồi không ra.
                                            */}
                                            <input
                                                type="radio"
                                                name="type"
                                                value={t.slug}
                                                checked={type === t.slug}
                                                onChange={() => setType(t.slug ?? '')}
                                                onClick={() => {
                                                    if (type === t.slug) setType('');
                                                }}
                                                style={{ display: 'none' }}
                                            />
                                            <div className="sb-checkbox" />
                                            <span>{t.label ?? t.slug}</span>
                                            <span className="sb-count">{t.count}</span>
                                        </label>
                                    ) : null,
                                )}
                            </div>
                        </div>
                    ) : null}

                    {filters?.categories?.length ? (
                        <div className="sb-card">
                            <div className="sb-title">Danh mục</div>
                            <div className="sb-group">
                                {filters.categories.map((c) =>
                                    c.slug ? (
                                        <label
                                            key={c.slug}
                                            className={
                                                categories.has(c.slug) ? 'sb-item on' : 'sb-item'
                                            }
                                        >
                                            <input
                                                type="checkbox"
                                                name="category"
                                                value={c.slug}
                                                checked={categories.has(c.slug)}
                                                onChange={() =>
                                                    setCategories(doiTap(categories, c.slug ?? ''))
                                                }
                                                style={{ display: 'none' }}
                                            />
                                            <div className="sb-checkbox" />
                                            <span>{c.label ?? c.slug}</span>
                                            <span className="sb-count">{c.count}</span>
                                        </label>
                                    ) : null,
                                )}
                            </div>
                        </div>
                    ) : null}

                    {filters?.cities?.length ? (
                        <div className="sb-card">
                            <div className="sb-title">Thành phố</div>
                            <div className="sb-group">
                                {/*
                                  12 thành phố đầu, đúng như bản Blade
                                  (`->take(12)`). Endpoint trả tối đa 20 và đã
                                  sắp theo số lượng giảm dần, nên 12 cái đầu là
                                  12 thành phố nhiều gói nhất — không phải 12
                                  cái bất kỳ.
                                */}
                                {filters.cities.slice(0, 12).map((c) =>
                                    c.name ? (
                                        <label
                                            key={c.name}
                                            className={
                                                cities.has(c.name) ? 'sb-item on' : 'sb-item'
                                            }
                                        >
                                            <input
                                                type="checkbox"
                                                name="city"
                                                value={c.name}
                                                checked={cities.has(c.name)}
                                                onChange={() => setCities(doiTap(cities, c.name ?? ''))}
                                                style={{ display: 'none' }}
                                            />
                                            <div className="sb-checkbox" />
                                            <span>{c.name}</span>
                                            <span className="sb-count">{c.count}</span>
                                        </label>
                                    ) : null,
                                )}
                            </div>
                        </div>
                    ) : null}

                    {/* Giữ `sort` qua lượt gửi không-JS, đúng cách bản Blade giữ. */}
                    <input type="hidden" name="sort" value={params.sort ?? ''} />

                    <button
                        type="submit"
                        className="btn btn-p"
                        style={{ width: '100%', justifyContent: 'center', borderRadius: 10 }}
                    >
                        Áp dụng
                    </button>

                    {coBoLoc ? (
                        <button
                            type="button"
                            className="btn btn-s"
                            style={{
                                width: '100%',
                                justifyContent: 'center',
                                borderRadius: 10,
                                marginTop: 6,
                            }}
                            onClick={() => {
                                setQ('');
                                setType('');
                                setCategories(new Set());
                                setCities(new Set());
                                setMo(false);
                                router.push('/products');
                            }}
                        >
                            Xoá bộ lọc
                        </button>
                    ) : null}
                </div>
            </form>

            {/*
              Nút nổi, chỉ hiện dưới 1024px (`frontpage.css:1154`). Nó mở
              drawer; `.filter-float.hidden` là cách bản Blade ẩn nó trong lúc
              drawer mở.
            */}
            <button
                className={mo ? 'filter-float hidden' : 'filter-float'}
                type="button"
                onClick={() => setMo(true)}
                aria-expanded={mo}
            >
                <svg viewBox="0 0 24 24" fill="#fff" aria-hidden="true">
                    <path d="M10 18h4v-2h-4v2zM3 6v2h18V6H3zm3 7h12v-2H6v2z" />
                </svg>
                Bộ lọc
                {coBoLoc ? <span className="filter-float-count">{soBoLoc}</span> : null}
            </button>
        </>
    );
}
