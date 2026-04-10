<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ContractResource\Pages;
use App\Models\Contract;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class ContractResource extends Resource
{
    protected static ?string $model = Contract::class;
    protected static ?string $navigationIcon = 'heroicon-o-document-text';
    protected static ?string $navigationLabel = 'Контракты';
    protected static ?string $modelLabel = 'Контракт';
    protected static ?string $pluralModelLabel = 'Контракты';
    protected static ?string $navigationGroup = 'Бизнес';
    protected static ?int $navigationSort = 3;

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')->sortable(),
                Tables\Columns\TextColumn::make('number')
                    ->label('Номер')
                    ->searchable(),
                Tables\Columns\TextColumn::make('clientName')
                    ->label('Клиент')
                    ->searchable(),
                Tables\Columns\TextColumn::make('consultantName')
                    ->label('Консультант')
                    ->searchable(),
                Tables\Columns\TextColumn::make('productName')
                    ->label('Продукт'),
                Tables\Columns\TextColumn::make('statusRelation.name')
                    ->label('Статус')
                    ->badge(),
                Tables\Columns\TextColumn::make('ammount')
                    ->label('Сумма')
                    ->numeric(2),
                Tables\Columns\TextColumn::make('currencyRelation.symbol')
                    ->label('Вал.'),
                Tables\Columns\TextColumn::make('openDate')
                    ->label('Открыт')
                    ->dateTime('d.m.Y')
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('Статус')
                    ->relationship('statusRelation', 'name'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('id', 'desc');
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Основное')
                    ->columns(2)
                    ->schema([
                        Forms\Components\TextInput::make('number')
                            ->label('Номер контракта'),
                        Forms\Components\TextInput::make('clientName')
                            ->label('Клиент'),
                        Forms\Components\TextInput::make('consultantName')
                            ->label('Консультант'),
                        Forms\Components\TextInput::make('productName')
                            ->label('Продукт'),
                        Forms\Components\TextInput::make('programName')
                            ->label('Программа'),
                        Forms\Components\TextInput::make('ammount')
                            ->label('Сумма')
                            ->numeric(),
                        Forms\Components\DatePicker::make('openDate')
                            ->label('Дата открытия'),
                        Forms\Components\DatePicker::make('closeDate')
                            ->label('Дата закрытия'),
                    ]),
                Forms\Components\Section::make('Прочее')
                    ->schema([
                        Forms\Components\Textarea::make('comment')
                            ->label('Комментарий'),
                    ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListContracts::route('/'),
            'create' => Pages\CreateContract::route('/create'),
            'edit' => Pages\EditContract::route('/{record}/edit'),
        ];
    }
}
