<?php

namespace App\Filament\Resources;

use App\Filament\Resources\TransactionResource\Pages;
use App\Models\Transaction;
use Filament\Forms;
use Filament\Forms\Form;
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
    protected static ?string $navigationGroup = 'Бизнес';
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
                    ->label('Сумма ₽')
                    ->numeric(2),
                Tables\Columns\TextColumn::make('currencyRelation.symbol')
                    ->label('Вал.'),
                Tables\Columns\TextColumn::make('dsCommissionPercentage')
                    ->label('DS %')
                    ->numeric(2),
                Tables\Columns\TextColumn::make('comment')
                    ->label('Комментарий')
                    ->limit(30),
                Tables\Columns\TextColumn::make('date')
                    ->label('Дата')
                    ->dateTime('d.m.Y')
                    ->sortable(),
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
                        Forms\Components\TextInput::make('amount')
                            ->label('Сумма')
                            ->numeric(),
                        Forms\Components\TextInput::make('amountRUB')
                            ->label('Сумма ₽')
                            ->numeric(),
                        Forms\Components\TextInput::make('amountUSD')
                            ->label('Сумма $')
                            ->numeric(),
                        Forms\Components\TextInput::make('dsCommissionPercentage')
                            ->label('Комиссия DS %')
                            ->numeric(),
                        Forms\Components\DatePicker::make('date')
                            ->label('Дата'),
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
            'index' => Pages\ListTransactions::route('/'),
            'create' => Pages\CreateTransaction::route('/create'),
            'edit' => Pages\EditTransaction::route('/{record}/edit'),
        ];
    }
}
