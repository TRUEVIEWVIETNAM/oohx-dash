<?php

namespace App\Services\Map;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * Kết quả lần soát nguồn ảnh nền bản đồ gần nhất.
 *
 * ══ Vì sao có tệp này thay vì chỉ đọc log ══
 *
 * `map:kiem-tile` đã ghi ra `storage/logs/oohx-map-tile.log` từ 18/10/2026.
 * Nhưng một dòng log là nơi **tra cứu sau khi đã biết có chuyện**, không phải
 * nơi phát hiện ra chuyện — và bài học của chính ngày hôm đó là mọi sự cố nền
 * bản đồ đều im lặng, nên không ai có lý do để mở log ra xem.
 *
 * Tệp JSON này là thứ máy đọc được, để một widget trên `/admin` nói thẳng
 * trạng thái cho người đang nhìn màn hình.
 *
 * ══ Dùng đĩa, không dùng cache ══
 *
 * `deploy.sh` chạy `cache:clear` ở bước 6. Nếu trạng thái sống trong cache thì
 * mỗi lần deploy nó biến mất, và widget nói "chưa chạy lần nào" cho tới lượt
 * cron kế tiếp — tức tối đa sáu giờ báo sai sau mỗi lần deploy.
 */
class TrangThaiNguonTile
{
    /** Quá hạn này mà chưa chạy lại thì coi như lịch đã chết. */
    public const GIO_QUA_HAN = 12;

    private const DUONG = 'oohx/trang-thai-tile.json';

    /**
     * @param  array<int, array{ten: string, ok: bool, mo_ta: string}>  $nguon
     */
    public function ghi(array $nguon): void
    {
        Storage::disk('local')->put(self::DUONG, (string) json_encode([
            'luc' => now()->toIso8601String(),
            'ok' => collect($nguon)->every(fn (array $n) => $n['ok']),
            'nguon' => $nguon,
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }

    /**
     * @return array{luc: Carbon, ok: bool, nguon: array<int, array{ten: string, ok: bool, mo_ta: string}>, tuoi_phut: int, qua_han: bool}|null
     */
    public function doc(): ?array
    {
        if (! Storage::disk('local')->exists(self::DUONG)) {
            return null;
        }

        $tho = json_decode((string) Storage::disk('local')->get(self::DUONG), true);

        if (! is_array($tho) || ! isset($tho['luc'])) {
            return null;
        }

        $luc = Carbon::parse($tho['luc']);
        $tuoi = (int) $luc->diffInMinutes(now());

        return [
            'luc' => $luc,
            'ok' => (bool) ($tho['ok'] ?? false),
            'nguon' => array_values((array) ($tho['nguon'] ?? [])),
            'tuoi_phut' => $tuoi,

            // Quá hạn là một trạng thái **xấu riêng**, không phải "chưa biết".
            // Lệnh chạy mỗi sáu giờ, nên quá mười hai giờ nghĩa là hai lượt
            // liên tiếp không chạy — gần như chắc chắn `schedule:run` không
            // có trong crontab hoặc scheduler đã chết. Đó đúng là kiểu hỏng
            // mà một widget im lặng sẽ giấu đi.
            'qua_han' => $tuoi > self::GIO_QUA_HAN * 60,
        ];
    }
}
