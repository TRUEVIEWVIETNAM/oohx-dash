<?php

namespace Tests\Feature;

use App\Models\PublicReflection;
use App\Services\PublicReflectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tiếp nhận và công bố phản ánh của tổ chức xã hội (review mục 3).
 *
 * Điểm dễ sai nhất của tính năng này không phải là form, mà là ranh giới giữa
 * phần công khai (tổ chức, nội dung, kết quả xử lý) và phần không được công khai
 * (email, điện thoại người gửi, ghi chú nội bộ). Phần lớn test dưới đây canh
 * đúng ranh giới đó.
 */
class PublicReflectionTest extends TestCase
{
    use RefreshDatabase;

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'organization_name' => 'Hội Bảo vệ người tiêu dùng TP. Hà Nội',
            'subject'           => 'Phản ánh về thông tin màn hình quảng cáo',
            'content'           => 'Nội dung phản ánh đủ dài để vượt qua ngưỡng tối thiểu hai mươi ký tự.',
            'contact_name'      => 'Nguyễn Văn A',
            'contact_email'     => 'nguoigui@example.org',
            'contact_phone'     => '0900000000',
        ], $overrides);
    }

    private function domain(): string
    {
        return config('domains.frontpage', 'oohx.net');
    }

    // ── Tiếp nhận ────────────────────────────────────────────────────────────

    // ── Tám ca qua biểu mẫu Blade: GỠ, đã có bản thay ở API ─────────────
    //
    // Chúng gọi `GET/POST /phan-anh-to-chuc-xa-hoi` và
    // `GET /phan-anh-to-chuc-xa-hoi/danh-sach` của Blade. Giai đoạn 7
    // (07/10/2026) gỡ hai trang đó — Next.js phục vụ chúng, và biểu mẫu gửi
    // sang `/api/v2/reflections`.
    //
    // Đây KHÔNG phải mất coverage: `Api\V2\PublicContentApiTest` phủ đúng
    // từng bảo đảm, và phủ ở nơi lưu lượng thật đi qua:
    //
    //   gửi được, trả mã tra cứu      → test_gui_duoc_va_tra_ve_ma_tra_cuu
    //   bản ghi mới chưa công bố      → test_ban_ghi_moi_chua_duoc_cong_bo
    //   thiếu email thì không nhận    → test_thieu_email_lien_he_thi_khong_nhan
    //   nội dung quá ngắn             → test_noi_dung_qua_ngan_bi_tu_choi_...
    //   bẫy mật chặn bot              → test_bay_mat_chan_duoc_bot_qua_api
    //   chỉ hiện bản đã công bố       → test_chi_tra_phan_anh_da_cong_bo
    //   không lộ email/điện thoại     → test_khong_bao_gio_lo_du_lieu_ca_nhan_...
    //   ghi chú nội bộ không ra ngoài → cùng ca trên
    //
    // Bộ luật kiểm thì vốn đã dùng chung: `StorePublicReflectionRequest` phục
    // vụ cả hai đường vào, nên không có bộ luật thứ hai nào mất theo trang.
    //
    // Hai ca còn lại trong lớp này ở mức MODEL, không qua HTTP, nên chúng
    // không phụ thuộc đường dẫn nào.

    public function test_serialize_model_khong_kem_du_lieu_ca_nhan(): void
    {
        $reflection = app(PublicReflectionService::class)->record($this->payload());

        $array = $reflection->toArray();

        $this->assertArrayNotHasKey('contact_email', $array);
        $this->assertArrayNotHasKey('contact_phone', $array);
        $this->assertArrayNotHasKey('contact_name', $array);
        $this->assertArrayNotHasKey('internal_notes', $array);
        $this->assertArrayNotHasKey('submitted_ip', $array);
        $this->assertArrayHasKey('organization_name', $array, 'phần công khai vẫn phải serialize được');
    }

    // ── Mã tra cứu ───────────────────────────────────────────────────────────

    public function test_ma_tra_cuu_tang_dan_va_khong_trung(): void
    {
        $svc = app(PublicReflectionService::class);

        $a = $svc->record($this->payload());
        $b = $svc->record($this->payload());

        $prefix = 'PA-' . now()->format('Ym') . '-';
        $this->assertSame($prefix . '0001', $a->code);
        $this->assertSame($prefix . '0002', $b->code);
    }
}
