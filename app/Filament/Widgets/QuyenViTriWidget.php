<?php

namespace App\Filament\Widgets;

use App\Services\DoDacService;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Một ô trên trang chủ `/admin`: hỏi quyền vị trí khi mở trang có đáng không.
 *
 * Lát 3 của `docs/PLAN-DO-DAC-TOI-THIEU.md`.
 *
 * ══ Vì sao một widget, chứ không phải một câu SQL chạy tay ══
 *
 * Cùng lý do `NguonBanDoWidget` tồn tại: một con số không ai mở ra nhìn thì
 * không phải một phép đo, nó là một hàng trong bảng. Lát 1 dựng bảng, lát 2
 * đổ số vào — nhưng nếu không có ô này thì câu hỏi *"90% bấm Chặn hay 70%
 * cho phép"* vẫn không ai trả lời, chỉ là nay nó không trả lời được vì không
 * ai nhìn chứ không vì không có dữ liệu. Hai kiểu không-biết đó tốn như nhau.
 *
 * ══ Ô này được phép nói "chưa đủ số liệu", và đó là tính năng ══
 *
 * Dưới `NGUONG_DU_LIEU` lượt hỏi thì không in tỉ lệ nào. Một tỉ lệ tính trên
 * 7 lượt vẫn trông hệt như một tỉ lệ, nên nó sẽ được mang đi quyết định có bỏ
 * tính năng hay không.
 *
 * Ngưỡng áp **theo từng thiết bị**, không theo tổng: tỉ lệ chặn trên di động
 * và trên máy tính khác nhau rõ rệt, nên 500 lượt mobile không làm 7 lượt
 * desktop đáng tin hơn. Áp theo tổng là đúng cái sai mà plan muốn tránh, chỉ
 * khó thấy hơn một bậc.
 *
 * ══ Ba thứ ô này cố ý KHÔNG làm ══
 *
 *  · **Không chia `da_chan_tu_truoc` cho `hoi`.** Nhánh đó không gọi
 *    `getCurrentPosition`, nên không có lượt `hoi` nào đi kèm; phân số ấy
 *    vượt 100% được. Nó hiện ở ô tổng như một số đếm.
 *  · **Không gộp `loi` vào `tu_choi`.** Hết thời gian chờ không phải một lời
 *    từ chối, và gộp vào sẽ làm tính năng trông tệ hơn thực tế.
 *  · **Không tự diễn giải.** Ô này không in "nên bỏ tính năng". Nó in con số
 *    và ngưỡng màu; quyết định là của người đọc.
 */
class QuyenViTriWidget extends BaseWidget
{
    protected static ?int $sort = 12;

    /**
     * Ngưỡng màu của tỉ lệ cho phép.
     *
     * Lấy thẳng từ hai thế giới plan nêu ra: 70% cho phép là *"quyết định
     * đúng, không cần bàn lại"*, 90% bấm Chặn (tức ~10% cho phép) là *"đã mất
     * tính năng vĩnh viễn"*. Hai mốc dưới đây nằm giữa hai cực đó và cố ý
     * không khớp chính xác con số nào: màu là để mắt dừng lại, không phải để
     * thay một quyết định.
     */
    private const XANH = 50.0;

    private const VANG = 25.0;

    /** Lệch quá mức này giữa `tong_ket_qua` và `hoi` thì client đang sai. */
    private const LECH_DANG_NGO_PCT = 5.0;

    public static function canView(): bool
    {
        $u = auth()->user();

        return $u && method_exists($u, 'hasRole') && $u->hasRole('super_admin');
    }

    protected function getStats(): array
    {
        $th = app(DoDacService::class)->tongHopQuyenViTri();

        if ($th['hoi'] === 0 && $th['da_chan_tu_truoc'] === 0) {
            return [
                Stat::make('Quyền vị trí', 'Chưa có số liệu')
                    ->description('Chưa lượt nào được ghi trong ' . $th['so_ngay'] . ' ngày qua')
                    ->descriptionIcon('heroicon-m-question-mark-circle')
                    ->color('gray')
                    ->icon('heroicon-m-map-pin'),
            ];
        }

        return array_merge([$this->oTong($th)], $this->oThietBi($th));
    }

    /**
     * @param  array<string, mixed>  $th
     */
    private function oTong(array $th): Stat
    {
        $moTa = 'Trong ' . $th['so_ngay'] . ' ngày, từ ' . $this->ngay((string) $th['tu_ngay']);

        if ($th['da_chan_tu_truoc'] > 0) {
            // Số đếm, không tỉ lệ — xem docblock lớp. Đây là những lượt mở
            // trang mà trình duyệt đã khoá sẵn, nên không hộp thoại nào bật
            // và không lượt `hoi` nào được ghi.
            $moTa .= ' · thêm ' . number_format((int) $th['da_chan_tu_truoc'])
                . ' lượt trình duyệt đã chặn sẵn';
        }

        return Stat::make('Lượt hỏi quyền vị trí', number_format((int) $th['hoi']))
            ->description($moTa)
            ->descriptionIcon('heroicon-m-calendar-days')
            ->color('gray')
            ->icon('heroicon-m-map-pin');
    }

    /**
     * @param  array<string, mixed>  $th
     * @return array<int, Stat>
     */
    private function oThietBi(array $th): array
    {
        $o = [];

        foreach ($th['thiet_bi'] as $ten => $tb) {
            $nhan = $ten === 'mobile' ? 'Di động' : 'Máy tính';
            $bieuTuong = $ten === 'mobile' ? 'heroicon-m-device-phone-mobile' : 'heroicon-m-computer-desktop';

            if (! $tb['du_lieu']) {
                $o[] = Stat::make($nhan, 'Chưa đủ số liệu')
                    ->description(number_format((int) $tb['hoi']) . '/' . $th['nguong'] . ' lượt hỏi cần có')
                    ->descriptionIcon('heroicon-m-ellipsis-horizontal')
                    ->color('gray')
                    ->icon($bieuTuong);

                continue;
            }

            $o[] = Stat::make($nhan, 'Cho phép ' . $this->pct($tb['ti_le']['cho_phep']))
                ->description($this->chiTiet($tb))
                ->descriptionIcon($this->dauHieu($tb))
                ->color($this->mau((float) $tb['ti_le']['cho_phep']))
                ->icon($bieuTuong);
        }

        return $o;
    }

    /**
     * @param  array<string, mixed>  $tb
     */
    private function chiTiet(array $tb): string
    {
        $phan = [
            'Chặn ' . $this->pct($tb['ti_le']['tu_choi']),
            'Chưa trả lời ' . $this->pct($tb['ti_le']['chua_tra_loi']),
            'Lỗi ' . $this->pct($tb['ti_le']['loi']),
            number_format((int) $tb['hoi']) . ' lượt hỏi',
        ];

        if ($this->lech($tb)) {
            // Từng tỉ lệ vẫn trông hợp lý khi client ghi hai kết quả cho một
            // lượt; chỉ tổng của chúng là vô lý. Nói thẳng ra ở đây, vì không
            // chỗ nào khác nhìn ra được.
            $phan[] = 'tổng kết quả lệch khỏi mẫu số — kiểm client';
        }

        return implode(' · ', $phan);
    }

    /**
     * @param  array<string, mixed>  $tb
     */
    private function dauHieu(array $tb): string
    {
        return $this->lech($tb)
            ? 'heroicon-m-exclamation-triangle'
            : 'heroicon-m-chart-pie';
    }

    /**
     * Tổng bốn kết quả có khớp mẫu số `hoi` không.
     *
     * @param  array<string, mixed>  $tb
     */
    private function lech(array $tb): bool
    {
        $hoi = (int) $tb['hoi'];

        if ($hoi === 0) {
            return false;
        }

        return abs((int) $tb['tong_ket_qua'] - $hoi) / $hoi * 100 > self::LECH_DANG_NGO_PCT;
    }

    private function mau(float $choPhep): string
    {
        if ($choPhep >= self::XANH) {
            return 'success';
        }

        return $choPhep >= self::VANG ? 'warning' : 'danger';
    }

    private function pct(float $v): string
    {
        return rtrim(rtrim(number_format($v, 1), '0'), '.') . '%';
    }

    private function ngay(string $iso): string
    {
        [$n, $t, $ng] = explode('-', $iso);

        return $ng . '/' . $t . '/' . $n;
    }
}
