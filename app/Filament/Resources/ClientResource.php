<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ClientResource\Pages;
use App\Models\Client;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class ClientResource extends Resource
{
    protected static ?string $model = Client::class;
    protected static ?string $navigationIcon = 'heroicon-o-users';
    protected static ?string $navigationLabel = 'Клиенты';
    protected static ?string $modelLabel = 'Клиент';
    protected static ?string $pluralModelLabel = 'Клиенты';
    protected static ?string $navigationGroup = 'Партнёры';
    protected static ?int $navigationSort = 2;

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')->sortable(),
                Tables\Columns\TextColumn::make('personName')
                    ->label('ФИО')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('consultantRelation.personName')
                    ->label('Консультант')
                    ->searchable(),
                Tables\Columns\IconColumn::make('active')
                    ->label('Активен')
                    ->boolean(),
                Tables\Columns\TextColumn::make('source')
                    ->label('Источник'),
                Tables\Columns\TextColumn::make('dateCreated')
                    ->label('Создан')
                    ->dateTime('d.m.Y')
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('active')
                    ->label('Активен'),
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
                        Forms\Components\TextInput::make('personName')
                            ->label('ФИО'),
                        Forms\Components\TextInput::make('source')
                            ->label('Источник'),
                        Forms\Components\Toggle::make('active')
                            ->label('Активен'),
                        Forms\Components\Toggle::make('leadDs')
                            ->label('Лид DS'),
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
            'index' => Pages\ListClients::route('/'),
            'create' => Pages\CreateClient::route('/create'),
            'edit' => Pages\EditClient::route('/{record}/edit'),
        ];
    }
}
