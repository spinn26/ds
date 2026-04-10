<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ConsultantResource\Pages;
use App\Models\Consultant;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class ConsultantResource extends Resource
{
    protected static ?string $model = Consultant::class;
    protected static ?string $navigationIcon = 'heroicon-o-user-group';
    protected static ?string $navigationLabel = 'Консультанты';
    protected static ?string $modelLabel = 'Консультант';
    protected static ?string $pluralModelLabel = 'Консультанты';
    protected static ?int $navigationSort = 1;

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')->sortable(),
                Tables\Columns\TextColumn::make('personName')
                    ->label('ФИО')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('statusRelation.title')
                    ->label('Статус'),
                Tables\Columns\IconColumn::make('active')
                    ->label('Активен')
                    ->boolean(),
                Tables\Columns\TextColumn::make('countryRelation.countryNameRu')
                    ->label('Страна'),
                Tables\Columns\TextColumn::make('personalVolume')
                    ->label('Личный объём')
                    ->numeric(2),
                Tables\Columns\TextColumn::make('groupVolume')
                    ->label('Групповой объём')
                    ->numeric(2),
                Tables\Columns\TextColumn::make('dateCreated')
                    ->label('Дата создания')
                    ->dateTime('d.m.Y')
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('active')
                    ->label('Активен'),
            ])
            ->defaultSort('id', 'desc');
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('personName')
                    ->label('ФИО')
                    ->disabled(),
                Forms\Components\Toggle::make('active')
                    ->label('Активен'),
                Forms\Components\Textarea::make('comment')
                    ->label('Комментарий'),
            ]);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListConsultants::route('/'),
            'edit' => Pages\EditConsultant::route('/{record}/edit'),
        ];
    }
}
