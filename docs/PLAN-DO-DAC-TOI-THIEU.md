# Đường đo đạc tối thiểu — bắt đầu từ tỉ lệ bấm "Chặn"

Viết 22/10/2026 · Claude Code

## Vì sao plan này tồn tại

Ngày 22/10/2026 `/explore` bắt đầu **xin quyền vị trí khi mở trang** (#159).
Khi thuyết phục rằng cách hỏi đó chấp nhận được, tôi đã hứa ba lưới an toàn —
và lưới thứ ba là *"đếm tỉ lệ bấm Chặn"*. Hai lưới đầu đã làm. Lưới thứ ba
thì **không**: app không có một đường đo đạc nào (`grep` cho
gtag/analytics/telemetry ra rỗng).

Nên hôm nay tính năng đang chạy trên production và **không ai biết nó tốn bao
nhiêu**. Đó là con số đáng lo nhất của quyết định này:

- hỏi khi mở trang mà **90% bấm Chặn** → đã mất tính năng với 90% người dùng
  **vĩnh viễn**, vì trình duyệt nhớ cú chặn cho tới khi họ tự vào cài đặt
  site mà gỡ;
- hỏi khi mở trang mà **70% cho phép** → quyết định đúng, và không cần bàn
  lại.

Hai thế giới đó khác nhau hoàn toàn, và hôm nay chúng trông giống hệt nhau.

## Nguyên tắc: đếm, không ghi nhật ký

Plan này **không** dựng một hệ thống phân tích. Nó dựng một cái máy đếm.

| | Máy đếm (plan này) | Nhật ký sự kiện (KHÔNG làm) |
|---|---|---|
| Lưu gì | `(ngày, tên sự kiện, giá trị, loại thiết bị) → số lượt` | một hàng cho mỗi lần xảy ra |
| Có định danh ai không | **không** — không id, không IP, không user agent | có, kể cả khi "ẩn danh" |
| Bảng lớn ra sao | ~20 hàng/ngày, mãi mãi | một hàng mỗi lượt truy cập |
| Trả lời được câu nào | "bao nhiêu phần trăm" | "người này đã làm gì" |

Sự khác biệt đó không phải chuyện kỹ thuật. Một bảng đếm gộp **không chứa dữ
liệu cá nhân**, nên câu hỏi "được phép thu không, phải xin đồng ý không, giữ
bao lâu" không phát sinh. Một bảng sự kiện thì có, và nó kéo theo Nghị định
13/2023/NĐ-CP về bảo vệ dữ liệu cá nhân — việc cần người có thẩm quyền pháp
lý xem, không phải việc gắn kèm một lát giao diện.

> **Ranh giới phải giữ:** ngày nào có người muốn biết *"người dùng X đã xem
> những màn hình nào"*, đó là một plan khác, có đồng ý của người dùng, có
> thời hạn lưu, và có mục trong chính sách bảo mật. Đừng mở rộng bảng này
> thành bảng đó.

## Năm kết quả cần phân biệt, và cái khó nằm ở một trong số đó

| Giá trị | Nghĩa | Lấy từ đâu |
|---|---|---|
| `cho_phep` | người dùng bấm "Cho phép" | callback thành công |
| `tu_choi` | bấm "Chặn" | callback lỗi, `code === PERMISSION_DENIED` |
| `da_chan_tu_truoc` | trình duyệt đã nhớ "Chặn" từ phiên trước | `permissions.query` trả `denied` — **không** có hộp thoại nào bật |
| `loi` | hết thời gian chờ, không lấy được vị trí | callback lỗi, mã khác |
| `chua_tra_loi` | hộp thoại bật ra và người dùng **bỏ qua** | xem dưới |

**`chua_tra_loi` là cái khó, và nó cũng là cái quan trọng nhất.** Trình duyệt
không báo gì khi người dùng bấm ra ngoài hộp thoại: không callback nào chạy,
`permissions.query` vẫn trả `prompt` mãi mãi. Nên nó chỉ suy ra được:

> gọi `getCurrentPosition`, chờ **N giây**; nếu chưa callback nào chạy và
> trạng thái quyền vẫn là `prompt` → ghi `chua_tra_loi`.

Đó là một phép **suy đoán**, không phải một phép đo, và tên trường phải nói
đúng điều đó. Người dùng mở trang rồi chuyển tab cũng rơi vào ô này.

Vì sao nó quan trọng nhất: `chua_tra_loi` là trạng thái **duy nhất còn cứu
được**. `tu_choi` thì trình duyệt đã khoá; `chua_tra_loi` thì lần sau vẫn hỏi
lại được — và nếu ô này lớn, câu trả lời không phải "bỏ tính năng" mà là
"hỏi ở thời điểm khác".

### Mẫu số

Một tỉ lệ cần mẫu số. Ghi thêm một giá trị `hoi` mỗi lần thật sự gọi
`getCurrentPosition` ở nhánh có thể bật hộp thoại. Mẫu số **không** phải số
lượt mở `/explore`: phần lớn lượt mở không hỏi gì cả, vì lưới an toàn số 1
chỉ hỏi một lần cho mỗi trình duyệt.

## Lát cắt

### Lát 1 — bảng đếm và đường nhận, chưa ai gọi — **XONG 23/10/2026**

> Bảng `do_dac_ngay`, `POST /api/v2/do-dac`, `DoDacService`,
> `GhiDoDacRequest`. 14 ca / ~150 khẳng định. Chưa ai gọi đường này, đúng
> như tên lát.
>
> **Hai chỗ thiết kế trên giấy không nói, và chúng sai được im lặng:**
>
> - `upsert()` của Laravel sinh `so_luot = VALUES(so_luot)` — **ghi đè bằng
>   1** thay vì cộng thêm 1. Mỗi ô sẽ vĩnh viễn bằng 1 và trông hoàn toàn
>   bình thường. Phải viết `ON DUPLICATE KEY UPDATE so_luot = so_luot + 1`.
> - `config('app.timezone')` là `UTC`, nên `now()->toDateString()` dồn **bảy
>   giờ đầu mỗi ngày ở Việt Nam vào ngày hôm trước**. Widget lát 3 sẽ thiếu
>   hẳn buổi sáng, đều đặn, mãi mãi. Ghi theo `Asia/Ho_Chi_Minh`.
>
> **Một đột biến cho thấy chốt chưa canh được điều plan coi là quan trọng.**
> Nới phép kiểm từ theo-cặp (`CHO_PHEP[$suKien]`) sang gộp mọi giá trị —
> **không ca nào đỏ**, 12/12 vẫn xanh. Lý do: danh sách thật mới có *một*
> sự kiện, nên không gửi được bộ ba *(sự kiện hợp lệ, giá trị thuộc sự kiện
> khác)*, đúng cái duy nhất phân biệt hai cách viết. Lỗ mở ra thật ở **lát
> 4**, đúng lúc không ai còn nhớ chỗ này.
>
> Nó cũng lộ ra một lỗi thật: `GhiDoDacRequest` tự dựng lại cả ba phép kiểm
> thay vì gọi service, tức **hai bản luật** cho một thứ. Nay luật sống ở
> `DoDacService::loiDauTien()`, và nó nhận tham số `$choPhep` để chốt tiêm
> được một bảng giả hai sự kiện.

Bảng `do_dac_ngay`:

| Cột | Kiểu | Ghi chú |
|---|---|---|
| `ngay` | `date` | |
| `su_kien` | `varchar(40)` | `quyen_vi_tri` |
| `gia_tri` | `varchar(40)` | `hoi` / `cho_phep` / `tu_choi` / … |
| `thiet_bi` | `enum('mobile','desktop')` | tỉ lệ chặn khác nhau rõ rệt giữa hai loại |
| `so_luot` | `unsigned int` | |

Khoá duy nhất `(ngay, su_kien, gia_tri, thiet_bi)`, và ghi bằng
`INSERT … ON DUPLICATE KEY UPDATE so_luot = so_luot + 1` — một câu, an toàn
với truy cập đồng thời, và bảng không bao giờ phình.

`POST /api/v2/do-dac`, công khai, và vì nó là **đường ghi mở cho người lạ**
nên nó đi kèm đúng ba thứ `POST /api/v2/reflections` đã có:

- `throttle:30,60` — cao hơn 5/giờ của phản ánh vì đây là sự kiện tự động,
  nhưng vẫn là một trần;
- **danh sách trắng cứng** cho cả `su_kien` lẫn `gia_tri`: giá trị lạ → 422,
  không ghi. Thiếu nó thì bất cứ ai cũng dựng được vài triệu hàng rác;
- phản hồi **204, thân rỗng** — không có gì để dò.

Không nhận IP, không nhận user agent, không nhận id. `thiet_bi` do client tự
khai (`matchMedia('(pointer: coarse)')`), không suy từ user agent.

**Chốt:** ca cho danh sách trắng, ca cho phép cộng dồn (hai lượt gửi cùng
khoá ra `so_luot = 2`, không phải hai hàng), ca cho hạn mức.

### Lát 2 — client gửi năm kết quả và mẫu số

Gắn vào `webapp/lib/dung-vi-tri.ts`, nơi đã có đủ cả năm nhánh. Ba điều kiện:

- **Không bao giờ chặn luồng.** `fetch` không `await`, lỗi nuốt im lặng. Một
  phép đo làm chậm tính năng nó đo là một phép đo tự huỷ.
- **Không gửi ở máy phát triển.** Chỉ gửi khi `location.hostname` là tên miền
  thật — nếu không, mỗi lần tôi chạy `next start` lại bơm số rác vào bảng.
- **Hẹn giờ `chua_tra_loi` phải huỷ được**, nếu không một callback về chậm sẽ
  ghi cả `chua_tra_loi` lẫn `cho_phep` cho cùng một lượt.

N giây chờ: **12** — dài hơn `timeout: 10_000` của `getCurrentPosition`, để
ca hết-giờ rơi vào `loi` chứ không lẫn vào `chua_tra_loi`.

### Lát 3 — một ô trên `/admin`

Widget kiểu `NguonBanDoWidget`: ba mươi ngày gần nhất, bốn số — tổng lượt
hỏi, tỉ lệ cho phép, tỉ lệ chặn, tỉ lệ chưa trả lời — tách mobile/desktop.

Lý do nó là widget chứ không phải một câu SQL chạy tay: cùng lý do widget
nguồn bản đồ tồn tại. Một con số không ai mở ra nhìn thì không phải một phép
đo, nó là một hàng trong bảng.

Ô này phải **nói ra khi chưa đủ dữ liệu**: dưới 100 lượt hỏi thì in "chưa đủ
số liệu" thay vì một tỉ lệ. Một tỉ lệ tính trên 7 lượt là một con số sẽ bị
mang đi quyết định.

### Lát 4 — chỉ làm khi lát 3 trả lời được câu hỏi ban đầu

Mở rộng cho những câu hỏi khác đang mù: bao nhiêu người đổi sang kiểu xem bản
đồ, bao nhiêu người bấm "Tìm trong khu vực này", bao nhiêu kế hoạch được
khoanh rồi bỏ. Cùng bảng, cùng đường, chỉ thêm `su_kien`.

**Không làm trước lát 3.** Một đường đo đạc chưa ai đọc thì thêm sự kiện vào
chỉ là thêm dữ liệu không ai đọc.

## Hai việc chủ dự án cần chốt

| Việc | Hai ngả |
|---|---|
| **Có bật đo đạc không** | Bảng đếm gộp không chứa dữ liệu cá nhân, nhưng nó **vẫn là quan sát hành vi người dùng**. Bật · hoặc không bật và chấp nhận rằng quyết định "hỏi khi mở trang" sẽ không bao giờ được kiểm chứng |
| **Có ghi vào chính sách bảo mật không** | Khuyến nghị của tôi: **có**, một câu nói sàn đếm số lượt gộp và không gắn với cá nhân. Nó rẻ, và nó là thứ người ta kiểm tra được |

Tôi **không** khuyến nghị gì về mặt pháp lý — tôi không đủ thẩm quyền. Thiết
kế trên cố ý tránh dữ liệu cá nhân để câu hỏi pháp lý không phát sinh; nếu
sau này muốn đo theo từng người thì đó là một plan khác và cần người có
chuyên môn pháp lý xem.

## Rủi ro

| Rủi ro | Chặn bằng |
|---|---|
| Đường ghi công khai bị bơm số rác | Danh sách trắng cứng + `throttle:30,60` + bảng đếm (không phình theo lượt) |
| `chua_tra_loi` là suy đoán, không phải phép đo | Tên trường nói đúng điều đó; widget chú thích tại chỗ |
| Số liệu lẫn lượt chạy ở máy phát triển | Chỉ gửi khi hostname là tên miền thật |
| Một tỉ lệ tính trên quá ít lượt | Widget in "chưa đủ số liệu" dưới 100 lượt hỏi |
| Bảng này lớn dần thành bảng hành vi | Ranh giới ghi ở mục "Nguyên tắc"; mở rộng sang dữ liệu cá nhân là một plan khác |
| Bot chạy JS làm lệch số | Chưa chặn. Ghi lại ở đây; nếu số liệu trông sai thì đó là nghi can đầu tiên |

## Cái plan này cố ý KHÔNG làm

- **Không** theo dõi theo phiên, theo người dùng, theo thiết bị.
- **Không** lưu IP, user agent, đường dẫn giới thiệu.
- **Không** dùng dịch vụ phân tích bên thứ ba: nó đẩy dữ liệu người dùng Việt
  Nam ra khỏi hạ tầng của sàn, và đó là một quyết định lớn hơn nhiều so với
  câu hỏi đang cần trả lời.
- **Không** đo gì khác ngoài quyền vị trí cho tới khi lát 3 chạy.

## Bắt đầu

Lát 1 chạy được ngay và không đụng giao diện. Hai câu hỏi ở mục trên chặn
**lát 2** — tức chặn việc gửi dữ liệu thật — chứ không chặn lát 1.
