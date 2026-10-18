<?php

namespace Tests\Feature\Api\V2;

use App\Http\Resources\V2\ScreenSummaryResource;
use Tests\TestCase;

/**
 * `screen_type_label` — nhãn người đọc cho một trường **dẫn xuất**.
 *
 * ══ Vì sao trường này tồn tại ══
 *
 * Soát production 18/10/2026: thẻ màn hình trên trang công khai in chữ
 * `lcd` ra mặt người dùng, ở hàng "Loại hình". Giao diện đọc thẳng
 * `screen_type` — là **mã**, không phải nhãn.
 *
 * Dự án đã có quy ước cho chuyện này: bảng chữ của một enum sống ở **một**
 * nơi, và `NhanEnumMotNoiTest` tự tìm mọi hằng `<CỘT>_LABELS` trên model.
 * Nhưng `screen_type` không phải một cột — nó suy ra từ kích thước và
 * `venue_type` trong `ScreenSummaryResource::resolveScreenType()`. Nên bảng
 * chữ của nó nằm cạnh chỗ sinh ra mã, và chốt tự tìm kia không với tới.
 *
 * Tệp này là chỗ với tới. Nó canh đúng một điều mà bộ dò kia canh cho bảy
 * cột khác: **thêm một nhánh mã mà quên thêm nhãn thì đỏ.**
 */
class NhanLoaiManHinhTest extends TestCase
{
    /** Ba nhánh của `resolveScreenType()`, đọc từ chính mã nguồn. */
    private function maTrongResolve(): array
    {
        $nguon = file_get_contents(
            base_path('app/Http/Resources/V2/ScreenSummaryResource.php'),
        );

        $than = substr($nguon, strpos($nguon, 'private function resolveScreenType'));

        preg_match_all("/return\s+'([a-z_]+)'|\?\s*'([a-z_]+)'\s*:\s*'([a-z_]+)'/", $than, $k);

        return array_values(array_unique(array_filter(array_merge($k[1], $k[2], $k[3]))));
    }

    public function test_moi_ma_resolve_sinh_ra_deu_co_nhan(): void
    {
        $ma = $this->maTrongResolve();

        $this->assertNotEmpty($ma, 'Không đọc được nhánh nào — bộ dò đã vỡ.');

        foreach ($ma as $m) {
            $this->assertArrayHasKey(
                $m,
                ScreenSummaryResource::SCREEN_TYPE_LABELS,
                "`resolveScreenType()` có thể trả `{$m}` nhưng `SCREEN_TYPE_LABELS` "
                . 'không có nhãn cho nó — giao diện sẽ in mã thô ra trang.',
            );
        }
    }

    public function test_bo_do_nhanh_khong_rong(): void
    {
        // Không có ca này thì một regex vỡ làm `maTrongResolve()` trả mảng
        // rỗng, và phép kiểm trên xanh vì không có gì để duyệt — đúng kiểu
        // "chốt hỏng mà vẫn xanh" đã gặp nhiều lần.
        $ma = $this->maTrongResolve();

        sort($ma);

        $this->assertSame(
            ['billboard', 'lcd', 'led'],
            $ma,
            'Bộ dò phải đọc được đúng ba nhánh của `resolveScreenType()`.',
        );
    }

    public function test_nhan_khong_phai_la_ma(): void
    {
        // Cùng luật với `NhanEnumMotNoiTest`: thứ không bao giờ là nhãn là
        // một mã có dấu gạch dưới hoặc một chuỗi thường y hệt mã.
        foreach (ScreenSummaryResource::SCREEN_TYPE_LABELS as $ma => $nhan) {
            $this->assertStringNotContainsString('_', $nhan, "Nhãn `{$nhan}` còn dấu gạch dưới.");

            $this->assertNotSame(
                $ma,
                $nhan,
                "Nhãn của `{$ma}` giống hệt mã — `lcd` phải ra `LCD`.",
            );
        }
    }

    public function test_khong_co_nhan_du_thua(): void
    {
        // Nhãn cho một mã không còn tồn tại là một dòng chết, và nó làm người
        // đọc sau tưởng `resolveScreenType()` còn trả giá trị đó.
        $ma = $this->maTrongResolve();

        foreach (array_keys(ScreenSummaryResource::SCREEN_TYPE_LABELS) as $m) {
            $this->assertContains(
                $m,
                $ma,
                "`SCREEN_TYPE_LABELS` có nhãn cho `{$m}` nhưng `resolveScreenType()` "
                . 'không bao giờ trả mã đó — gạch nó khỏi bảng.',
            );
        }
    }
}
