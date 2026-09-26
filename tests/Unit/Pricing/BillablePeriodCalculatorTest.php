<?php

namespace Tests\Unit\Pricing;

use App\Services\Pricing\BillablePeriodCalculator;
use Carbon\Carbon;
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

    /** @dataProvider ioCases */
    public function test_so_ky_io(string $start, string $end, string $unit, int $expected): void
    {
        $this->assertSame(
            $expected,
            $this->calc->ioUnits(Carbon::parse($start), Carbon::parse($end), $unit)
        );
    }

    public static function ioCases(): array
    {
        return [
            'một ngày, tháng'        => ['2027-01-01', '2027-01-01', 'month', 1],
            'tròn 30 ngày'           => ['2027-01-01', '2027-01-30', 'month', 1],
            'quá 30 ngày một chút'   => ['2027-01-01', '2027-01-31', 'month', 2],
            // 01/01–30/06 là 181 ngày. Với quy ước "tháng = 30 ngày" thì thành 7 kỳ,
            // không phải 6. Đây chính là lý do quy ước này cần được duyệt về mặt
            // nghiệp vụ: người mua nghĩ mình thuê 6 tháng nhưng bị tính 7 kỳ.
            'sáu tháng lịch = 181 ngày = 7 kỳ' => ['2027-01-01', '2027-06-30', 'month', 7],
            'đúng 180 ngày = 6 kỳ'             => ['2027-01-01', '2027-06-29', 'month', 6],
            'một tuần'               => ['2027-01-01', '2027-01-07', 'week', 1],
            'tám ngày, tuần'         => ['2027-01-01', '2027-01-08', 'week', 2],
            'tháng 2 năm nhuận, 30 ngày' => ['2028-02-01', '2028-03-01', 'month', 1],
            'tháng 2 năm nhuận, 31 ngày' => ['2028-02-01', '2028-03-02', 'month', 2],
        ];
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
        config(['pricing.month_days' => 28]);

        $this->assertSame(
            2,
            $this->calc->ioUnits(Carbon::parse('2027-01-01'), Carbon::parse('2027-01-29'), 'month'),
            'Đổi quy ước tháng trong config phải đổi kết quả — con số không được viết cứng.'
        );
    }
}
