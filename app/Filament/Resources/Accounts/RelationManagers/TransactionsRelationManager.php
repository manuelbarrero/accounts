<?php

namespace App\Filament\Resources\Accounts\RelationManagers;

use App\Enums\TransactionType;
use Bavix\Wallet\Models\Transaction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class TransactionsRelationManager extends RelationManager
{
    protected static string $relationship = 'transactions';

    protected static ?string $title = 'Movimientos';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('amount')
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')
                    ->label('Fecha')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('type')
                    ->label('Tipo')
                    ->badge()
                    ->state(fn (Transaction $record): TransactionType => TransactionType::from(
                        $record->type instanceof \BackedEnum ? $record->type->value : $record->type,
                    )),
                TextColumn::make('amount')
                    ->label('Monto')
                    ->money('USD', divideBy: 100)
                    ->sortable()
                    ->color(fn ($state): string => $state < 0 ? 'danger' : 'success'),
                TextColumn::make('description')
                    ->label('Descripción')
                    ->state(fn (Transaction $record): ?string => $record->meta['description'] ?? null),
                TextColumn::make('amount_bs')
                    ->label('Monto Bs')
                    ->state(fn (Transaction $record): ?int => $record->meta['amount_bs'] ?? null)
                    ->money('VES', divideBy: 100)
                    ->placeholder('—'),
                TextColumn::make('exchange_rate')
                    ->label('Cotización')
                    ->state(fn (Transaction $record): ?string => $record->meta['exchange_rate'] ?? null)
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('type')
                    ->label('Tipo')
                    ->options(TransactionType::class),
            ])
            ->headerActions([
                // Los movimientos se crean con las actions Ingreso/Egreso: el ledger es inmutable.
            ])
            ->recordActions([])
            ->toolbarActions([]);
    }
}
