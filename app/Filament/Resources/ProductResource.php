<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ProductResource\Pages;
use App\Models\Product;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class ProductResource extends Resource
{
    protected static ?string $model = Product::class;
    protected static ?string $navigationIcon = 'heroicon-o-cube';
    protected static ?string $navigationLabel = 'Продукты';
    protected static ?string $modelLabel = 'Продукт';
    protected static ?string $pluralModelLabel = 'Продукты';
    protected static ?string $navigationGroup = 'Справочники';
    protected static ?int $navigationSort = 5;

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')->sortable(),
                Tables\Columns\TextColumn::make('name')
                    ->label('Название')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('typeName')
                    ->label('Тип'),
                Tables\Columns\IconColumn::make('active')
                    ->label('Активен')
                    ->boolean(),
                Tables\Columns\IconColumn::make('noComission')
                    ->label('Без комиссии')
                    ->boolean(),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->defaultSort('id');
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('name')
                    ->label('Название')
                    ->required(),
                Forms\Components\TextInput::make('typeName')
                    ->label('Тип'),
                Forms\Components\TextInput::make('formLink')
                    ->label('Ссылка на форму')
                    ->url(),
                Forms\Components\Toggle::make('active')
                    ->label('Активен'),
                Forms\Components\Toggle::make('noComission')
                    ->label('Без комиссии'),
                Forms\Components\Toggle::make('visibleToResident')
                    ->label('Видимость для резидентов'),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListProducts::route('/'),
            'create' => Pages\CreateProduct::route('/create'),
            'edit' => Pages\EditProduct::route('/{record}/edit'),
        ];
    }
}
