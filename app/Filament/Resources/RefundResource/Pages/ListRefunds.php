<?php

namespace App\Filament\Resources\RefundResource\Pages;

use App\Filament\Resources\RefundResource;
use Filament\Resources\Pages\ListRecords;

class ListRefunds extends ListRecords
{
    protected static string $resource = RefundResource::class;

    /**
     * Không có `CreateAction`: khoản hoàn tiền chỉ sinh ra từ đường hủy đặt
     * chỗ, với số tiền do chính sách quyết định. Một nút "tạo khoản hoàn tiền"
     * là một đường nhập số tiền bằng tay.
     */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
