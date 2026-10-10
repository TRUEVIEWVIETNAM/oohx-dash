<?php

namespace App\Filament\Resources\OohxCampaignEstimateResource\Pages;

use App\Filament\Resources\OohxCampaignEstimateResource;
use App\Filament\Resources\OohxRecomputeJobResource;
use App\Models\Screen as LocalScreen;
use App\Services\Oohx\JobOrchestrator;
use Filament\Actions;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;

/**
 * Phase 4.1 — Campaign Planner create form.
 *
 * Đây KHÔNG phải CreateRecord mặc định của Filament — connection oohx là
 * readonly, không thể INSERT trực tiếp. Thay vào đó form submit → enqueue
 * job qua JobOrchestrator::enqueueCampaign() → redirect sang Job View để
 * user poll progress.
 *
 * Screen picker: multi-select từ local `screens` table (ULID). Service tự
 * resolve UUID → DE bigint khi enqueue.
 *
 * @property array $data
 */
class CreateOohxCampaignEstimate extends Page
{
    protected static string $resource = OohxCampaignEstimateResource::class;

    protected static string $view = 'filament.resources.oohx-campaign-estimate.create';

    public ?array $data = [];

    public function getTitle(): string | Htmlable
    {
        return 'New Campaign Forecast';
    }

    public function getBreadcrumb(): ?string
    {
        return 'New';
    }

    public function mount(): void
    {
        // Allow pre-fill from query string (?screens=ulid1,ulid2,ulid3)
        // Dùng cho bulk action trên ScreenResource → pre-populate form
        $pre = request('screens');
        $preIds = [];
        if ($pre) {
            $preIds = array_values(array_filter(explode(',', (string) $pre)));
        }

        $this->form->fill([
            'screen_ids'    => $preIds,
            'duration_days' => 30,
            'campaign_name' => null,
            'total_budget'  => null,
            'notes'         => null,
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->statePath('data')
            ->schema([
                Forms\Components\Section::make('Chọn màn hình')
                    ->icon('heroicon-o-tv')
                    ->description('Chọn danh sách biển để forecast. Service tự resolve Laravel ULID → Data Engine screen IDs.')
                    ->schema([
                        Forms\Components\Select::make('screen_ids')
                            ->label('Màn hình')
                            ->multiple()
                            ->required()
                            ->searchable()
                            ->preload()
                            ->minItems(JobOrchestrator::CAMPAIGN_MIN_SCREENS)
                            ->maxItems(JobOrchestrator::CAMPAIGN_MAX_SCREENS)
                            ->options(fn () => LocalScreen::query()
                                ->where('active', true)
                                ->orderBy('name')
                                ->limit(1000)
                                ->pluck('name', 'id')
                                ->all())
                            ->getOptionLabelsUsing(fn (array $values) => LocalScreen::query()
                                ->whereIn('id', $values)
                                ->pluck('name', 'id')
                                ->all())
                            ->helperText(fn () => 'Tối đa ' . JobOrchestrator::CAMPAIGN_MAX_SCREENS . ' screens / campaign.'),

                        Forms\Components\Placeholder::make('screen_count_label')
                            ->label('Tổng đã chọn')
                            ->content(fn (Forms\Get $get) => count($get('screen_ids') ?? []) . ' screens'),
                    ]),

                Forms\Components\Section::make('Thời lượng và ngân sách')
                    ->icon('heroicon-o-calendar-days')
                    ->columns(3)
                    ->schema([
                        Forms\Components\TextInput::make('duration_days')
                            ->label('Số ngày')
                            ->required()
                            ->numeric()
                            ->minValue(JobOrchestrator::CAMPAIGN_MIN_DURATION_DAYS)
                            ->maxValue(JobOrchestrator::CAMPAIGN_MAX_DURATION_DAYS)
                            ->default(30)
                            ->suffix('days'),

                        Forms\Components\TextInput::make('total_budget')
                            ->label('Ngân sách (VND, không bắt buộc)')
                            ->numeric()
                            ->minValue(0)
                            ->prefix('VND')
                            ->helperText('Để trống → CPM = null'),

                        Forms\Components\TextInput::make('campaign_name')
                            ->label('Tên chiến dịch')
                            ->maxLength(120)
                            ->placeholder('VD: Tết 2026 Hanoi'),
                    ]),

                Forms\Components\Section::make('Ghi chú')
                    ->icon('heroicon-o-pencil-square')
                    ->collapsible()
                    ->collapsed()
                    ->schema([
                        Forms\Components\Textarea::make('notes')
                            ->label('Ghi chú (không bắt buộc)')
                            ->rows(2)
                            ->maxLength(500),
                    ]),

                Forms\Components\Section::make('Về ước lượng này')
                    ->icon('heroicon-o-information-circle')
                    ->collapsible()
                    ->collapsed()
                    ->schema([
                        Forms\Components\Placeholder::make('disclaimer')
                            ->label('Lưu ý về mô hình')
                            ->content(new \Illuminate\Support\HtmlString(
                                'Reach dựa trên mô hình spatial dedup simple (ST_GeoHash 150m cells × 500 viewers/day × log saturation). '
                                . 'Numbers là <strong>directional estimate</strong>, không phải measured reality. '
                                . 'Sẽ refine dần khi có real population density (Phase 4.2.1) + traffic calibration samples (Phase 4.2.4).'
                            )),
                    ]),
            ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('back')
                ->label('Huỷ')
                ->icon('heroicon-o-x-mark')
                ->color('gray')
                ->url(fn () => OohxCampaignEstimateResource::getUrl('index')),
        ];
    }

    public function forecast(): void
    {
        $data = $this->form->getState();

        try {
            $job = app(JobOrchestrator::class)->enqueueCampaign(
                laravelScreenIds: $data['screen_ids'] ?? [],
                durationDays: (int) $data['duration_days'],
                campaignName: $data['campaign_name'] ?? null,
                totalBudget: isset($data['total_budget']) && $data['total_budget'] !== ''
                    ? (float) $data['total_budget']
                    : null,
                notes: $data['notes'] ?? null,
            );

            Notification::make()
                ->title("Campaign job #{$job->id} queued")
                ->body("Worker sẽ compute aggregate ~1-2s. Polling job detail để xem result.")
                ->success()
                ->persistent()
                ->send();

            $this->redirect(OohxRecomputeJobResource::getUrl('view', ['record' => $job->id]));
        } catch (\Throwable $e) {
            Notification::make()
                ->title('Không đưa vào hàng đợi được')
                ->body($e->getMessage())
                ->danger()
                ->persistent()
                ->send();
        }
    }
}
