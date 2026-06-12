<?php

namespace App\Filament\Resources\Accounts\Schemas;

use App\Models\Account;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;

class AccountInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Cuenta')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('name')
                            ->label('Nombre'),
                        TextEntry::make('balance')
                            ->label('Balance')
                            ->state(fn (Account $record): int => $record->balanceInt)
                            ->money('USD', divideBy: 100)
                            ->weight(FontWeight::Bold)
                            ->color(fn ($state): string => $state > 0 ? 'success' : 'gray'),
                    ]),
            ]);
    }
}
