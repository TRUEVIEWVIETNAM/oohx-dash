@php
    use App\Services\SoatViTriService;
@endphp

<x-filament-panels::page>
    {{-- ── Phạm vi: nói trước khi báo cáo nói gì ────────────────────────── --}}
    <x-filament::section>
        <x-slot name="heading">Phạm vi lượt soát</x-slot>

        <div class="space-y-3 text-sm">
            <p>
                Soát <strong>{{ $kq['tong_man_hinh'] }}</strong> màn hình đang công khai,
                lúc {{ $kq['luc']->format('d/m/Y H:i') }}.
                @if($soCao > 0)
                    <span class="text-danger-600 dark:text-danger-400 font-medium">{{ $soCao }} phát hiện mức cao</span>
                @else
                    <span class="text-success-600 dark:text-success-400 font-medium">Không có phát hiện mức cao</span>
                @endif
                @if($soXemTay > 0)
                    · {{ $soXemTay }} dòng cần người đọc quyết định
                @endif
            </p>

            <p class="text-gray-500 dark:text-gray-400">
                <strong>Không soát ở đây:</strong> giá, ảnh thực địa, kích thước, và
                màn hình chưa công khai. Chạy ở dòng lệnh:
                <code class="text-xs">php artisan oohx:soat-vi-tri</code>
            </p>
        </div>
    </x-filament::section>

    {{-- ── Tổng hợp, kèm cột độ phủ ─────────────────────────────────────── --}}
    <x-filament::section>
        <x-slot name="heading">Từng phép đo</x-slot>
        <x-slot name="description">
            Cột <em>đối chiếu được</em> là số bản ghi phép đo thật sự xét tới. Nó
            phân biệt “kho sạch” với “phép đo không chạy được trên bản ghi nào”.
        </x-slot>

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="text-left text-gray-500 dark:text-gray-400">
                    <tr class="border-b border-gray-200 dark:border-gray-700">
                        <th class="py-2 pr-4 font-medium">Phép đo</th>
                        <th class="py-2 pr-4 font-medium">Mức</th>
                        <th class="py-2 pr-4 font-medium text-right">Phát hiện</th>
                        <th class="py-2 pr-4 font-medium text-right">Đối chiếu được</th>
                        <th class="py-2 font-medium">Hệ quả với người mua</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($theoLoai as $loai => $t)
                        <tr class="border-b border-gray-100 dark:border-gray-800 align-top">
                            <td class="py-2 pr-4 font-medium">{{ SoatViTriService::NHAN[$loai] ?? $loai }}</td>
                            <td class="py-2 pr-4">
                                @if($t['muc'] === SoatViTriService::MUC_CAO)
                                    <x-filament::badge color="danger" size="sm">{{ SoatViTriService::MUC_NHAN[SoatViTriService::MUC_CAO] }}</x-filament::badge>
                                @else
                                    <x-filament::badge color="warning" size="sm">{{ SoatViTriService::MUC_NHAN[SoatViTriService::MUC_XEM_TAY] }}</x-filament::badge>
                                @endif
                            </td>
                            <td class="py-2 pr-4 text-right tabular-nums">
                                @if($t['so'] > 0)
                                    <strong>{{ $t['so'] }}</strong>
                                @else
                                    <span class="text-gray-400">—</span>
                                @endif
                            </td>
                            <td class="py-2 pr-4 text-right tabular-nums
                                       {{ $t['do_phu'] === 0 ? 'text-warning-600 dark:text-warning-400 font-medium' : 'text-gray-500' }}">
                                {{ $t['do_phu'] }}/{{ $kq['tong_man_hinh'] }}
                            </td>
                            <td class="py-2 text-gray-500 dark:text-gray-400">
                                {{ SoatViTriService::HE_QUA[$loai] ?? '' }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-filament::section>

    {{-- ── Chi tiết từng phát hiện ──────────────────────────────────────── --}}
    @if($nhomLoi->isEmpty())
        <x-filament::section>
            <div class="py-6 text-center space-y-2">
                <x-heroicon-o-check-circle class="w-10 h-10 mx-auto text-success-500" />
                <p class="text-sm text-gray-500 dark:text-gray-400">
                    Không có phát hiện nào trong phạm vi các phép đo trên.
                </p>
            </div>
        </x-filament::section>
    @else
        @foreach($nhomLoi as $loai => $nhom)
            <x-filament::section collapsible :collapsed="$nhom->first()['muc'] !== SoatViTriService::MUC_CAO">
                <x-slot name="heading">
                    {{ SoatViTriService::NHAN[$loai] ?? $loai }} — {{ $nhom->count() }} bản ghi
                </x-slot>
                <x-slot name="description">{{ SoatViTriService::HE_QUA[$loai] ?? '' }}</x-slot>

                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="text-left text-gray-500 dark:text-gray-400">
                            <tr class="border-b border-gray-200 dark:border-gray-700">
                                <th class="py-2 pr-4 font-medium">Màn hình</th>
                                <th class="py-2 pr-4 font-medium">Điểm đặt</th>
                                <th class="py-2 pr-4 font-medium">Tỉnh/thành</th>
                                <th class="py-2 pr-4 font-medium">Media owner</th>
                                <th class="py-2 font-medium">Chi tiết</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($nhom as $l)
                                <tr class="border-b border-gray-100 dark:border-gray-800 align-top">
                                    <td class="py-2 pr-4">
                                        <div class="font-medium">{{ $l['screen_name'] }}</div>
                                        <a href="{{ config('app.url') }}/explore/{{ $l['screen_slug'] }}"
                                           target="_blank" rel="noopener"
                                           class="text-xs text-primary-600 dark:text-primary-400 hover:underline">
                                            {{ $l['screen_slug'] }}
                                        </a>
                                    </td>
                                    <td class="py-2 pr-4">{{ $l['site_name'] ?: '—' }}</td>
                                    <td class="py-2 pr-4">{{ $l['city'] ?: '—' }}</td>
                                    <td class="py-2 pr-4">{{ trim((string) $l['owner_name']) ?: '—' }}</td>
                                    <td class="py-2 text-gray-600 dark:text-gray-300">{{ $l['chi_tiet'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </x-filament::section>
        @endforeach
    @endif
</x-filament-panels::page>
