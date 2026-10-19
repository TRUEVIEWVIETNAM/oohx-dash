<?php

namespace App\Services;

use App\Models\PublicReflection;
use App\Support\PhanTrangOnDinh;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class PublicReflectionService
{
    use PhanTrangOnDinh;

    /**
     * Ghi nhận một phản ánh mới từ tổ chức xã hội.
     *
     * Không tự đăng công khai: `published_at` để null, admin đọc rồi mới cho lên
     * danh sách. Một form công khai mà tự xuất bản là cửa ngỏ cho spam và cho cả
     * nội dung bôi nhọ bên thứ ba.
     *
     * @param  array{organization_name:string,subject:string,content:string,contact_name?:string,contact_email?:string,contact_phone?:string}  $data
     */
    public function record(array $data, ?string $ip = null): PublicReflection
    {
        return DB::transaction(function () use ($data, $ip) {
            return PublicReflection::create([
                'code'              => $this->generateCode(),
                'organization_name' => $data['organization_name'],
                'subject'           => $data['subject'],
                'content'           => $data['content'],
                'contact_name'      => $data['contact_name']  ?? null,
                'contact_email'     => $data['contact_email'] ?? null,
                'contact_phone'     => $data['contact_phone'] ?? null,
                'received_at'       => now(),
                'status'            => PublicReflection::STATUS_PENDING,
                'submitted_ip'      => $ip,
            ]);
        });
    }

    /**
     * Danh sách phản ánh công khai.
     *
     * `select` liệt kê tường minh thay vì lấy cả bảng: email và số điện thoại
     * người gửi không được rời khỏi tầng này.
     */
    public function published(int $perPage = 20): LengthAwarePaginator
    {
        $query = PublicReflection::published()
            ->select([
                'id', 'code', 'organization_name', 'subject', 'content',
                'received_at', 'status', 'resolution', 'resolved_at',
            ])
            ->orderByDesc('received_at');

        return $this->phanTrang($query, $perPage);
    }

    /**
     * Mã tra cứu: PA-YYYYMM-XXXX. Cùng dạng với mã campaign (CPN-YYYYMM-XXXX).
     */
    public function generateCode(): string
    {
        $prefix = 'PA-' . now()->format('Ym') . '-';
        $last = PublicReflection::where('code', 'like', $prefix . '%')
            ->orderByDesc('code')
            ->value('code');

        $num = $last ? (int) substr($last, -4) + 1 : 1;

        return $prefix . str_pad($num, 4, '0', STR_PAD_LEFT);
    }
}
