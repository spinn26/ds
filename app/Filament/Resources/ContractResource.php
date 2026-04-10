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
                Tables\Columns\TextColumn::make('programName')
                    ->label('Программа'),
                Tables\Columns\TextColumn::make('statusRelation.name')
                    ->label('Статус'),
                Tables\Columns\TextColumn::make('ammount')
                    ->label('Сумма')
                    ->numeric(2),
                Tables\Columns\TextColumn::make('currencyRelation.symbol')
                    ->label('Валюта'),
                Tables\Columns\TextColumn::make('openDate')
                    ->label('Дата открытия')
                    ->dateTime('d.m.Y')
                    ->sortable(),
            ])
            ->defaultSort('id', 'desc');
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('number')
                    ->label('Номер'),
                Forms\Components\TextInput::make('clientName')
                    ->label('Клиент')
                    ->disabled(),
                Forms\Components\TextInput::make('consultantName')
                    ->label('Консультант')
                    ->disabled(),
                Forms\Components\Textarea::make('comment')
                    ->label('Комментарий'),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListContracts::route('/'),
            'edit' => Pages\EditContract::route('/{record}/edit'),
        ];
    }
}
