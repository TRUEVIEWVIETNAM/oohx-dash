<?php

namespace App\Filament\Widgets;

use App\Services\Map\TrangThaiNguonTile;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Một ô trên trang chủ `/admin`: nền bản đồ còn sống không.
 *
 * ══ Vì sao một widget, chứ không phải một dòng log ══
 *
 * Ngày 18/10/2026 nguồn ảnh nền bản đồ hỏng bốn lần, và không lần nào có một
 * dòng lỗi nào được ghi ở đâu. Lý do chung: bản đồ **trông như đang chạy** —
 * pin đúng, cụm đúng, nút đúng, chỉ nền là sai hoặc thiếu. Hai lần trong đó
 * lên production và nằm đó cho tới khi có người tình cờ mở trang ra nhìn.
 *
 * `map:kiem-tile` giải nửa bài toán: nó biết khi nào nguồn trả rác. Nửa còn
 * lại là **ai đọc kết quả đó**. Lệnh ghi ra `storage/logs/oohx-map-tile.log`,
 * mà một tệp log là nơi tra cứu sau khi đã biết có chuyện. Ô này đặt câu trả
 * lời vào đúng màn hình người ta mở hằng ngày.
 *
 * ══ Nó canh hai chuyện, không phải một ══
 *
 *  1. **Nguồn có trả về bản đồ không** — kết quả lần soát gần nhất.
 *  2. **Lịch có còn chạy không** — nếu kết quả gần nhất đã quá
 *     `GIO_QUA_HAN` giờ thì lệnh không chạy nữa, và một ô báo "ổn" dựa trên
 *     số liệu ba ngày trước còn tệ hơn không có ô nào.
 *
 * Chuyện thứ hai đang là một lỗ thật: tính tới 18/10/2026 chưa ai xác nhận
 * `schedule:run` có trong crontab của máy chủ.
 */
class NguonBanDoWidget extends BaseWidget
{
    protected static ?int $sort = 11;

    public static function canView(): bool
    {
        $u = auth()->user();

        return $u && method_exists($u, 'hasRole') && $u->hasRole('super_admin');
    }

    protected function getStats(): array
    {
        $tt = app(TrangThaiNguonTile::class)->doc();

        if ($tt === null) {
            return [
                Stat::make('Nền bản đồ', 'Chưa soát lần nào')
                    ->description('Chạy `php artisan map:kiem-tile` để có số liệu đầu tiên')
                    ->descriptionIcon('heroicon-m-question-mark-circle')
                    ->color('gray')
                    ->icon('heroicon-m-map'),
            ];
        }

        // Quá hạn thắng mọi trạng thái khác: một kết quả "ổn" từ ba ngày
        // trước không nói gì về hôm nay, và hiển thị nó màu xanh là nói dối.
        if ($tt['qua_han']) {
            return [
                Stat::make('Nền bản đồ', 'Lịch soát đã dừng')
                    ->description($this->tuoi($tt['tuoi_phut']) . ' chưa soát lại — kiểm `schedule:run` trong crontab')
                    ->descriptionIcon('heroicon-m-exclamation-triangle')
                    ->color('danger')
                    ->icon('heroicon-m-clock'),
            ];
        }

        $o = [
            Stat::make('Nền bản đồ', $tt['ok'] ? 'Đang chạy' : 'Có nguồn hỏng')
                ->description('Soát ' . $this->tuoi($tt['tuoi_phut']) . ' trước')
                ->descriptionIcon('heroicon-m-clock')
                ->color($tt['ok'] ? 'success' : 'danger')
                ->icon('heroicon-m-map'),
        ];

        foreach ($tt['nguon'] as $nguon) {
            $o[] = Stat::make(
                ucfirst((string) $nguon['ten']),
                $nguon['ok'] ? 'Trả về bản đồ' : 'Không trả về bản đồ',
            )
                ->description((string) $nguon['mo_ta'])
                ->color($nguon['ok'] ? 'success' : 'danger')
                ->icon($nguon['ok'] ? 'heroicon-m-check-circle' : 'heroicon-m-x-circle');
        }

        return $o;
    }

    private function tuoi(int $phut): string
    {
        if ($phut < 60) {
            return "{$phut} phút";
        }

        $gio = intdiv($phut, 60);

        return $gio < 48 ? "{$gio} giờ" : intdiv($gio, 24) . ' ngày';
    }
}
