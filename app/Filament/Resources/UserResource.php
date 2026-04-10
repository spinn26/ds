<?php

namespace App\Filament\Resources;

use App\Filament\Resources\UserResource\Pages;
use App\Models\User;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Hash;

class UserResource extends Resource
{
    protected static ?string $model = User::class;
    protected static ?string $navigationIcon = 'heroicon-o-shield-check';
    protected static ?string $navigationLabel = 'Пользователи';
    protected static ?string $modelLabel = 'Пользователь';
    protected static ?string $pluralModelLabel = 'Пользователи';
    protected static ?string $navigationGroup = 'Система';
    protected static ?int $navigationSort = 10;

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')->sortable(),
                Tables\Columns\TextColumn::make('email')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('lastName')
                    ->label('Фамилия')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('firstName')
                    ->label('Имя')
                    ->searchable(),
                Tables\Columns\TextColumn::make('patronymic')
                    ->label('Отчество'),
                Tables\Columns\TextColumn::make('phone')
                    ->label('Телефон'),
                Tables\Columns\TextColumn::make('role')
                    ->label('Роли')
                    ->badge()
                    ->separator(','),
                Tables\Columns\IconColumn::make('isBlocked')
                    ->label('Заблокирован')
                    ->boolean()
                    ->trueColor('danger')
                    ->falseColor('success'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('role')
                    ->label('Роль')
                    ->options([
                        'admin' => 'Admin',
                        'backoffice' => 'Backoffice',
                        'consultant' => 'Consultant',
                        'client' => 'Client',
                    ])
                    ->query(fn ($query, array $data) => $data['value']
                        ? $query->where('role', 'like', "%{$data['value']}%")
                        : $query
                    ),
                Tables\Filters\TernaryFilter::make('isBlocked')
                    ->label('Заблокирован'),
            ])
            ->actions([
                Tables\Actions\Action::make('impersonate')
                    ->label('В админку')
                    ->icon('heroicon-o-arrow-right-on-rectangle')
                    ->color('warning')
                    ->url(fn ($record) => route('impersonate', $record))
                    ->visible(fn ($record) => $record->id !== auth()->id()),
                Tables\Actions\Action::make('impersonate_spa')
                    ->label('На сайт')
                    ->icon('heroicon-o-globe-alt')
                    ->color('success')
                    ->url(fn ($record) => route('impersonate.spa', $record))
                    ->visible(fn ($record) => $record->id !== auth()->id()),
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
                        Forms\Components\TextInput::make('email')
                            ->email()
                            ->required(),
                        Forms\Components\TextInput::make('phone')
                            ->label('Телефон'),
                        Forms\Components\TextInput::make('lastName')
                            ->label('Фамилия'),
                        Forms\Components\TextInput::make('firstName')
                            ->label('Имя'),
                        Forms\Components\TextInput::make('patronymic')
                            ->label('Отчество'),
                        Forms\Components\Select::make('gender')
                            ->label('Пол')
                            ->options([
                                'Мужской' => 'Мужской',
                                'female' => 'Женский',
                            ]),
                        Forms\Components\DatePicker::make('birthDate')
                            ->label('Дата рождения'),
                    ]),
                Forms\Components\Section::make('Доступ')
                    ->columns(2)
                    ->schema([
                        Forms\Components\TextInput::make('role')
                            ->label('Роли (через запятую)')
                            ->helperText('admin, backoffice, consultant, client'),
                        Forms\Components\TextInput::make('password')
                            ->label('Новый пароль')
                            ->password()
                            ->dehydrateStateUsing(fn ($state) => $state ? Hash::make($state) : null)
                            ->dehydrated(fn ($state) => filled($state)),
                        Forms\Components\Toggle::make('isBlocked')
                            ->label('Заблокирован'),
                        Forms\Components\Toggle::make('agreement')
                            ->label('Согласие'),
                    ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListUsers::route('/'),
            'create' => Pages\CreateUser::route('/create'),
            'edit' => Pages\EditUser::route('/{record}/edit'),
        ];
    }
}
