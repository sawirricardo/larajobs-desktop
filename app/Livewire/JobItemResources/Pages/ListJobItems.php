<?php

namespace App\Livewire\JobItemResources\Pages;

use App\Models\Company;
use App\Models\JobItem;
use App\Services\LarajobsService;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Livewire\Component;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Native\Laravel\Facades\Clipboard;
use Native\Laravel\Notification;
use Spatie\MediaLibrary\MediaCollections\Exceptions\UnreachableUrl;

class ListJobItems extends Component implements HasForms, HasTable
{
    use InteractsWithForms;
    use InteractsWithTable;

    public function mount()
    {
        $this->getFeeds(new LarajobsService);
    }

    public function getFeeds(LarajobsService $larajobsService)
    {
        $items = $larajobsService->feedItems();
        foreach ($items as $item) {
            $meta = data_get($item->data, ['child', 'https://larajobs.com']);

            $company = Company::query()->firstOrCreate(['name' => $item->get_author()->get_name()]);

            $logo = data_get($meta, 'company_logo.0.data');

            if ($logo && ! $company->hasMedia('logo')) {
                try {
                    $company->addMediaFromUrl($logo)
                        ->toMediaCollection('logo');
                } catch (UnreachableUrl $e) {
                    //
                }
            }

            $jobItem = JobItem::query()->firstOrCreate([
                'link' => $item->get_id(),
            ], [
                'title' => $item->get_title(),
                'company_id' => $company->getKey(),
                'published_at' => Carbon::parse(data_get($item->data, ['child', '', 'pubDate', 0, 'data'])),
                'location' => data_get($meta, 'location.0.data'),
                'salary' => data_get($meta, 'salary.0.data'),
            ]);

            if ($jobItem->wasRecentlyCreated) {
                Notification::new()
                    ->title("New Larajob: {$jobItem->title}")
                    ->message($jobItem->notification_message)
                    ->show();
            }
        }
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(JobItem::query())
            ->deferLoading()
            ->columns([
                Tables\Columns\Layout\Stack::make([
                    Tables\Columns\TextColumn::make('title')
                        ->searchable()
                        ->url(fn ($record) => $record->link)
                        ->openUrlInNewTab(),
                    Tables\Columns\Layout\Split::make([
                        Tables\Columns\TextColumn::make('published_at')
                            ->label('Published Date')
                            ->tooltip(fn ($state) => $state)
                            ->formatStateUsing(fn ($state) => $state->diffForHumans(short: true))
                            ->icon('heroicon-o-clock')
                            ->grow(false)
                            ->sortable(),
                        Tables\Columns\TextColumn::make('salary')
                            ->icon('heroicon-o-banknotes')
                            ->grow(false),
                        Tables\Columns\TextColumn::make('location')
                            ->icon('heroicon-o-map-pin')
                            ->grow(false),
                    ]),
                    Tables\Columns\TextColumn::make('company.name')
                        ->icon('heroicon-o-building-office')
                        ->tooltip('filter by this company')
                        ->disabledClick()
                        ->searchable()
                        ->grow(false)
                        ->action(function ($livewire, $record) {
                            $livewire->tableFilters['company_id']['value'] = $record->company_id;
                        }),
                ]),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('applied_at')
                    ->label('applied?')
                    ->nullable()
                    ->default(false),
                Tables\Filters\SelectFilter::make('company_id')
                    ->label('Company')
                    ->searchable()
                    ->relationship('company', 'name'),
                Tables\Filters\TrashedFilter::make(),
            ])
            ->actions([
                Tables\Actions\ViewAction::make()
                    ->url(fn ($record) => $record->link)
                    ->openUrlInNewTab(),
                Tables\Actions\DeleteAction::make(),
                Tables\Actions\Action::make('mark_applied')
                    ->hidden(fn ($record) => $record->applied_at)
                    ->action(function ($record) {
                        if (empty($record->applied_at)) {
                            $record->touch('applied_at');
                            return;
                        }
                        $record->update(['applied_at' => null]);
                    })
            ])
            ->bulkActions([
                // Tables\Actions\BulkActionGroup::make([
                //     //
                // ]),
            ])
            ->poll()
            ->paginated(false)
            ->defaultSort('published_at', 'desc');
    }

    public function render(): View
    {
        return view('livewire.job-item-resources.pages.list-job-items');
    }
}
