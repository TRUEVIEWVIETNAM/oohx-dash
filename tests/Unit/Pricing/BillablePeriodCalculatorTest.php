<?php

namespace Tests\Unit\Pricing;

use App\Services\Pricing\BillablePeriodCalculator;
use Carbon\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Khoá công thức quy đổi ngày → kỳ tính tiền.
 *
 * Trước đây không có test nào chạm tới phép tính giá; sửa công thức không làm
 * đỏ bất cứ thứ gì (audit mục G).
 */
class BillablePeriodCalculatorTest extends TestCase
{
    private BillablePeriodCalculator $calc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calc = new BillablePeriodCalculator();
    }

    #[DataProvider('ioCases')]
    public function test_so_ky_io(string $start, string $end, string $unit, int $expected): void
    {
        $this->assertSame(
            $expected,
            $this->calc->ioUnits(Carbon::parse($start), Carbon::parse($end), $unit)
        );
    }

    /**
     * Quy ước đã chốt 27/09/2026: **tháng lịch**.
     * Kỳ 1 chạy từ ngày bắt đầu tới hôm trước mốc cùng ngày tháng sau.
     */
    public static function ioCases(): array
    {
        return [
            'một ngày'                   => ['2027-01-01', '2027-01-01', 'month', 1],
            'trọn tháng 1 (31 ngày)'     => ['2027-01-01', '2027-01-31', 'month', 1],
            'sang ngày đầu tháng 2'      => ['2027-01-01', '2027-02-01', 'month', 2],
            'sáu tháng lịch'             => ['2027-01-01', '2027-06-30', 'month', 6],
            'sáu tháng cộng một ngày'    => ['2027-01-01', '2027-07-01', 'month', 7],
            'giữa tháng, chưa đủ kỳ'     => ['2027-01-15', '2027-02-14', 'month', 1],
            'giữa tháng, chạm mốc'       => ['2027-01-15', '2027-02-15', 'month', 2],
            'tháng 2 ngắn ngày'          => ['2027-02-01', '2027-02-28', 'month', 1],
            'một tuần'                   => ['2027-01-01', '2027-01-07', 'week', 1],
            'tám ngày, tuần'             => ['2027-01-01', '2027-01-08', 'week', 2],
        ];
    }

    /**
     * Ngày 31 không có ở tháng sau: mốc bị kẹp về ngày cuối tháng, không nhảy
     * sang tháng 3. Thuê 31/01 → 28/02 (2028 là năm nhuận nên mốc là 29/02) vẫn
     * là một kỳ; sang 29/02 là bước vào kỳ thứ hai.
     */
    public function test_ngay_31_khong_nhay_sang_thang_sau(): void
    {
        $this->assertSame(
            1,
            $this->calc->ioUnits(Carbon::parse('2028-01-31'), Carbon::parse('2028-02-28'), 'month')
        );

        $this->assertSame(
            2,
            $this->calc->ioUnits(Carbon::parse('2028-01-31'), Carbon::parse('2028-02-29'), 'month')
        );
    }

    public function test_che_do_cu_theo_so_ngay_van_dung_duoc(): void
    {
        // Giữ để đối chiếu dữ liệu lịch sử. 181 ngày / 30 = 7 kỳ.
        config(['pricing.month_mode' => 'fixed_days', 'pricing.month_days' => 30]);

        $this->assertSame(
            7,
            $this->calc->ioUnits(Carbon::parse('2027-01-01'), Carbon::parse('2027-06-30'), 'month'),
            'Chế độ cũ tính 181 ngày thành 7 kỳ — chính là lý do đã chuyển sang tháng lịch.'
        );
    }

    public function test_dem_ngay_gom_ca_ngay_dau_va_cuoi(): void
    {
        $this->assertSame(1, $this->calc->days(Carbon::parse('2027-01-01'), Carbon::parse('2027-01-01')));
        $this->assertSame(31, $this->calc->days(Carbon::parse('2027-01-01'), Carbon::parse('2027-01-31')));
    }

    public function test_khong_lam_bien_doi_doi_tuong_ngay_dau_vao(): void
    {
        $start = Carbon::parse('2027-01-01 15:30:00');
        $end   = Carbon::parse('2027-01-31 08:00:00');

        $this->calc->days($start, $end);

        $this->assertSame('15:30:00', $start->format('H:i:s'), 'startOfDay() không được sửa đối tượng gốc.');
    }

    public function test_so_cpm_toi_thieu(): void
    {
        $this->assertSame(1, $this->calc->minimumCpms(0));
        $this->assertSame(1, $this->calc->minimumCpms(500));
        $this->assertSame(1, $this->calc->minimumCpms(1000));
        $this->assertSame(2, $this->calc->minimumCpms(1001));
        $this->assertSame(150, $this->calc->minimumCpms(150_000));
    }

    public function test_so_ngay_mot_ky_lay_tu_config(): void
    {
        config(['pricing.month_mode' => 'fixed_days', 'pricing.month_days' => 28]);

        $this->assertSame(
            2,
            $this->calc->ioUnits(Carbon::parse('2027-01-01'), Carbon::parse('2027-01-29'), 'month'),
            'Đổi quy ước tháng trong config phải đổi kết quả — con số không được viết cứng.'
        );
    }
}
