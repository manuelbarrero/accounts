<?php

namespace App\Filament\Resources\Accounts\Tables;

use App\Filament\Resources\Accounts\Actions\AccountActions;
use App\Models\Account;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class AccountsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('position')
            ->reorderable('position')
            ->paginated(false)
            ->columns([
                TextColumn::make('name')
                    ->label('Nombre')
                    ->searchable(),
                TextColumn::make('balance')
                    ->label('Balance')
                    ->state(fn (Account $record): int => $record->balanceInt)
                    ->money('USD', divideBy: 100)
                    ->color(fn ($state): string => $state > 0 ? 'success' : 'gray'),
                TextColumn::make('last_movement')
                    ->label('Último movimiento')
                    ->state(fn (Account $record): ?string => self::lastMovementSummary($record))
                    ->placeholder('—')
                    ->copyable(),
            ])
            ->recordActions([
                AccountActions::income(),
                AccountActions::expense(),
                ActionGroup::make([
                    EditAction::make(),
                    DeleteAction::make()
                        ->visible(fn (Account $record): bool => $record->balanceInt === 0)
                        ->modalDescription('¿Eliminar esta cuenta? Se perderá su historial de movimientos.')
                        ->successNotificationTitle('Cuenta eliminada'),
                ]),
            ]);
    }

    protected static function lastMovementSummary(Account $record): ?string
    {
        $transaction = $record->transactions()
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();

        if ($transaction === null) {
            return null;
        }

        $currentCents = $record->balanceInt;
        $amountCents = (int) $transaction->amount;
        $previousCents = $currentCents - $amountCents;
        $sign = $amountCents < 0 ? '-' : '+';

        return self::formatCents($previousCents)
            .' '.$sign.' '.self::formatCents(abs($amountCents))
            .' = '.self::formatCents($currentCents);
    }

    protected static function formatCents(int $cents): string
    {
        $decimals = $cents % 100 === 0 ? 0 : 2;

        return number_format($cents / 100, $decimals, ',', '.');
    }
}
