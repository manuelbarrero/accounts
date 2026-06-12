<?php

use App\Filament\Resources\Accounts\AccountResource;
use App\Filament\Resources\Accounts\Pages\CreateAccount;
use App\Filament\Resources\Accounts\Pages\EditAccount;
use App\Filament\Resources\Accounts\Pages\ListAccounts;
use App\Models\Account;

use function Pest\Livewire\livewire;

/*
|--------------------------------------------------------------------------
| Validation
|--------------------------------------------------------------------------
*/

it('validates the account form', function (array $data, array $errors) {
    livewire(CreateAccount::class)
        ->fillForm($data)
        ->call('create')
        ->assertHasFormErrors($errors);
})->with([
    'name is required' => [['name' => null], ['name' => 'required']],
    'name max 100' => [['name' => str_repeat('a', 101)], ['name' => 'max']],
]);

/*
|--------------------------------------------------------------------------
| Component config
|--------------------------------------------------------------------------
*/

it('searches accounts by name', function () {
    $cash = Account::factory()->create(['name' => 'Efectivo']);
    $bank = Account::factory()->create(['name' => 'Banco']);

    livewire(ListAccounts::class)
        ->searchTable('Efectivo')
        ->assertCanSeeTableRecords([$cash])
        ->assertCanNotSeeTableRecords([$bank]);
});

it('shows balance formatted as money', function () {
    $account = Account::factory()->create();
    $account->recordIncome(10000);

    livewire(ListAccounts::class)
        ->assertTableColumnFormattedStateSet('balance', '$100.00', $account->refresh());
});

it('shows the last movement as previous +/- amount = current, copyable', function () {
    $account = Account::factory()->create();
    $account->recordIncome(10000);
    $account->recordExpense(1046);

    livewire(ListAccounts::class)
        ->assertTableColumnStateSet('last_movement', '100 - 10,46 = 89,54', $account->refresh());
});

it('lists accounts ordered by position', function () {
    $second = Account::factory()->create(['position' => 2]);
    $first = Account::factory()->create(['position' => 1]);

    livewire(ListAccounts::class)
        ->assertCanSeeTableRecords([$first, $second], inOrder: true);
});

/*
|--------------------------------------------------------------------------
| CRUD
|--------------------------------------------------------------------------
*/

it('can render the list page', function () {
    $this->get(AccountResource::getUrl('index'))->assertSuccessful();
});

it('can render the create page', function () {
    $this->get(AccountResource::getUrl('create'))->assertSuccessful();
});

it('can create an account and assigns the next position', function () {
    Account::factory()->create(['position' => 5]);

    livewire(CreateAccount::class)
        ->fillForm(['name' => 'Efectivo'])
        ->call('create')
        ->assertHasNoFormErrors();

    $account = Account::query()->where('name', 'Efectivo')->first();

    expect($account)->not->toBeNull()
        ->and($account->position)->toBe(6)
        ->and($account->balanceInt)->toBe(0);
});

it('can render the edit page', function () {
    $account = Account::factory()->create();

    $this->get(AccountResource::getUrl('edit', ['record' => $account]))->assertSuccessful();
});

it('can update an account', function () {
    $account = Account::factory()->create();

    livewire(EditAccount::class, ['record' => $account->getRouteKey()])
        ->fillForm(['name' => 'Ahorros'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($account->refresh()->name)->toBe('Ahorros');
});

it('can render the view page', function () {
    $account = Account::factory()->create();

    $this->get(AccountResource::getUrl('view', ['record' => $account]))->assertSuccessful();
});

it('can delete an account with zero balance', function () {
    $account = Account::factory()->create();

    livewire(ListAccounts::class)
        ->assertTableActionVisible('delete', $account)
        ->callTableAction('delete', $account);

    expect(Account::query()->find($account->id))->toBeNull();
});

it('hides the delete action when the account has balance', function () {
    $account = Account::factory()->create();
    $account->recordIncome(1000);

    livewire(ListAccounts::class)
        ->assertTableActionHidden('delete', $account->refresh());
});

/*
|--------------------------------------------------------------------------
| Action: Ingreso
|--------------------------------------------------------------------------
*/

it('records an income and increases the balance', function () {
    $account = Account::factory()->create();

    livewire(ListAccounts::class)
        ->callTableAction('income', $account, data: [
            'amount' => 100,
            'description' => 'Pago inicial',
        ])
        ->assertHasNoTableActionErrors()
        ->assertNotified('Ingreso registrado');

    $account->refresh();
    $transaction = $account->transactions()->first();

    expect($account->balanceInt)->toBe(10000)
        ->and($transaction->type->value)->toBe('deposit')
        ->and((int) $transaction->amount)->toBe(10000)
        ->and($transaction->meta['description'])->toBe('Pago inicial');
});

/*
|--------------------------------------------------------------------------
| Action: Egreso
|--------------------------------------------------------------------------
*/

it('hides the expense action when the balance is zero', function () {
    $account = Account::factory()->create();

    livewire(ListAccounts::class)
        ->assertTableActionHidden('expense', $account);
});

it('records a USD expense and decreases the balance', function () {
    $account = Account::factory()->create();
    $account->recordIncome(10000);

    livewire(ListAccounts::class)
        ->assertTableActionVisible('expense', $account->refresh())
        ->callTableAction('expense', $account, data: [
            'in_bs' => false,
            'amount' => 30,
            'description' => 'Compra',
        ])
        ->assertHasNoTableActionErrors()
        ->assertNotified('Egreso registrado');

    $account->refresh();
    $transaction = $account->transactions()->where('type', 'withdraw')->first();

    expect($account->balanceInt)->toBe(7000)
        ->and((int) $transaction->amount)->toBe(-3000)
        ->and($transaction->meta['in_bs'])->toBeFalse()
        ->and($transaction->meta['description'])->toBe('Compra');
});

it('rejects an expense greater than the balance', function () {
    $account = Account::factory()->create();
    $account->recordIncome(10000);

    livewire(ListAccounts::class)
        ->callTableAction('expense', $account->refresh(), data: [
            'in_bs' => false,
            'amount' => 500,
        ])
        ->assertHasTableActionErrors(['amount' => 'max']);

    expect($account->refresh()->balanceInt)->toBe(10000);
});

it('records an expense in bolivars saving the rate and Bs amount', function () {
    $account = Account::factory()->create();
    $account->recordIncome(10000);

    livewire(ListAccounts::class)
        ->callTableAction('expense', $account->refresh(), data: [
            'in_bs' => true,
            'amount' => 10,
            'exchange_rate' => '36.50',
        ])
        ->assertHasNoTableActionErrors()
        ->assertNotified('Egreso registrado');

    $account->refresh();
    $transaction = $account->transactions()->where('type', 'withdraw')->first();

    expect($account->balanceInt)->toBe(9000)
        ->and((int) $transaction->amount)->toBe(-1000)
        ->and($transaction->meta['in_bs'])->toBeTrue()
        ->and($transaction->meta['amount_bs'])->toBe(36500)
        ->and((float) $transaction->meta['exchange_rate'])->toEqual(36.5);
});

it('suggests the last registered exchange rate in the expense form', function () {
    $account = Account::factory()->create();
    $account->recordIncome(100000);
    $account->recordExpense(1000, 'Gasto en Bs', '36.50', 36500);

    livewire(ListAccounts::class)
        ->mountTableAction('expense', $account->refresh())
        ->assertTableActionDataSet(['exchange_rate' => '36.50']);
});

/*
|--------------------------------------------------------------------------
| Cálculos
|--------------------------------------------------------------------------
*/

it('returns the most recent exchange rate', function () {
    $account = Account::factory()->create();
    $account->recordIncome(1000000);
    $account->recordExpense(1000, null, '36.50', 36500);
    $account->recordExpense(1000, null, '37.20', 37200);

    expect(Account::lastExchangeRate())->toBe('37.20');
});

it('returns null when no exchange rate was ever registered', function () {
    $account = Account::factory()->create();
    $account->recordIncome(1000);
    $account->recordExpense(500, 'Gasto USD');

    expect(Account::lastExchangeRate())->toBeNull();
});

it('converts 100 USD at rate 36.50 into 3650 Bs', function () {
    $account = Account::factory()->create();
    $account->recordIncome(20000);

    livewire(ListAccounts::class)
        ->callTableAction('expense', $account->refresh(), data: [
            'in_bs' => true,
            'amount' => 100,
            'exchange_rate' => '36.50',
        ])
        ->assertHasNoTableActionErrors();

    $transaction = $account->transactions()->where('type', 'withdraw')->first();

    expect((int) $transaction->amount)->toBe(-10000)
        ->and($transaction->meta['amount_bs'])->toBe(365000)
        ->and($account->refresh()->balanceInt)->toBe(10000);
});
