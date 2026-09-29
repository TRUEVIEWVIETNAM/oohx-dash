<?php

namespace Tests\Unit\Booking;

use App\Services\Booking\BundleExpander;
use PHPUnit\Framework\TestCase;

/**
 * Chia tiền gói cho từng màn hình. Quy tắc bất di bất dịch: **tổng các phần
 * luôn đúng bằng số ban đầu.** Lệch một đồng trong một gói nghìn màn hình là
 * lệch cả sổ đối soát, và người ta phát hiện nó bằng hóa đơn chứ không bằng test.
 */
class SplitVndTest extends TestCase
{
    public function test_chia_theo_ti_le_trong_so(): void
    {
        $this->assertSame([2_000_000, 6_000_000], BundleExpander::splitVnd(8_000_000, [1, 3]));
    }

    public function test_trong_so_bang_khong_thi_chia_deu(): void
    {
        $this->assertSame([2_500_000, 2_500_000], BundleExpander::splitVnd(5_000_000, [0, 0]));
    }

    public function test_phan_du_khong_bi_mat(): void
    {
        $parts = BundleExpander::splitVnd(10, [1, 1, 1]);

        $this->assertSame(10, array_sum($parts), 'Chia 10 cho 3 phần vẫn phải cộng lại thành 10.');
        $this->assertSame([4, 3, 3], $parts, 'Phần dư cộng vào phần đầu tiên trong nhóm trọng số lớn nhất.');
    }

    public function test_phan_du_uu_tien_trong_so_lon(): void
    {
        $parts = BundleExpander::splitVnd(100, [1, 2, 4]);

        $this->assertSame(100, array_sum($parts));
        // 100*1/7=14, 100*2/7=28, 100*4/7=57 → dư 1, cộng vào phần trọng số 4.
        $this->assertSame([14, 28, 58], $parts);
    }

    public function test_mot_man_hinh_thi_nhan_het(): void
    {
        $this->assertSame([7_000_000], BundleExpander::splitVnd(7_000_000, [5]));
    }

    public function test_khong_co_man_hinh_thi_khong_chia_gi(): void
    {
        $this->assertSame([], BundleExpander::splitVnd(1_000_000, []));
    }

    public function test_so_lon_khong_tran_va_khong_lech(): void
    {
        $weights = array_fill(0, 97, 3);
        $parts   = BundleExpander::splitVnd(1_000_000_007, $weights);

        $this->assertSame(1_000_000_007, array_sum($parts));
        $this->assertCount(97, $parts);
    }
}
