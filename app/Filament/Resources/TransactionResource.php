<?php

namespace App\Filament\Resources;

use App\Filament\Resources\TransactionResource\Pages;
use App\Models\Transaction;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class TransactionResource extends Resource
{
    protected static ?string $model = Transaction::class;
    protected static ?string $navigationIcon = 'heroicon-o-banknotes';
    protected static ?string $navigationLabel = 'Транзакции';
    protected static ?string $modelLabel = 'Транзакция';
    protected static ?string $pluralModelLabel = 'Транзакции';
    protected static ?int $navigationSort = 4;

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')->sortable(),
                Tables\Columns\TextColumn::make('contractRelation.number')
                    ->label('Контракт')
                    ->searchable(),
                Tables\Columns\TextColumn::make('amount')
                    ->label('Сумма')
                    ->numeric(2),
                Tables\Columns\TextColumn::make('amountRUB')
                    ->label('Сумма (₽)')
                    ->numeric(2),
                Tables\Columns\TextColumn::make('currencyRelation.symbol')
                    ->label('Валюта'),
                Tables\Columns\TextColumn::make('dsCommissionPercentage')
                    ->label('Комиссия DS %')
                    ->numeric(2),
                Tables\Columns\TextColumn::make('date')
                    ->label('Дата')
                    ->dateTime('d.m.Y')
                    ->sortable(),
            ])
            ->defaultSort('id', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTransactions::route('/'),
        ];
    }
}
