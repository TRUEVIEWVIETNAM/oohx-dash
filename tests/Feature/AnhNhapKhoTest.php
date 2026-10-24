<?php

namespace Tests\Feature;

use App\Models\Owner;
use App\Models\Site;
use App\Services\ScreenImport\RowValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Cột ảnh của đường nhập kho — lát 1 của `docs/PLAN-ANH-VI-TRI.md`.
 *
 * ══ Cột này trước hôm nay không có luật nào ══
 *
 * `FieldCatalog:57` khai `spec.photo_url` là `string`, và `RowValidator` không
 * có một dòng nào cho nó. Giá trị đi thẳng từ ô bảng tính vào
 * `screen_specs.photos`, và từ đó ra trang công khai — không qua một phép kiểm
 * nào.
 *
 * Nghĩa là một ô chứa `../../etc/passwd`, hay `javascript:alert(1)`, hay một
 * URL `http://` mà trình duyệt sẽ chặn trên trang HTTPS, đều được **ghi** và
 * không ai thấy. Kho sắp nhận 10.000 hàng từ một đối tác, nên "không ai điền
 * sai đâu" không còn là một giả định dùng được.
 *
 * ══ Hai dạng hợp lệ, vì cả hai đều có thật ══
 *
 * Đường dẫn tương đối trong disk công khai (biểu mẫu tải ảnh của Filament ghi
 * vào) và URL `https` đầy đủ (đối tác đưa). Mọi dạng khác là một hàng cần
 * người đọc lại.
 */
class AnhNhapKhoTest extends TestCase
{
    use RefreshDatabase;

    private function validator(): RowValidator
    {
        $owner = Owner::factory()->create(['status' => 'active']);

        Site::factory()->create([
            'owner_id'    => $owner->id,
            'external_id' => 'SITE-A',
            'status'      => 'active',
            'city'        => 'Hà Nội',
        ]);

        return new RowValidator($owner->id);
    }

    /** Một hàng hợp lệ ở mọi trường KHÁC, để lỗi còn lại chỉ có thể là lỗi ảnh. */
    private function hang(?string $anh): array
    {
        return [
            'screens' => ['external_id' => 'SCR-1', 'name' => 'Màn hình một'],
            'site'    => ['external_id' => 'SITE-A'],
            'spec'    => $anh === null ? [] : ['photo_url' => $anh],
        ];
    }

    private function loi(?string $anh): array
    {
        return $this->validator()->validate($this->hang($anh));
    }

    // ── Hai dạng hợp lệ ─────────────────────────────────────────────────────

    public function test_duong_dan_tuong_doi_va_url_https_deu_qua(): void
    {
        foreach ([
            null,                                   // không điền: không phải lỗi
            'screen-photos/a.jpg',
            'screen-photos/2026/01/a.webp',
            'https://doitac.example/anh/a.jpg',
            'https://doitac.example/a.jpg?v=2',
        ] as $anh) {
            $this->assertSame(
                [],
                $this->loi($anh),
                'Giá trị hợp lệ bị từ chối: ' . var_export($anh, true),
            );
        }
    }

    // ── Dạng bị từ chối ─────────────────────────────────────────────────────

    /**
     * `..` ở bất cứ đâu trong đường dẫn.
     *
     * `storage/app/public` là gốc; một đường dẫn đi lên khỏi nó trỏ vào tệp
     * của máy chủ. Đây là ca mà plan ghi tên.
     */
    public function test_duong_dan_di_ra_khoi_disk_cong_khai_bi_tu_choi(): void
    {
        foreach ([
            '../../etc/passwd',
            'screen-photos/../../../.env',
            'a/../../b.jpg',
        ] as $anh) {
            $loi = $this->loi($anh);

            $this->assertNotSame([], $loi, "Đường dẫn vượt gốc được nhận: {$anh}");
            $this->assertStringContainsString('ra khỏi disk công khai', $loi[0]);
        }
    }

    public function test_http_bi_tu_choi_va_noi_ro_vi_sao(): void
    {
        $loi = $this->loi('http://doitac.example/a.jpg');

        $this->assertNotSame([], $loi);

        // Câu lỗi phải nói **vì sao**, không chỉ "không hợp lệ": người sửa hàng
        // là một người ở phía đối tác, và họ cần biết đổi thành gì.
        $this->assertStringContainsString('https', $loi[0]);
        $this->assertStringContainsString('chặn ảnh HTTP', $loi[0]);
    }

    /**
     * Không tự "nâng cấp" `http` thành `https`.
     *
     * Đổi giao thức hộ đối tác là đoán rằng máy chủ của họ có HTTPS. Đoán sai
     * thì ảnh vỡ, và lần này vỡ do mình sửa.
     */
    public function test_khong_tu_doi_http_thanh_https(): void
    {
        $hang = $this->hang('http://doitac.example/a.jpg');

        $this->validator()->validate($hang);

        $this->assertSame(
            'http://doitac.example/a.jpg',
            $hang['spec']['photo_url'],
            'Validator không được sửa dữ liệu — nó chỉ nói hàng nào sai.',
        );
    }

    public function test_giao_thuc_khac_bi_tu_choi(): void
    {
        foreach ([
            'javascript:alert(1)',
            'data:image/png;base64,iVBORw0KGgo=',
            'file:///etc/passwd',
            'ftp://doitac.example/a.jpg',
        ] as $anh) {
            $loi = $this->loi($anh);

            $this->assertNotSame([], $loi, "Giao thức lạ được nhận: {$anh}");
            $this->assertStringContainsString('chỉ nhận https hoặc đường dẫn tương đối', $loi[0]);
        }
    }

    public function test_duong_dan_tuyet_doi_bi_tu_choi(): void
    {
        foreach (['/var/www/a.jpg', '\\\\may-chu\\anh\\a.jpg'] as $anh) {
            $loi = $this->loi($anh);

            $this->assertNotSame([], $loi, "Đường dẫn tuyệt đối được nhận: {$anh}");
            $this->assertStringContainsString('phải tương đối', $loi[0]);
        }
    }

    public function test_byte_nul_bi_tu_choi(): void
    {
        // `a.jpg\0.php` là cách cổ điển để qua một phép kiểm phần mở rộng.
        $loi = $this->loi("screen-photos/a.jpg\0.php");

        $this->assertNotSame([], $loi);
        $this->assertStringContainsString('NUL', $loi[0]);
    }

    public function test_https_khong_phai_url_that_bi_tu_choi(): void
    {
        $loi = $this->loi('https://');

        $this->assertNotSame([], $loi);
        $this->assertStringContainsString('không phải URL hợp lệ', $loi[0]);
    }

    // ── Luật ảnh không được che luật khác ──────────────────────────────────

    public function test_o_anh_trong_khong_sinh_loi(): void
    {
        foreach (['', '   '] as $anh) {
            $this->assertSame([], $this->loi($anh), 'Ô ảnh trống không phải lỗi.');
        }
    }

    public function test_hang_sai_ca_ten_va_anh_bao_ca_hai(): void
    {
        $loi = $this->validator()->validate([
            'screens' => ['external_id' => 'SCR-1'],   // thiếu tên
            'site'    => ['external_id' => 'SITE-A'],
            'spec'    => ['photo_url' => '../../etc/passwd'],
        ]);

        // Một hàng sai hai chỗ phải báo hai chỗ: người sửa không muốn nhập lại
        // rồi gặp lỗi thứ hai.
        $this->assertCount(2, $loi);
    }
}
