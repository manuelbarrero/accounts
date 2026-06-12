<?php

use App\Filament\Resources\Accounts\Pages\ViewAccount;
use App\Filament\Resources\Accounts\RelationManagers\TransactionsRelationManager;
use App\Models\Account;

use function Pest\Livewire\livewire;

it('lists the account movements', function () {
    $account = Account::factory()->create();
    $account->recordIncome(10000, 'Pago inicial');
    $account->recordExpense(3000, 'Compra');

    livewire(TransactionsRelationManager::class, [
        'ownerRecord' => $account->refresh(),
        'pageClass' => ViewAccount::class,
    ])
        ->assertSuccessful()
        ->assertCanSeeTableRecords($account->transactions);
});

it('filters movements by type', function () {
    $account = Account::factory()->create();
    $account->recordIncome(10000);
    $account->recordExpense(3000);
    $account->refresh();

    $deposits = $account->transactions()->where('type', 'deposit')->get();
    $withdraws = $account->transactions()->where('type', 'withdraw')->get();

    livewire(TransactionsRelationManager::class, [
        'ownerRecord' => $account,
        'pageClass' => ViewAccount::class,
    ])
        ->filterTable('type', 'withdraw')
        ->assertCanSeeTableRecords($withdraws)
        ->assertCanNotSeeTableRecords($deposits);
});

it('does not allow creating, editing or deleting movements', function () {
    $account = Account::factory()->create();
    $account->recordIncome(10000);

    livewire(TransactionsRelationManager::class, [
        'ownerRecord' => $account->refresh(),
        'pageClass' => ViewAccount::class,
    ])
        ->assertTableActionDoesNotExist('create')
        ->assertTableActionDoesNotExist('edit')
        ->assertTableActionDoesNotExist('delete');
});
