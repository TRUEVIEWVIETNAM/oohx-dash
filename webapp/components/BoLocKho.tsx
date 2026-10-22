'use client';

import { useCallback, useEffect, useRef, useState } from 'react';
import { useRouter } from 'next/navigation';
import type { Filters } from '@/lib/api';
import {
    KHOA_LOC,
    type ChonLoc,
    type KhoaLoc,
    type ThamSoLoc,
    demChon,
    docChon,
    duongDanLoc,
    khoaLocDauTien,
} from '@/lib/loc-url';

/**
 * Bộ lọc kho — một dải **chip**, dùng ở cả hai kiểu xem của `/explore`.
 *
 * ══ Vì sao cột lọc dọc bị gỡ (lát 3, 22/10/2026) ══
 *
 * Đo trên `oohx.net/explore` ở 1440×900 ngày 21/10/2026: bọc nội dung rộng
 * 1176px, trong đó cột lọc lấy **260px (22%)** và **cao 1358px** — cao hơn cả
 * khung nhìn. Nó phục vụ 23 ô tick mà người mua chạm vài lần rồi thôi, và nó
 * lấy chỗ của đúng thứ họ tới để xem: thẻ màn hình, lúc đó còn 2 thẻ 255px.
 *
 * Gỡ cột đi, lưới lấy lại cả 1176px: **4 thẻ 280px** ở 1200px+ thay vì 2 thẻ
 * 255px. Cùng một bộ lọc, cùng một hợp đồng URL, nhưng xếp ngang thành chip
 * nên nó cao 38px chứ không 1358px.
 *
 * ══ Một bộ lọc, hai kiểu xem, một tệp ══
 *
 * Kiểu lưới đặt dải chip trên lưới; kiểu bản đồ đặt đúng dải đó vào đầu panel
 * danh sách. Hai chỗ, một component — vì `/explore` và `/map` vừa gộp lại
 * chính vì chúng đã có **hai** bộ lọc trôi khỏi nhau (`BoLocKho` 405 dòng và
 * `BoLocThongMinh` 330 dòng, cho cùng một kho 104 vị trí). Dựng bộ thứ ba cho
 * kiểu xem bản đồ là lặp lại đúng cái lỗi vừa sửa.
 *
 * ══ `<details>` chứ không phải nút + state ══
 *
 * Mỗi chip là một `<summary>`, bảng rớt là phần nội dung của `<details>`. Ba
 * thứ có sẵn mà không phải viết: mở bằng bàn phím, `aria-expanded` đúng, và
 * — quan trọng nhất — **mở được khi JavaScript chưa tải xong**. Cột lọc cũ
 * giữ được lời hứa "submit được không cần JS" nhờ dựng sẵn mọi ô tick trong
 * HTML; một bảng rớt dựng có điều kiện (`{mo && …}`) sẽ phá lời hứa đó mà
 * không có gì đỏ lên, vì mọi phép kiểm đều chạy với JS bật.
 *
 * Trạng thái mở vẫn do React giữ (để đóng cái này khi mở cái kia), nên
 * `onClick` của `<summary>` gọi `preventDefault()`: cú bấm không còn tự lật
 * `open`, và thuộc tính đó chỉ đến từ state. Không có JS thì không có handler
 * nào, và hành vi gốc của trình duyệt làm việc của nó.
 *
 * ══ Mỗi ô tick xuất hiện ĐÚNG MỘT lần trong tài liệu ══
 *
 * Form vẫn là một `<form method="GET">` thật, nên lượt gửi không-JS mang theo
 * mọi hộp đang tick. Vì thế chip `Bộ lọc n` **không** dựng một bản sao của
 * năm nhóm trong một ngăn kéo riêng: nó chỉ mở cả năm `<details>` tại chỗ và
 * CSS xếp chúng thành cột (`.ex-loc.tat-ca`). Hai bộ input cùng `name` trong
 * một form là hai giá trị cho cùng một khoá — và khoá đó sẽ chỉ còn giá trị
 * cuối.
 *
 * ══ Lọc vẫn đi qua URL ══
 *
 * Mỗi bộ lọc là một URL chia sẻ được, quay lại được, index được. State ở đây
 * chỉ là **bản nháp** cho tới khi bấm "Áp dụng"; nguồn sự thật vẫn là `params`
 * từ máy chủ. Luật dựng URL sống ở `@/lib/loc-url` và được chốt bằng
 * `tests/js/loc-url.test.mjs`.
 */

/** Số lượng tối đa mỗi nhóm dài, trước khi cắt. */
const CAT_TINH = 12;
const CAT_LOAI_DIEM = 10;

type Muc = { gia_tri: string; nhan: string; so?: number | null };

type Nhom = { khoa: KhoaLoc; nhan: string; muc: Muc[] };

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

/**
 * Năm nhóm, dựng từ `/api/v2/filters`.
 *
 * Nhóm rỗng thì không có chip — một chip mở ra bảng rớt trống là một lời mời
 * bấm vào chỗ không có gì. Nhưng nhóm có **mục đếm 0** thì vẫn ra chip, vì
 * mục đếm 0 là thông tin (xem `OTick`).
 */
function dungNhom(filters: Filters | null): Nhom[] {
    const nhoms: Nhom[] = [
        {
            khoa: 'price_status',
            nhan: 'Trạng thái giá',
            muc: [
                { gia_tri: 'quoted', nhan: 'Đã có giá', so: filters?.price_status?.quoted },
                { gia_tri: 'unquoted', nhan: 'Chưa báo giá', so: filters?.price_status?.unquoted },
            ],
        },
    ];

    const loai: Muc[] = (filters?.screen_types ?? []).flatMap((t) =>
        t.code ? [{ gia_tri: t.code, nhan: t.name ?? t.code, so: t.count }] : [],
    );

    if (loai.length) nhoms.push({ khoa: 'screen_type', nhan: 'Loại hình', muc: loai });

    /*
     * `code` chứ không `name`: `/api/v2/screens` nhận slug thành phố và tự mở
     * ra tên tiếng Việt (`expandCitySlugs`). Gửi tên có dấu thì nó vẫn khớp
     * nhờ nhánh dự phòng, nhưng URL sinh ra dài gấp ba và không giống URL mà
     * ô tìm kiếm lớn tạo.
     */
    const tinh: Muc[] = (filters?.cities ?? [])
        .slice(0, CAT_TINH)
        .flatMap((c) => (c.code ? [{ gia_tri: c.code, nhan: c.name ?? c.code, so: c.count }] : []));

    if (tinh.length) nhoms.push({ khoa: 'city', nhan: 'Tỉnh thành', muc: tinh });

    const diem: Muc[] = (filters?.venue_types ?? [])
        .slice(0, CAT_LOAI_DIEM)
        .flatMap((v) => (v.code ? [{ gia_tri: v.code, nhan: v.name ?? v.code, so: v.count }] : []));

    if (diem.length) nhoms.push({ khoa: 'venue_type', nhan: 'Loại điểm đặt', muc: diem });

    const owner: Muc[] = (filters?.owners ?? []).flatMap((o) =>
        o.slug ? [{ gia_tri: o.slug, nhan: o.name ?? o.slug, so: o.count }] : [],
    );

    if (owner.length) nhoms.push({ khoa: 'owner', nhan: 'Media owner', muc: owner });

    return nhoms;
}

/**
 * Chữ trên chip: tên nhóm khi chưa lọc, **giá trị** khi đã lọc.
 *
 * Đó là điểm khác duy nhất đáng kể giữa dải chip này và cột lọc cũ: nhìn một
 * dòng là đọc được cả bộ lọc đang chạy, thay vì phải cuộn một cột 1358px để
 * tìm xem ô nào đang tick.
 *
 * Giá trị đang chọn có thể **không** nằm trong `muc` — `cities` bị cắt ở 12
 * và URL thì không. Khi đó in thẳng mã, vì mã vẫn đúng hơn là in tên nhóm và
 * giả vờ chưa lọc gì.
 */
function nhanChip(nhom: Nhom, tap: Set<string>): string {
    if (!tap.size) return nhom.nhan;

    const dau = nhom.muc.find((m) => tap.has(m.gia_tri))?.nhan ?? [...tap][0];

    return tap.size === 1 ? dau : `${dau} +${tap.size - 1}`;
}

export function BoLocKho({
    filters,
    params = {},
}: {
    filters: Filters | null;
    params?: ThamSoLoc;
}) {
    const router = useRouter();
    const refForm = useRef<HTMLFormElement>(null);
    const refChips = useRef<HTMLDivElement>(null);

    /** Nhóm nào đang mở bảng rớt; `null` là đóng hết. */
    const [moNhom, setMoNhom] = useState<KhoaLoc | null>(null);

    /** Chip `Bộ lọc n`: mở cả năm nhóm tại chỗ, xếp thành cột. */
    const [moHet, setMoHet] = useState(false);

    const [chon, setChon] = useState<ChonLoc>(() => docChon(params));

    /**
     * URL đổi thì bản nháp đổi theo.
     *
     * Thiếu phần này thì nút Back để lại một dải chip nói sai: địa chỉ về bộ
     * lọc cũ còn chip vẫn đọc theo lần tick cuối. Ở cột lọc dọc lỗi đó nằm
     * trong một cột đóng nên không ai thấy; ở đây nó nằm ngay trên lưới.
     *
     * Khoá của effect là năm giá trị đã nối chuỗi, không phải cả `params` —
     * `page` đổi thì bản nháp không có gì phải đổi.
     */
    const khoaUrl = KHOA_LOC.map((k) => params[k] ?? '').join('|');

    useEffect(() => {
        setChon(docChon(params));
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [khoaUrl]);

    /**
     * Dải chip cuộn tới chip đang lọc.
     *
     * Dưới 1024px và trong panel bản đồ, dải chip là một hàng cuộn ngang. Đo
     * trên production ngày 22/10/2026 ngay sau khi lát 3 lên: panel rộng
     * 372px, dải chip dài 898px, và chip `Media owner` đang lọc nằm ở offset
     * **541** — ngoài tầm nhìn. Danh sách đã bị cắt từ 104 xuống 83 vị trí mà
     * trên màn hình không có một dấu hiệu nào nói vì sao.
     *
     * Chạy theo `khoaUrl`, tức theo bộ lọc **đã áp dụng**, không theo bản
     * nháp: cuộn dải chip trong lúc người dùng đang tick là giật thứ họ đang
     * chạm. Hàng không cuộn được (desktop, chip xuống hàng) thì không làm gì.
     */
    useEffect(() => {
        const dai = refChips.current;

        if (!dai || dai.scrollWidth <= dai.clientWidth) return;

        const khoa = khoaLocDauTien(docChon(params));
        const chip = khoa ? dai.querySelector<HTMLElement>(`[data-khoa="${khoa}"]`) : null;

        // `offsetLeft` đo từ `.ex-loc` (`position:relative`), và dải chip là
        // con trực tiếp không lề của nó — nên hai gốc trùng nhau. 8px chừa ra
        // để chip không dính sát mép trái.
        dai.scrollLeft = chip ? Math.max(0, chip.offsetLeft - 8) : 0;
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [khoaUrl]);

    /**
     * Bấm ra ngoài hoặc gõ Esc thì đóng bảng rớt.
     *
     * Chỉ đóng `moNhom`, **không** đóng `moHet`: bảng rớt là một tấm nổi đè
     * lên nội dung, còn `Bộ lọc n` chỉ nở dải chip ra tại chỗ và đẩy lưới
     * xuống. Thứ nổi lên thì đóng khi bấm ra ngoài; thứ nằm trong mạch nội
     * dung thì không — đóng nó là lấy mất chỗ người dùng vừa mở ra đọc.
     */
    useEffect(() => {
        if (!moNhom) return;

        const ngoai = (e: Event) => {
            if (!refForm.current?.contains(e.target as Node)) setMoNhom(null);
        };

        const phim = (e: KeyboardEvent) => {
            if (e.key === 'Escape') setMoNhom(null);
        };

        document.addEventListener('pointerdown', ngoai);
        document.addEventListener('keydown', phim);

        return () => {
            document.removeEventListener('pointerdown', ngoai);
            document.removeEventListener('keydown', phim);
        };
    }, [moNhom]);

    const nhoms = dungNhom(filters);
    const soBoLoc = demChon(chon);

    const bamChip = useCallback((khoa: KhoaLoc) => {
        setMoHet(false);
        setMoNhom((cur) => (cur === khoa ? null : khoa));
    }, []);

    function doiNhom(khoa: KhoaLoc, tap: Set<string>) {
        setChon((cur) => ({ ...cur, [khoa]: tap }));
    }

    function di(moi: ChonLoc) {
        setChon(moi);
        setMoNhom(null);
        router.push(duongDanLoc(params, moi));
    }

    function apDung() {
        setMoNhom(null);
        setMoHet(false);
        router.push(duongDanLoc(params, chon));
    }

    return (
        <form
            ref={refForm}
            method="GET"
            action="/explore"
            className={moHet ? 'ex-loc tat-ca' : 'ex-loc'}
            onSubmit={(e) => {
                e.preventDefault();
                apDung();
            }}
        >
            <div className="ex-loc-chips" ref={refChips}>
                {nhoms.map((nhom) => {
                    const tap = chon[nhom.khoa];

                    return (
                        <details
                            key={nhom.khoa}
                            data-khoa={nhom.khoa}
                            className="ex-loc-nhom"
                            open={moHet || moNhom === nhom.khoa}
                        >
                            {/*
                              `preventDefault()` tắt hành vi lật `open` của
                              trình duyệt, nên thuộc tính đó chỉ đến từ state
                              và mở nhóm này là đóng nhóm kia. Không có JS thì
                              không có handler, và hành vi gốc làm việc của nó
                              — đó là cả lý do chip là `<summary>`.
                            */}
                            <summary
                                className={tap.size ? 'fchip on' : 'fchip'}
                                onClick={(e) => {
                                    e.preventDefault();
                                    bamChip(nhom.khoa);
                                }}
                            >
                                <span>{nhanChip(nhom, tap)}</span>

                                {tap.size ? (
                                    /*
                                      Nút gỡ nằm TRONG `<summary>`, nên cú bấm
                                      vào nó phải chặn cả hai đường: nổi bọt
                                      lên handler trên (`stopPropagation`) và
                                      hành vi lật `open` của trình duyệt
                                      (`preventDefault`). Thiếu một trong hai
                                      thì gỡ bộ lọc cũng mở bảng rớt.

                                      28px, không 44px: nó ngồi *bên trong*
                                      một vùng chạm 44px, và nới nó ra 44 là
                                      ăn gần hết bề ngang của chip — chạm vào
                                      chữ sẽ thành gỡ bộ lọc. 28px vẫn trên
                                      mức 24px tối thiểu của WCAG 2.5.8.
                                    */
                                    <button
                                        type="button"
                                        className="ex-chip-xoa"
                                        aria-label={`Gỡ bộ lọc ${nhom.nhan}`}
                                        onClick={(e) => {
                                            e.preventDefault();
                                            e.stopPropagation();
                                            di({ ...chon, [nhom.khoa]: new Set<string>() });
                                        }}
                                    >
                                        <svg viewBox="0 0 24 24" aria-hidden="true">
                                            <path d="M18.3 5.7 12 12l-6.3-6.3-1.4 1.4L10.6 13.4l-6.3 6.3 1.4 1.4L12 14.8l6.3 6.3 1.4-1.4-6.3-6.3 6.3-6.3z" />
                                        </svg>
                                    </button>
                                ) : (
                                    <svg className="ex-chip-mui" viewBox="0 0 24 24" aria-hidden="true">
                                        <path d="M7 10l5 5 5-5z" />
                                    </svg>
                                )}
                            </summary>

                            <div className="ex-loc-rot">
                                <div className="sb-title">{nhom.nhan}</div>

                                <div className="sb-group">
                                    {nhom.muc.map((m) => (
                                        <OTick
                                            key={m.gia_tri}
                                            ten={nhom.khoa}
                                            gia_tri={m.gia_tri}
                                            nhan={m.nhan}
                                            so={m.so}
                                            tap={tap}
                                            dat={(t) => doiNhom(nhom.khoa, t)}
                                        />
                                    ))}
                                </div>

                                <button type="submit" className="btn btn-p ex-loc-ap">
                                    Áp dụng
                                </button>
                            </div>
                        </details>
                    );
                })}

                <button
                    type="button"
                    className={soBoLoc ? 'fchip on ex-loc-het' : 'fchip ex-loc-het'}
                    onClick={() => {
                        setMoNhom(null);
                        setMoHet((v) => !v);
                    }}
                    aria-expanded={moHet}
                >
                    <svg viewBox="0 0 24 24" aria-hidden="true" className="ex-chip-mui">
                        <path d="M10 18h4v-2h-4v2zM3 6v2h18V6H3zm3 7h12v-2H6v2z" />
                    </svg>
                    {moHet ? 'Thu gọn' : 'Bộ lọc'}
                    {soBoLoc ? <span className="fchip-count">{soBoLoc}</span> : null}
                </button>

                {soBoLoc ? (
                    <button type="button" className="ex-loc-xoa" onClick={() => di(docChon({}))}>
                        Xoá bộ lọc
                    </button>
                ) : null}

                {/*
                  Mở cả năm nhóm thì có MỘT nút áp dụng, không năm.
                  Mỗi bảng rớt mang nút của riêng nó vì khi nổi lên một mình
                  nó là cả biểu mẫu người dùng đang nhìn; xếp cả năm thành cột
                  thì năm nút giống hệt nhau chồng lên nhau, và chúng lấy
                  176px chiều cao để nói đúng một việc. CSS ẩn chúng trong
                  `.tat-ca`.
                */}
                {moHet ? (
                    <button type="submit" className="btn btn-p ex-loc-ap ex-loc-ap-het">
                        Áp dụng bộ lọc
                    </button>
                ) : null}

                {/*
                  Giữ ba thứ không thuộc bộ lọc qua lượt gửi KHÔNG-JS. Với JS
                  thì `duongDanLoc` lo việc này; không có JS thì form gửi
                  thẳng, và thiếu `view` ở đây là mỗi lần áp dụng bộ lọc trong
                  kiểu xem bản đồ lại bị ném về kiểu lưới.
                */}
                <input type="hidden" name="q" value={params.q ?? ''} />
                <input type="hidden" name="sort" value={params.sort ?? ''} />
                {params.view === 'map' ? <input type="hidden" name="view" value="map" /> : null}
            </div>
        </form>
    );
}
