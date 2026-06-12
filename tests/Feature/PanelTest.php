<?php

use App\Filament\Resources\Accounts\AccountResource;

it('redirects the panel home to the accounts list', function () {
    $this->get('/admin')
        ->assertRedirect(AccountResource::getUrl('index'));
});
