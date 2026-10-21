'use client';

import { useCallback, useEffect, useState } from 'react';
import { useRouter } from 'next/navigation';
import type { Filters } from '@/lib/api';
import { dungKeoSheet } from '@/lib/dung-keo-sheet';

/**
 * Cột lọc của `/explore`, kèm drawer cho mobile.
 *
 * ══ Vì sao tệp này tồn tại: trang đọc bộ lọc nhưng không vẽ cái nào ══
 *
 * `/explore` đọc `city`, `venue_type`, `screen_type`, `sort` từ URL và gửi
 * thẳng sang `/api/v2/screens`. Nhưng ngoài ô tìm kiếm lớn, **không có bộ lọc
 * nào hiện ra**: muốn lọc theo owner hay loại hình thì phải tự sửa URL. Cột
 * lọc của `/products` đã có từ lát 3 ngày 14/10/2026; trang kho — trang danh
 * mục chính của sàn — thì chưa.
 *
 * ══ Nhóm đầu tiên là **trạng thái giá**, và đó là lý do cả cột này tồn tại ══
 *
 * Đo trên production ngày 18/10/2026: **1 trong 50** bản ghi có giá, và
 * `/api/v2/filters` trả `price_range {min: 0, max: 0}` — thanh lọc giá không
 * lọc được gì. Nên nhóm này hỏi một câu trả lời được (*đã có giá chưa?*) thay
 * cho một câu không trả lời được (*giá nằm trong khoảng nào?*).
 *
 * Con số đi kèm làm người ta khó chịu, và nó nên thế: *chưa báo giá 103* là
 * sự thật của kho, không phải một lỗi hiển thị.
 *
 * ══ Dùng lại bộ class của `/products`, không đặt tên mới ══
 *
 * `.sidebar`, `.sb-card`, `.sb-item`, `.sb-checkbox`, `.sb-count`,
 * `.filter-float`, `.drawer-drag` đã có đủ trong `frontpage.css` và đã chạy ở
 * `/products`. Đặt một bộ `.lk-*` song song là hai cột lọc trông khác nhau
 * trên cùng một trang web, và là hai chỗ phải sửa mỗi lần đổi khoảng cách.
 *
 * Hệ quả: cột lọc hiện từ **1024px**, không phải 900px như bản kế hoạch viết.
 * `.sidebar` là `display:none` dưới 1024px và chỉ hiện dưới dạng drawer — đổi
 * mốc đó là đổi cả `/products`. Một mốc chung đáng giá hơn 124px.
 *
 * ══ Lọc đi qua URL ══
 *
 * Mỗi bộ lọc là một URL chia sẻ được, quay lại được, index được — đúng cách
 * trang này đang làm. State ở đây chỉ là **bản nháp** cho tới khi bấm "Áp
 * dụng"; nguồn sự thật vẫn là `params` từ máy chủ.
 */

type ThamSo = Record<string, string | undefined>;

/** `"a,b"` → `Set{a,b}`. Nhận cả `|` vì `FrontpageListingRequest` cũng nhận. */
function tachDanhSach(gia_tri?: string): Set<string> {
    if (!gia_tri) return new Set();

    return new Set(
        gia_tri
            .split(/[,|]/)
            .map((s) => s.trim())
            .filter(Boolean),
    );
}

/** Số lượng tối đa mỗi nhóm dài, trước khi cắt. */
const CAT_TINH = 12;
const CAT_LOAI_DIEM = 10;

function doiTap(tap: Set<string>, gia_tri: string): Set<string> {
    const moi = new Set(tap);
    moi.has(gia_tri) ? moi.delete(gia_tri) : moi.add(gia_tri);

    return moi;
}

/**
 * Một dòng tick.
 *
 * ══ Định nghĩa ở cấp module, KHÔNG lồng trong `BoLocKho` ══
 *
 * Bản đầu đặt hàm này bên trong thân component. Mỗi lần render tạo ra một
 * **danh tính hàm mới**, nên React coi đó là một loại component khác và
 * **tháo rồi gắn lại** toàn bộ cây con sau mỗi lần tick. Đo được ở 1440px:
 * tick "Chưa báo giá" rồi tick "Billboard" thì ô thứ hai không ăn — nút đã
 * bị thay bằng một nút khác giữa hai cú bấm, và cú bấm thứ hai rơi vào một
 * nút đã rời khỏi tài liệu. Ô nhập chữ trong cùng cây cũng sẽ mất con trỏ
 * theo đúng cơ chế đó.
 *
 * `tat` = nhóm này đếm 0. Hiện mờ và vô hiệu chứ **không ẩn**: một loại hình
 * hiện 0 nói với người mua rằng sàn biết loại đó tồn tại nhưng chưa có hàng;
 * ẩn nó đi thì người mua tưởng sàn không bán loại đó.
 */
function OTick({
    ten,
    gia_tri,
    nhan,
    so,
    tap,
    dat,
}: {
    ten: string;
    gia_tri: string;
    nhan: string;
    so?: number | null;
    tap: Set<string>;
    dat: (t: Set<string>) => void;
}) {
    const dangChon = tap.has(gia_tri);
    const tat = so === 0 && !dangChon;

    return (
        <label
            className={dangChon ? 'sb-item on' : tat ? 'sb-item sb-item-tat' : 'sb-item'}
            aria-disabled={tat || undefined}
        >
            {/*
              Input thật, ẩn bằng CSS nội tuyến như `/products`:
              `.sb-checkbox` là ô vuông vẽ bằng CSS, nhưng phần tử nhận bàn
              phím và đọc được bởi trình đọc màn hình vẫn phải là input thật.
            */}
            <input
                type="checkbox"
                name={ten}
                value={gia_tri}
                checked={dangChon}
                disabled={tat}
                onChange={() => dat(doiTap(tap, gia_tri))}
                style={{ display: 'none' }}
            />
            <div className="sb-checkbox" />
            <span>{nhan}</span>
            {so === null || so === undefined ? null : <span className="sb-count">{so}</span>}
        </label>
    );
}

export function BoLocKho({
    filters,
    params = {},
}: {
    filters: Filters | null;
    params?: ThamSo;
}) {
    const router = useRouter();

    const [mo, setMo] = useState(false);
    const [giaTrangThai, setGiaTrangThai] = useState<Set<string>>(() =>
        tachDanhSach(params.price_status),
    );
    const [loaiManHinh, setLoaiManHinh] = useState<Set<string>>(() =>
        tachDanhSach(params.screen_type),
    );
    const [tinhThanh, setTinhThanh] = useState<Set<string>>(() => tachDanhSach(params.city));
    const [loaiDiem, setLoaiDiem] = useState<Set<string>>(() => tachDanhSach(params.venue_type));
    const [chuSoHuu, setChuSoHuu] = useState<Set<string>>(() => tachDanhSach(params.owner));

    const soBoLoc =
        giaTrangThai.size + loaiManHinh.size + tinhThanh.size + loaiDiem.size + chuSoHuu.size;

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

    function apDung() {
        const url = new URLSearchParams();

        // `q` thuộc ô tìm kiếm lớn phía trên, không thuộc cột này — giữ
        // nguyên thay vì xoá, nếu không thì đổi một bộ lọc là lặng lẽ bỏ từ
        // khoá người dùng đã gõ.
        if (params.q) url.set('q', params.q);

        if (giaTrangThai.size) url.set('price_status', [...giaTrangThai].join(','));
        if (loaiManHinh.size) url.set('screen_type', [...loaiManHinh].join(','));
        if (tinhThanh.size) url.set('city', [...tinhThanh].join(','));
        if (loaiDiem.size) url.set('venue_type', [...loaiDiem].join(','));
        if (chuSoHuu.size) url.set('owner', [...chuSoHuu].join(','));

        // `sort` giữ lại: nó thuộc thanh kết quả, không thuộc cột lọc. `page`
        // thì bỏ — trang 7 của bộ lọc cũ không còn nghĩa gì.
        if (params.sort) url.set('sort', params.sort);

        const qs = url.toString();
        setMo(false);
        router.push(qs ? `/explore?${qs}` : '/explore');
    }

    function xoaHet() {
        setGiaTrangThai(new Set());
        setLoaiManHinh(new Set());
        setTinhThanh(new Set());
        setLoaiDiem(new Set());
        setChuSoHuu(new Set());
        setMo(false);
        router.push(params.q ? `/explore?q=${encodeURIComponent(params.q)}` : '/explore');
    }

    const coBoLoc = soBoLoc > 0;

    const dongDrawer = useCallback(() => setMo(false), []);

    // Tay cầm kéo được: cùng một hook với ngăn danh sách của `/map`, vì đó là
    // cùng một cử chỉ trên cùng một hình dạng.
    const { refNgan, rong, tay } = dungKeoSheet<HTMLFormElement>({
        mo,
        dong: dongDrawer,
        nhan: 'bộ lọc',
    });

    return (
        <>
            <form
                ref={refNgan}
                method="GET"
                action="/explore"
                className={mo ? (rong ? 'sidebar open sheet-rong' : 'sidebar open') : 'sidebar'}
                onSubmit={(e) => {
                    e.preventDefault();
                    apDung();
                }}
            >
                {/*
                  Tay cầm của drawer. CSS chỉ hiện nó khi `.sidebar.open`, và
                  chỉ dưới 1024px — ở desktop cột lọc là một cột tĩnh.
                */}
                <div className="drawer-drag" {...tay}>
                    <div className="drawer-drag-bar" />
                </div>

                <div className="sidebar-scroll">
                    {/*
                      Trạng thái giá đứng ĐẦU, trên cả tỉnh thành. Với 103/104
                      vị trí chưa báo giá, đây là câu hỏi đầu tiên của bất kỳ
                      người mua nào — và là câu duy nhất mà thanh lọc giá cũ
                      không trả lời được.
                    */}
                    <div className="sb-card">
                        <div className="sb-title">Trạng thái giá</div>
                        <div className="sb-group">
                            <OTick
                                ten="price_status"
                                gia_tri="quoted"
                                nhan="Đã có giá"
                                so={filters?.price_status?.quoted}
                                tap={giaTrangThai}
                                dat={setGiaTrangThai}
                            />
                            <OTick
                                ten="price_status"
                                gia_tri="unquoted"
                                nhan="Chưa báo giá"
                                so={filters?.price_status?.unquoted}
                                tap={giaTrangThai}
                                dat={setGiaTrangThai}
                            />
                        </div>
                    </div>

                    {filters?.screen_types?.length ? (
                        <div className="sb-card">
                            <div className="sb-title">Loại hình</div>
                            <div className="sb-group">
                                {filters.screen_types.map((t) =>
                                    t.code ? (
                                        <OTick
                                            key={t.code}
                                            ten="screen_type"
                                            gia_tri={t.code}
                                            nhan={t.name ?? t.code}
                                            so={t.count}
                                            tap={loaiManHinh}
                                            dat={setLoaiManHinh}
                                        />
                                    ) : null,
                                )}
                            </div>
                        </div>
                    ) : null}

                    {filters?.cities?.length ? (
                        <div className="sb-card">
                            <div className="sb-title">Tỉnh thành</div>
                            <div className="sb-group">
                                {/*
                                  `code` chứ không `name`: `/api/v2/screens`
                                  nhận slug thành phố và tự mở ra tên tiếng
                                  Việt (`expandCitySlugs`). Gửi tên có dấu thì
                                  nó vẫn khớp nhờ nhánh dự phòng, nhưng URL
                                  sinh ra dài gấp ba và không giống URL mà ô
                                  tìm kiếm lớn tạo.
                                */}
                                {filters.cities.slice(0, CAT_TINH).map((c) =>
                                    c.code ? (
                                        <OTick
                                            key={c.code}
                                            ten="city"
                                            gia_tri={c.code}
                                            nhan={c.name ?? c.code}
                                            so={c.count}
                                            tap={tinhThanh}
                                            dat={setTinhThanh}
                                        />
                                    ) : null,
                                )}
                            </div>
                        </div>
                    ) : null}

                    {filters?.venue_types?.length ? (
                        <div className="sb-card">
                            <div className="sb-title">Loại điểm đặt</div>
                            <div className="sb-group">
                                {filters.venue_types.slice(0, CAT_LOAI_DIEM).map((v) =>
                                    v.code ? (
                                        <OTick
                                            key={v.code}
                                            ten="venue_type"
                                            gia_tri={v.code}
                                            nhan={v.name ?? v.code}
                                            so={v.count}
                                            tap={loaiDiem}
                                            dat={setLoaiDiem}
                                        />
                                    ) : null,
                                )}
                            </div>
                        </div>
                    ) : null}

                    {filters?.owners?.length ? (
                        <div className="sb-card">
                            <div className="sb-title">Media owner</div>
                            <div className="sb-group">
                                {filters.owners.map((o) =>
                                    o.slug ? (
                                        <OTick
                                            key={o.slug}
                                            ten="owner"
                                            gia_tri={o.slug}
                                            nhan={o.name ?? o.slug}
                                            so={o.count}
                                            tap={chuSoHuu}
                                            dat={setChuSoHuu}
                                        />
                                    ) : null,
                                )}
                            </div>
                        </div>
                    ) : null}

                    {/* Giữ hai thứ không thuộc cột này qua lượt gửi không-JS. */}
                    <input type="hidden" name="q" value={params.q ?? ''} />
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
                            onClick={xoaHet}
                        >
                            Xoá bộ lọc
                        </button>
                    ) : null}
                </div>
            </form>

            {/*
              Nút nổi, chỉ hiện dưới 1024px. Nằm trong `.listing-layout`
              nhưng `position:fixed`, nên nó không chiếm một ô của lưới hai
              cột — xem chú thích dài hơn ở `BoLocGoi`.
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
