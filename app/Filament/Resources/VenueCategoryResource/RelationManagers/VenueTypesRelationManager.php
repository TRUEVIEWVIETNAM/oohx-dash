<?php

namespace App\Filament\Resources\VenueCategoryResource\RelationManagers;

use App\Models\VenueType;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class VenueTypesRelationManager extends RelationManager
{
    protected static string $relationship = 'venueTypes';
    protected static ?string $title = 'Loại địa điểm OpenOOH đã ghép';
    protected static ?string $recordTitleAttribute = 'venue_type';

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('enumeration_id')
                ->label('Mã enum')
                ->numeric()
                ->disabled(),

            Forms\Components\TextInput::make('string_value')
                ->label('Giá trị chuỗi')
                ->disabled(),

            Forms\Components\TextInput::make('venue_type')
                ->label('Loại địa điểm')
                ->required(),

            Forms\Components\TextInput::make('category')
                ->label('Danh mục OpenOOH')
                ->required(),

            Forms\Components\TextInput::make('subcategory')
                ->label('Danh mục con'),

            Forms\Components\Toggle::make('is_active')
                ->label('Đang bật')
                ->default(true),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('enumeration_id')
                    ->label('ID')
                    ->sortable()
                    ->width('70px'),

                Tables\Columns\TextColumn::make('venue_type')
                    ->label('Loại địa điểm')
                    ->searchable()
                    ->sortable()
                    ->formatStateUsing(fn (VenueType $record) =>
                        str_repeat('— ', max(0, $record->depth - 1)) . $record->venue_type
                    )
                    ->description(fn (VenueType $record) => $record->string_value),

                Tables\Columns\TextColumn::make('category')
                    ->label('Danh mục OpenOOH')
                    ->badge()
                    ->sortable(),

                Tables\Columns\TextColumn::make('depth')
                    ->label('Cấp')
                    ->badge()
                    ->formatStateUsing(fn ($state) => match ((int) $state) {
                        0 => 'Root',
                        1 => 'Category',
                        2 => 'Sub',
                        default => "L{$state}",
                    })
                    ->color(fn ($state) => match ((int) $state) {
                        0 => 'danger',
                        1 => 'warning',
                        2 => 'success',
                        default => 'gray',
                    }),

                Tables\Columns\IconColumn::make('is_active')
                    ->label('Đang bật')
                    ->boolean(),

                Tables\Columns\IconColumn::make('hivestack_supported')
                    ->label('Hivestack')
                    ->boolean(),
            ])
            ->defaultSort('enumeration_id', 'asc')
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active')->label('Đang bật'),
            ])
            ->headerActions([
                Tables\Actions\Action::make('assign_types')
                    ->label('Gán loại địa điểm')
                    ->icon('heroicon-o-plus')
                    ->form([
                        Forms\Components\Select::make('venue_type_ids')
                            ->label('Chọn Venue Types để gán vào danh mục này')
                            ->multiple()
                            ->searchable()
                            ->preload()
                            ->options(fn () => VenueType::whereNull('vn_category_id')
                                ->orderBy('category')->orderBy('venue_type')
                                ->get()
                                ->mapWithKeys(fn ($vt) => [
                                    $vt->id => "[{$vt->category}] {$vt->venue_type} ({$vt->string_value})",
                                ])
                                ->toArray()
                            ),
                    ])
                    ->action(function (array $data) {
                        if (! empty($data['venue_type_ids'])) {
                            VenueType::whereIn('id', $data['venue_type_ids'])
                                ->update(['vn_category_id' => $this->getOwnerRecord()->id]);

                            \Filament\Notifications\Notification::make()
                                ->title('Đã gán ' . count($data['venue_type_ids']) . ' venue types')
                                ->success()->send();
                        }
                    }),
            ])
            ->actions([
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\EditAction::make(),
                    Tables\Actions\Action::make('unmap')
                        ->label('Bỏ gán')
                        ->icon('heroicon-o-x-mark')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->action(fn (VenueType $record) => $record->update(['vn_category_id' => null])),
                ]),
            ])
            ->bulkActions([
                Tables\Actions\BulkAction::make('unmap_selected')
                    ->label('Bỏ gán selected')
                    ->icon('heroicon-o-x-mark')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->action(fn ($records) => $records->each->update(['vn_category_id' => null])),
            ]);
    }
}
