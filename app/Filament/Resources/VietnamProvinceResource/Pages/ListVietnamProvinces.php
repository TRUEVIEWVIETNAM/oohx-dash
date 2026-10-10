<?php

namespace App\Filament\Resources\VietnamProvinceResource\Pages;

use App\Filament\Resources\VietnamProvinceResource;
use App\Filament\Resources\VietnamProvinceResource\Widgets\ProvinceStatsWidget;
use App\Models\VietnamCommune;
use App\Models\VietnamProvince;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\Artisan;

class ListVietnamProvinces extends ListRecords
{
    protected static string $resource = VietnamProvinceResource::class;

    protected function getHeaderWidgets(): array
    {
        return [ProvinceStatsWidget::class];
    }

    protected function getHeaderActions(): array
    {
        $communeCount  = VietnamCommune::count();
        $provinceCount = VietnamProvince::count();

        return [
            // ── Nút seed 34 tỉnh/thành (chỉ hiện khi chưa có dữ liệu) ────────
            Actions\Action::make('seed_provinces')
                ->label('Seed 34 Tỉnh/Thành 2025')
                ->icon('heroicon-o-circle-stack')
                ->color('warning')
                ->requiresConfirmation()
                ->modalHeading('Seed 34 tỉnh/thành phố (2025)')
                ->modalDescription('Sẽ xóa dữ liệu tỉnh/thành cũ và seed lại 8 vùng + 34 tỉnh/thành theo địa giới hành chính mới từ 01/07/2025. Tiếp tục?')
                ->modalSubmitActionLabel('Nạp ngay')
                ->modalCancelActionLabel('Hủy')
                ->visible($provinceCount === 0)
                ->action(function () {
                    try {
                        Artisan::call('db:seed', ['--class' => 'VietnamAdminDivisions2025Seeder', '--force' => true]);
                        $count = VietnamProvince::count();
                        Notification::make()
                            ->success()
                            ->title('Seed hoàn thành!')
                            ->body("Đã seed {$count} tỉnh/thành phố vào hệ thống. Bây giờ bạn có thể fetch phường/xã.")
                            ->persistent()
                            ->send();
                    } catch (\Throwable $e) {
                        Notification::make()
                            ->danger()
                            ->title('Lỗi khi seed')
                            ->body($e->getMessage())
                            ->persistent()
                            ->send();
                    }
                }),

            // ── Nút fetch phường/xã từ API ────────────────────────────────────
            Actions\Action::make('fetch_communes')
                ->label('Cập nhật phường/xã từ API')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('success')
                ->badge($communeCount > 0 ? number_format($communeCount) : null)
                ->badgeColor('gray')
                ->requiresConfirmation()
                ->modalHeading('Fetch 3321 phường/xã/thị trấn')
                ->modalDescription(
                    $provinceCount === 0
                        ? 'Chưa có dữ liệu tỉnh/thành! Vui lòng nhấn "Seed 34 Tỉnh/Thành 2025" trước.'
                        : ($communeCount > 0
                            ? "Hiện có {$communeCount} phường/xã trong hệ thống. Quá trình sẽ cập nhật (upsert) từ API 34tinhthanh.com, ước tính 3-5 phút. Tiếp tục?"
                            : 'Chưa có dữ liệu phường/xã. Quá trình fetch ~3321 đơn vị từ API 34tinhthanh.com, ước tính 3-5 phút. Tiếp tục?')
                )
                ->modalSubmitActionLabel('Bắt đầu fetch')
                ->modalCancelActionLabel('Hủy')
                ->action(function () use ($provinceCount) {
                    if ($provinceCount === 0) {
                        Notification::make()
                            ->warning()
                            ->title('Chưa có tỉnh/thành')
                            ->body('Vui lòng nhấn "Seed 34 Tỉnh/Thành 2025" trước khi fetch phường/xã.')
                            ->persistent()
                            ->send();
                        return;
                    }
                    try {
                        $exitCode = Artisan::call('vietnam:fetch', ['--only' => 'communes', '--delay' => 200]);

                        if ($exitCode === 0) {
                            $count = VietnamCommune::count();
                            Notification::make()
                                ->success()
                                ->title('Fetch hoàn thành!')
                                ->body("Đã lưu " . number_format($count) . " phường/xã/thị trấn vào hệ thống.")
                                ->persistent()
                                ->send();
                        } else {
                            Notification::make()
                                ->warning()
                                ->title('Fetch kết thúc có lỗi')
                                ->body('Kiểm tra log để biết chi tiết. Một số tỉnh có thể chưa được fetch.')
                                ->persistent()
                                ->send();
                        }
                    } catch (\Throwable $e) {
                        Notification::make()
                            ->danger()
                            ->title('Lỗi khi fetch')
                            ->body($e->getMessage())
                            ->persistent()
                            ->send();
                    }
                }),

            // ── Nút fetch + reset toàn bộ ─────────────────────────────────────
            Actions\Action::make('fetch_fresh')
                ->label('Reset & Fetch lại')
                ->icon('heroicon-o-arrow-path')
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading('⚠ Xóa sạch và fetch lại toàn bộ')
                ->modalDescription('Sẽ XÓA toàn bộ dữ liệu phường/xã hiện tại, sau đó fetch lại 3321 đơn vị từ API. Thao tác này không thể hoàn tác!')
                ->modalSubmitActionLabel('Xác nhận xóa & fetch lại')
                ->modalCancelActionLabel('Hủy')
                ->visible($communeCount > 0)
                ->action(function () {
                    try {
                        Artisan::call('vietnam:fetch', ['--only' => 'communes', '--fresh' => true, '--delay' => 200]);
                        $count = VietnamCommune::count();

                        Notification::make()
                            ->success()
                            ->title('Reset & Fetch hoàn thành!')
                            ->body("Đã fetch lại " . number_format($count) . " phường/xã/thị trấn.")
                            ->persistent()
                            ->send();
                    } catch (\Throwable $e) {
                        Notification::make()
                            ->danger()
                            ->title('Lỗi')
                            ->body($e->getMessage())
                            ->send();
                    }
                }),

            Actions\CreateAction::make(),
        ];
    }
}
