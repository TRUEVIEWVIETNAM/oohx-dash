<?php

namespace Tests\Unit;

use App\Services\FrontpageService;
use PHPUnit\Framework\TestCase;

/**
 * `FrontpageService::coOLuoi()` — chọn cạnh ô lưới cho một khung nhìn.
 *
 * Hai tính chất, và cả hai đều là tính chất về **thị giác**, không về hiệu
 * năng:
 *
 *  1. **Neo cố định.** Kéo bản đồ một chút không được đổi cạnh ô, nếu không
 *     ranh ô dịch theo và mọi cụm nhảy chỗ — cùng một kho trông như vừa thay
 *     đổi. Đây là lý do cạnh ô lấy từ một thang cố định chứ không phải
 *     `span / 16`.
 *  2. **Số ô trong tầm.** Quá thô thì cả nước thành bốn cụm và bản đồ không
 *     nói gì; quá mịn thì hàng nghìn cụm và nó lại là bản đồ pin, chỉ tệ hơn.
 */
class OLuoiBanDoTest extends TestCase
{
    /** @return array{north:float,south:float,east:float,west:float} */
    private function khung(float $tam, float $rong): array
    {
        return [
            'north' => $tam + $rong / 2,
            'south' => $tam - $rong / 2,
            'east'  => 105.8 + $rong / 2,
            'west'  => 105.8 - $rong / 2,
        ];
    }

    public function test_so_o_ngang_khong_qua_muoi_sau_o_moi_muc_thu_phong(): void
    {
        // Từ cả nước (15°) tới một con phố (0,002°), bước 1,5 lần.
        for ($rong = 15.0; $rong > 0.0015; $rong /= 1.5) {
            $o    = FrontpageService::coOLuoi($this->khung(21.0, $rong));
            $soO  = $rong / $o;

            $this->assertLessThanOrEqual(
                16.01,
                $soO,
                sprintf('Khung %.4f° cho %.1f ô ngang — quá mịn, cụm sẽ nhiều như pin.', $rong, $soO)
            );

            // Mặt còn lại: thang 1–2–5 bước 2–2,5 lần, nên ô nhỏ nhất vẫn giữ
            // ≤ 16 ô luôn cho ít nhất 16/2,5 ≈ 6,4 ô. Dưới 6 ô ngang là thang
            // có lỗ.
            $this->assertGreaterThan(
                6.0,
                $soO,
                sprintf('Khung %.4f° chỉ cho %.1f ô ngang — quá thô, bản đồ không nói gì.', $rong, $soO)
            );
        }
    }

    public function test_keo_ban_do_mot_chut_khong_doi_canh_o(): void
    {
        $rong = 0.3;

        $o = FrontpageService::coOLuoi($this->khung(21.0, $rong));

        // Mười bước kéo nhỏ, cùng mức thu phóng. Cạnh ô phải y nguyên.
        for ($i = 1; $i <= 10; $i++) {
            $this->assertSame(
                $o,
                FrontpageService::coOLuoi($this->khung(21.0 + $i * 0.013, $rong)),
                'Kéo bản đồ đổi cạnh ô — cụm sẽ nhảy chỗ ở mỗi lần kéo.'
            );
        }
    }

    public function test_canh_o_lay_tu_thang_co_dinh_chu_khong_phai_so_bat_ky(): void
    {
        // Đọc chính thang của service, không chép lại. Một bản chép sẽ hết
        // đúng ở lần nối thang kế tiếp mà ca vẫn xanh.
        for ($rong = 20.0; $rong > 0.001; $rong /= 1.3) {
            $this->assertContains(
                FrontpageService::coOLuoi($this->khung(21.0, $rong)),
                FrontpageService::THANG_O_LUOI,
                sprintf('Khung %.4f° cho cạnh ô ngoài thang — lưới không neo được.', $rong)
            );
        }
    }

    public function test_khung_rong_hon_ca_the_gioi_van_tra_canh_o_huu_han(): void
    {
        // Leaflet trả khung rộng hơn 360° khi thu nhỏ hết ở container rộng.
        // Rơi ra khỏi thang thì phải lấy nấc thô nhất, không trả 0 hay vỡ:
        // cạnh ô 0 làm `FLOOR(lat / 0)` chia cho không.
        $o = FrontpageService::coOLuoi(['north' => 85, 'south' => -85, 'east' => 400, 'west' => -400]);

        $this->assertGreaterThan(0, $o);
        $this->assertSame(5.0, $o);
    }

    public function test_canh_o_theo_chieu_rong_hon_trong_hai_chieu(): void
    {
        // Khung dẹt: cao 0,02° nhưng rộng 2°. Lấy theo chiều cao thì ngang ra
        // ~1.000 ô. Phải lấy chiều lớn hơn.
        $o = FrontpageService::coOLuoi(['north' => 21.01, 'south' => 20.99, 'east' => 106.8, 'west' => 104.8]);

        $this->assertLessThanOrEqual(16.01, 2.0 / $o);
    }
}
