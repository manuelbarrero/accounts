<?php

namespace App\Filament\Resources\Accounts\Actions;

use App\Models\Account;
use Bavix\Wallet\Internal\Exceptions\ExceptionInterface;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Icons\Heroicon;

class AccountActions
{
    public static function income(): Action
    {
        return Action::make('income')
            ->label('Ingreso')
            ->icon(Heroicon::ArrowDownCircle)
            ->color('success')
            ->modalHeading('Registrar ingreso')
            ->schema([
                TextInput::make('amount')
                    ->label('Monto')
                    ->numeric()
                    ->prefix('$')
                    ->minValue(0.01)
                    ->required(),
                TextInput::make('description')
                    ->label('Descripción')
                    ->maxLength(255),
            ])
            ->action(function (Account $record, array $data): void {
                $record->recordIncome(
                    (int) round(((float) $data['amount']) * 100),
                    $data['description'] ?? null,
                );

                Notification::make()
                    ->title('Ingreso registrado')
                    ->success()
                    ->send();
            });
    }

    public static function expense(): Action
    {
        return Action::make('expense')
            ->label('Egreso')
            ->icon(Heroicon::ArrowUpCircle)
            ->color('danger')
            ->visible(fn (Account $record): bool => $record->balanceInt > 0)
            ->modalHeading('Registrar egreso')
            ->schema([
                Toggle::make('in_bs')
                    ->label('Pago en Bolívares')
                    ->default(false)
                    ->live(),
                TextInput::make('amount')
                    ->label('Monto')
                    ->numeric()
                    ->prefix('$')
                    ->minValue(0.01)
                    ->required()
                    ->maxValue(fn (Account $record): float => $record->balanceInt / 100)
                    ->live(onBlur: true)
                    ->afterStateUpdated(fn (Set $set, Get $get) => self::recalculateBs($set, $get)),
                TextInput::make('exchange_rate')
                    ->label('Cotización')
                    ->numeric()
                    ->suffix('Bs/$')
                    ->minValue(0.0001)
                    ->default(fn (): ?string => Account::lastExchangeRate())
                    ->visible(fn (Get $get): bool => (bool) $get('in_bs'))
                    ->required(fn (Get $get): bool => (bool) $get('in_bs'))
                    ->live(onBlur: true)
                    ->afterStateUpdated(fn (Set $set, Get $get) => self::recalculateBs($set, $get)),
                TextInput::make('amount_bs')
                    ->label('Monto Bs')
                    ->numeric()
                    ->prefix('Bs')
                    ->visible(fn (Get $get): bool => (bool) $get('in_bs'))
                    ->disabled()
                    ->dehydrated(),
                TextInput::make('description')
                    ->label('Descripción')
                    ->maxLength(255),
            ])
            ->action(function (Account $record, array $data): void {
                $amountCents = (int) round(((float) $data['amount']) * 100);

                try {
                    if ($data['in_bs'] ?? false) {
                        $exchangeRate = (string) $data['exchange_rate'];
                        $amountBsCents = (int) round($amountCents * (float) $exchangeRate);

                        $record->recordExpense(
                            $amountCents,
                            $data['description'] ?? null,
                            $exchangeRate,
                            $amountBsCents,
                        );
                    } else {
                        $record->recordExpense(
                            $amountCents,
                            $data['description'] ?? null,
                        );
                    }
                } catch (ExceptionInterface) {
                    Notification::make()
                        ->title('Fondos insuficientes')
                        ->danger()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title('Egreso registrado')
                    ->success()
                    ->send();
            });
    }

    protected static function recalculateBs(Set $set, Get $get): void
    {
        $usd = (float) $get('amount');
        $rate = (float) $get('exchange_rate');

        if ($usd > 0 && $rate > 0) {
            $set('amount_bs', round($usd * $rate, 2));
        }
    }
}
