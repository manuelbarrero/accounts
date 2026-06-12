<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Icons\Heroicon;

enum TransactionType: string implements HasLabel, HasColor, HasIcon
{
    case Deposit = 'deposit';
    case Withdraw = 'withdraw';

    public function getLabel(): string
    {
        return match ($this) {
            self::Deposit => 'Ingreso',
            self::Withdraw => 'Egreso',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Deposit => 'success',
            self::Withdraw => 'danger',
        };
    }

    public function getIcon(): Heroicon
    {
        return match ($this) {
            self::Deposit => Heroicon::ArrowDownCircle,
            self::Withdraw => Heroicon::ArrowUpCircle,
        };
    }
}
