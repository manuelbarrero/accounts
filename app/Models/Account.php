<?php

namespace App\Models;

use Bavix\Wallet\Interfaces\Wallet;
use Bavix\Wallet\Models\Transaction;
use Bavix\Wallet\Traits\HasWallet;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Account extends Model implements Wallet
{
    /** @use HasFactory<\Database\Factories\AccountFactory> */
    use HasFactory;
    use HasWallet;

    protected $fillable = [
        'name',
        'position',
    ];

    protected static function booted(): void
    {
        static::creating(function (Account $account) {
            if (! $account->position) {
                $account->position = (int) static::max('position') + 1;
            }
        });
    }

    public function recordIncome(int $amountCents, ?string $description = null): Transaction
    {
        return $this->deposit($amountCents, [
            'description' => $description,
        ]);
    }

    public function recordExpense(
        int $amountCents,
        ?string $description = null,
        ?string $exchangeRate = null,
        ?int $amountBsCents = null,
    ): Transaction {
        $meta = [
            'description' => $description,
            'in_bs' => false,
        ];

        if ($exchangeRate !== null && $amountBsCents !== null) {
            $meta['in_bs'] = true;
            $meta['amount_bs'] = $amountBsCents;
            $meta['exchange_rate'] = $exchangeRate;
        }

        return $this->withdraw($amountCents, $meta);
    }

    public static function lastExchangeRate(): ?string
    {
        $meta = Transaction::query()
            ->whereNotNull('meta->exchange_rate')
            ->latest('id')
            ->value('meta');

        $rate = $meta['exchange_rate'] ?? null;

        return $rate === null ? null : (string) $rate;
    }
}
