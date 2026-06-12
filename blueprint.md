# Blueprint — Accounts (Ingresos / Egresos con Laravel Wallet)

## Overview

**Key decisions:**
- Laravel 12 + Filament (panel `admin`) + `bavix/laravel-wallet` para balances y movimientos
- Base de datos MySQL; nombres de campos en inglés siguiendo convenciones Laravel
- Sin dashboard: el panel registra `->pages([])` en el provider, por lo que el login redirige directo al listado de `AccountResource` (primer ítem de navegación)
- Cada `Account` ES un wallet: implementa `Bavix\Wallet\Interfaces\Wallet` y usa el trait `HasWallet`; el balance lo mantiene el paquete
- Moneda base: USD, almacenada en **centavos (integer)** — lo que ya hace laravel-wallet
- Los movimientos son los records de la tabla `transactions` del paquete (ledger inmutable: no se editan ni se borran); los datos extra (descripción, monto Bs, cotización) van en la columna JSON `meta`
- Egresos opcionalmente en Bolívares (VES): el usuario ingresa monto en Bs + cotización (Bs/$), el sistema convierte a USD y guarda en `meta` la cotización y el monto en Bs (centavos)
- La cotización sugerida es la última registrada en cualquier transacción (`meta->exchange_rate` más reciente)
- Un egreso no puede exceder el balance de la cuenta (wallet lanza `InsufficientFunds`; se valida en el form con `maxValue`)
- Las cuentas se reordenan por drag & drop en la tabla (columna `position`)
- Una cuenta solo puede eliminarse cuando su balance es 0

---

## User Flows

### Flow 1: Entrar a la aplicación

1. Usuario inicia sesión en el panel
2. Sistema lo redirige directamente al listado de cuentas (no hay dashboard)
3. Usuario ve cada cuenta con su nombre y balance actual

### Flow 2: Crear una cuenta

1. Usuario hace click en "New account" desde el listado
2. Usuario ingresa el nombre de la cuenta
3. On submit: se crea la cuenta con balance 0 y `position` al final de la lista
4. Notificación de éxito mostrada

### Flow 3: Registrar un ingreso

1. Usuario hace click en el botón "Ingreso" en la fila de una cuenta (o en el header de la página de detalle)
2. Sistema muestra un modal con monto (USD) y descripción
3. Usuario completa y confirma
4. On submit: se crea un movimiento de depósito y el balance de la cuenta aumenta
5. Notificación "Ingreso registrado" mostrada

### Flow 4: Registrar un egreso en USD

1. Usuario hace click en el botón "Egreso" en la fila de una cuenta (o en el header de la página de detalle)
2. Sistema muestra un modal con monto (USD), descripción y un toggle "Monto en Bolívares" apagado
3. Usuario ingresa un monto menor o igual al balance y confirma
4. On submit: se crea un movimiento de retiro y el balance de la cuenta disminuye
5. Notificación "Egreso registrado" mostrada

### Flow 5: Registrar un egreso en Bolívares

1. Usuario hace click en el botón "Egreso" en la fila de una cuenta
2. Usuario activa el toggle "Pago en Bolívares"
3. Sistema muestra la cotización (Bs/$) precargada con la última registrada y el campo monto Bs (solo lectura)
4. Usuario ingresa el monto en USD (y ajusta la cotización si cambió)
5. Sistema calcula y muestra en vivo el equivalente en Bs
6. On submit: se crea un movimiento de retiro por el monto USD, guardando en el movimiento la cotización y el monto en Bs calculado
7. Notificación "Egreso registrado" mostrada

### Flow 6: Ver los movimientos de una cuenta

1. Usuario hace click en una cuenta del listado
2. Sistema muestra la página de detalle con nombre y balance
3. Debajo, usuario ve la tabla de movimientos: fecha, tipo (ingreso/egreso), monto USD, descripción, y — si aplica — monto Bs y cotización
4. Usuario puede filtrar por tipo de movimiento

### Flow 7: Reordenar cuentas

1. Usuario hace click en el botón de reordenar en el listado de cuentas
2. Usuario arrastra las filas a la posición deseada
3. On drop: el nuevo orden se persiste y se respeta en visitas futuras

---

## Commands

```bash
# 1. Crear proyecto e instalar dependencias
composer create-project laravel/laravel . --no-interaction
composer require filament/filament bavix/laravel-wallet --no-interaction

# 2. Instalar panel Filament
php artisan filament:install --panels --no-interaction

# 3. Publicar migrations de laravel-wallet
php artisan vendor:publish --tag=laravel-wallet-migrations --no-interaction

# 4. Crear enum
php artisan make:enum Enums/TransactionType --string --no-interaction

# 5. Crear modelo con migration y factory
php artisan make:model Account -mf --no-interaction

# 6. Crear resource (con página de View)
php artisan make:filament-resource Account --view --no-interaction

# 7. Crear relation manager
php artisan make:filament-relation-manager AccountResource transactions amount --no-interaction

# 8. Migrar y crear usuario
php artisan migrate --no-interaction
php artisan make:filament-user --no-interaction
```

**Configuración del panel** (`App\Providers\Filament\AdminPanelProvider`):
- `->pages([])` — elimina el Dashboard; Filament redirige al primer recurso de la navegación (AccountResource)

**Configuración de base de datos** (`.env`): conexión `mysql`, database `accounts`.

---

## Models

### Enums

```
Enum: TransactionType
  Location: App\Enums\TransactionType
  Backed: string (valores coinciden con la columna `type` de laravel-wallet)
  Implements: HasLabel, HasColor, HasIcon
  Imports:
    - Filament\Support\Contracts\HasLabel
    - Filament\Support\Contracts\HasColor
    - Filament\Support\Contracts\HasIcon
    - Filament\Support\Icons\Heroicon
  Cases:
    - Deposit = 'deposit': label "Ingreso", color "success", icon Heroicon::ArrowDownCircle
    - Withdraw = 'withdraw': label "Egreso", color "danger", icon Heroicon::ArrowUpCircle
```

### Modelos

```
Model: Account
  Table: accounts
  Attributes:
    - id: bigint, primary
    - name: string(100), not null
    - position: unsignedInteger, default 0   ← orden manual en el listado
    - created_at / updated_at: timestamps
  Implements:
    - Bavix\Wallet\Interfaces\Wallet
  Traits:
    - Bavix\Wallet\Traits\HasWallet
  Relationships:
    - morphMany: Bavix\Wallet\Models\Transaction via payable (provista por HasWallet como transactions())
  Accessors:
    - balance / balanceInt: provistos por HasWallet (cents)
  Boot:
    - creating: position = max(position) + 1 (la cuenta nueva queda al final)
  Methods:
    - recordIncome(int $amountCents, ?string $description): Transaction
      — deposit($amountCents, ['description' => $description])
    - recordExpense(int $amountCents, ?string $description, ?string $exchangeRate = null, ?int $amountBsCents = null): Transaction
      — withdraw($amountCents, meta). Si $exchangeRate y $amountBsCents vienen:
        meta = ['description', 'in_bs' => true, 'amount_bs' => $amountBsCents, 'exchange_rate' => $exchangeRate]
        Si no: meta = ['description', 'in_bs' => false]
    - static lastExchangeRate(): ?string
      — última cotización registrada: Transaction más reciente con meta->exchange_rate no nulo
```

**Tablas del paquete laravel-wallet** (no se crean modelos propios; se usan los del paquete):

```
Table: wallets       ← balance por cuenta, mantenida por el paquete
Table: transactions  ← movimientos (ledger inmutable)
  Columnas relevantes:
    - payable_type / payable_id: morph a Account
    - type: string ('deposit' | 'withdraw') — mapea a TransactionType
    - amount: bigint (cents; negativo en withdraws)
    - confirmed: boolean
    - meta: json
    - created_at: timestamp
  Claves documentadas de meta:
    - description: string|null
    - in_bs: bool
    - amount_bs: integer (céntimos de Bs) — solo si in_bs
    - exchange_rate: string decimal, 4 decimales (Bs por USD) — solo si in_bs
Table: transfers     ← sin uso en esta fase
```

**Fórmula de conversión (egreso en Bs):**
`amount_bs_cents = (int) round(amount_usd_cents * exchange_rate)`

---

## Resources

```
Resource: AccountResource
  Location: App\Filament\Resources\Accounts\AccountResource
  Docs: https://filamentphp.com/docs/5.x/panels/resources/overview
  RecordTitleAttribute: name
  GloballySearchableAttributes: [name]

  Navigation:
    Group: (ninguno)
    Icon: Heroicon::Wallet
    Sort: 1
    Label: Cuentas
```

### Form

```
  Form:
    Columns: 1

    Section: Cuenta
      Fields:
        Field: name
          Component: Filament\Forms\Components\TextInput
          Docs: https://filamentphp.com/docs/5.x/forms/text-input
          Validation: required, max:100
          Config: ->required()->maxLength(100)
```

*(`position` no aparece en el form: se asigna automáticamente al crear y se modifica solo por reorder.)*

### Table

```
  Table:
    DefaultSort: position asc
    Reorderable: ->reorderable('position')

    Column: name
      Component: Filament\Tables\Columns\TextColumn
      Docs: https://filamentphp.com/docs/5.x/tables/columns/text
      Config: ->searchable()

    Column: balance
      Component: Filament\Tables\Columns\TextColumn
      Docs: https://filamentphp.com/docs/5.x/tables/columns/text
      Config: ->state(fn (Account $record) => $record->balanceInt)
              ->money('usd', divideBy: 100)
              ->color(fn ($state) => $state > 0 ? 'success' : 'gray')
              ->label('Balance')

    Column: transactions_count
      Component: Filament\Tables\Columns\TextColumn
      Docs: https://filamentphp.com/docs/5.x/tables/columns/text
      Config: ->counts('transactions')->label('Movimientos')
              ->toggleable(isToggledHiddenByDefault: true)

    Column: created_at
      Component: Filament\Tables\Columns\TextColumn
      Docs: https://filamentphp.com/docs/5.x/tables/columns/text
      Config: ->dateTime()->toggleable(isToggledHiddenByDefault: true)
```

*(Sin filtros: el listado es corto y se navega por posición.)*

### Infolist (página View)

```
  Infolist:
    Columns: 2

    Section: Cuenta
      Columns: 2
      Entries:
        Entry: name
          Component: Filament\Infolists\Components\TextEntry
          Docs: https://filamentphp.com/docs/5.x/infolists/text-entry
          Config: ->label('Nombre')

        Entry: balance
          Component: Filament\Infolists\Components\TextEntry
          Docs: https://filamentphp.com/docs/5.x/infolists/text-entry
          Config: ->state(fn (Account $record) => $record->balanceInt)
                  ->money('usd', divideBy: 100)
                  ->weight('bold')
                  ->color(fn ($state) => $state > 0 ? 'success' : 'gray')
```

### Actions

```
  Actions:
    Action: Ingreso
      Component: Filament\Actions\Action
      Docs: https://filamentphp.com/docs/5.x/actions/overview
      Location: table row + view page header
      Icon: Heroicon::ArrowDownCircle
      Color: success
      Visibility: siempre visible
      Behavior:
        - Convertir el monto USD ingresado a centavos (× 100)
        - $record->recordIncome($amountCents, $description)
        - El balance de la cuenta aumenta
      Notification: "Ingreso registrado"

      Modal:
        Heading: Registrar ingreso
        Field: amount
          Component: Filament\Forms\Components\TextInput
          Docs: https://filamentphp.com/docs/5.x/forms/text-input
          Validation: required, numeric, min:0.01
          Config: ->numeric()->prefix('$')->minValue(0.01)->required()
        Field: description
          Component: Filament\Forms\Components\TextInput
          Docs: https://filamentphp.com/docs/5.x/forms/text-input
          Validation: nullable, max:255
          Config: ->maxLength(255)

    Action: Egreso
      Component: Filament\Actions\Action
      Docs: https://filamentphp.com/docs/5.x/actions/overview
      Location: table row + view page header
      Icon: Heroicon::ArrowUpCircle
      Color: danger
      Visibility: solo cuando balance > 0
      Behavior:
        - Si in_bs: amount_bs a céntimos (× 100), amount_usd_cents = round(amount_bs_cents / exchange_rate)
        - Si no: amount_usd_cents = amount × 100
        - $record->recordExpense($amountCents, $description, $exchangeRate, $amountBsCents)
        - El balance de la cuenta disminuye
      Notification: "Egreso registrado"

      Modal:
        Heading: Registrar egreso
        Imports:
          - Filament\Schemas\Components\Utilities\Get
          - Filament\Schemas\Components\Utilities\Set

        Field: in_bs
          Component: Filament\Forms\Components\Toggle
          Docs: https://filamentphp.com/docs/5.x/forms/toggle
          Validation: boolean
          Config: ->label('Pago en Bolívares')->default(false)->live()

        Field: amount
          Component: Filament\Forms\Components\TextInput
          Docs: https://filamentphp.com/docs/5.x/forms/text-input
          Validation: required, numeric, min:0.01, max:balance de la cuenta en USD
          Config: ->numeric()->prefix('$')->minValue(0.01)->required()
                  ->maxValue(fn (Account $record) => $record->balanceInt / 100)
                  ->live()->afterStateUpdated(recalcular amount_bs)

        Field: exchange_rate
          Component: Filament\Forms\Components\TextInput
          Docs: https://filamentphp.com/docs/5.x/forms/text-input
          Validation: required_if:in_bs,true, numeric, min:0.0001
          Config: ->numeric()->suffix('Bs/$')
                  ->default(fn () => Account::lastExchangeRate())   ← sugiere la última cotización
                  ->visible(fn (Get $get) => $get('in_bs'))
                  ->required(fn (Get $get) => $get('in_bs'))
                  ->live()->afterStateUpdated(recalcular amount_bs)

        Field: amount_bs
          Component: Filament\Forms\Components\TextInput
          Docs: https://filamentphp.com/docs/5.x/forms/text-input
          Validation: (calculado, solo lectura)
          Config: ->numeric()->prefix('Bs')
                  ->visible(fn (Get $get) => $get('in_bs'))
                  ->disabled()->dehydrated()
                  ← calculado en vivo como amount * exchange_rate

        Field: description
          Component: Filament\Forms\Components\TextInput
          Docs: https://filamentphp.com/docs/5.x/forms/text-input
          Validation: nullable, max:255
          Config: ->maxLength(255)

    Action: Edit
      Component: Filament\Actions\EditAction
      Docs: https://filamentphp.com/docs/5.x/actions/overview
      Location: table row (grouped)
      Visibility: siempre visible

    Action: Delete
      Component: Filament\Actions\DeleteAction
      Docs: https://filamentphp.com/docs/5.x/actions/overview
      Location: table row (grouped)
      Color: danger
      Visibility: solo cuando balance = 0
      Confirmation: "¿Eliminar esta cuenta? Se perderá su historial de movimientos."
      Notification: "Cuenta eliminada"
```

### Relation Managers

```
RelationManager: TransactionsRelationManager
  Location: App\Filament\Resources\Accounts\RelationManagers\TransactionsRelationManager
  Relationship: transactions (MorphMany a Bavix\Wallet\Models\Transaction, provista por HasWallet)
  Title attribute: amount
  Can create: no — use las actions Ingreso/Egreso del header de la página View
  Can edit: no — el ledger es inmutable
  Can delete: no — el ledger es inmutable
  Can view: no

  Table:
    DefaultSort: created_at desc

    Column: created_at
      Component: Filament\Tables\Columns\TextColumn
      Docs: https://filamentphp.com/docs/5.x/tables/columns/text
      Config: ->dateTime()->sortable()->label('Fecha')

    Column: type
      Component: Filament\Tables\Columns\TextColumn
      Docs: https://filamentphp.com/docs/5.x/tables/columns/text
      Config: ->badge()->state(fn ($record) => TransactionType::from($record->type))->label('Tipo')

    Column: amount
      Component: Filament\Tables\Columns\TextColumn
      Docs: https://filamentphp.com/docs/5.x/tables/columns/text
      Config: ->money('usd', divideBy: 100)->sortable()
              ->color(fn ($state) => $state < 0 ? 'danger' : 'success')
              ->summarize(Sum::make()->money('usd', divideBy: 100))

    Column: meta.description
      Component: Filament\Tables\Columns\TextColumn
      Docs: https://filamentphp.com/docs/5.x/tables/columns/text
      Config: ->state(fn ($record) => $record->meta['description'] ?? null)->label('Descripción')

    Column: meta.amount_bs
      Component: Filament\Tables\Columns\TextColumn
      Docs: https://filamentphp.com/docs/5.x/tables/columns/text
      Config: ->state(fn ($record) => $record->meta['amount_bs'] ?? null)
              ->money('ves', divideBy: 100)->label('Monto Bs')
              ->placeholder('—')

    Column: meta.exchange_rate
      Component: Filament\Tables\Columns\TextColumn
      Docs: https://filamentphp.com/docs/5.x/tables/columns/text
      Config: ->state(fn ($record) => $record->meta['exchange_rate'] ?? null)
              ->label('Cotización')->placeholder('—')
              ->toggleable(isToggledHiddenByDefault: true)

    Filter: type
      Component: Filament\Tables\Filters\SelectFilter
      Docs: https://filamentphp.com/docs/5.x/tables/filters/select
      Config: ->options(TransactionType::class)
```

---

## Authorization

```
Authorization: All authenticated users
  - Todos los resources accesibles para cualquier usuario autenticado
  - Sin roles ni restricciones
  - Login requerido vía autenticación del panel Filament
```

---

## Tests

```
AccountResource:
  Validation (use dataset pattern):
    - name: required, max:100

  Component Config:
    - columna name es searchable
    - columna balance muestra como money usd
    - tabla es reorderable por position
    - tabla ordena por position asc por defecto

  CRUD:
    - can render list page
    - can render create page
    - can create record with valid data (position se asigna como max + 1)
    - can render edit page
    - can update record
    - can render view page
    - can delete record only when balance = 0

  Actions:
    - Ingreso:
      - is visible siempre
      - crea una transaction tipo deposit con el monto en cents
      - guarda description en meta
      - el balance de la cuenta aumenta por el monto
    - Egreso:
      - is visible cuando balance > 0
      - is hidden cuando balance = 0
      - crea una transaction tipo withdraw y el balance disminuye
      - falla la validación cuando el monto excede el balance
      - con in_bs: guarda amount_bs (céntimos) y exchange_rate en meta
      - con in_bs: el monto retirado en USD equivale a round(amount_bs_cents / exchange_rate)
      - el campo exchange_rate sugiere la última cotización registrada
    - Delete:
      - is hidden cuando balance > 0

  Cálculos:
    - Account::lastExchangeRate() retorna la cotización del movimiento más reciente que tenga una
    - Account::lastExchangeRate() retorna null sin movimientos en Bs
    - conversión: 3650 Bs a cotización 36.50 equivale a $100.00 (10000 cents)

TransactionsRelationManager:
  Component Config:
    - columna amount muestra como money usd con summarize Sum
    - columna type muestra como badge
    - no permite create, edit ni delete
  CRUD:
    - can render relation manager en la página View
    - lista los movimientos de la cuenta ordenados por fecha desc
  Filters:
    - filtra por tipo deposit/withdraw

Panel:
  - usuario autenticado que visita la raíz del panel es redirigido al listado de cuentas
  - no existe página Dashboard
```

---

## Verification

### Manual Testing Checklist

1. **Entrar a la aplicación**
   - Iniciar sesión en `/admin`
   - Verificar que aterriza directo en el listado de Cuentas (sin dashboard)

2. **Crear y reordenar cuentas**
   - Crear "Efectivo", "Banco" y "Ahorros"; verificar que aparecen en ese orden con balance $0.00
   - Arrastrar "Ahorros" al primer lugar; recargar y verificar que el orden persiste

3. **Registrar ingreso**
   - Click en "Ingreso" en la fila de "Efectivo", monto $100, descripción "Pago inicial"
   - Verificar notificación y balance $100.00 en la fila

4. **Registrar egreso en USD**
   - Click en "Egreso" en "Efectivo", monto $30
   - Verificar balance $70.00
   - Intentar egreso de $500: verificar error de validación por exceder el balance

5. **Registrar egreso en Bolívares**
   - Click en "Egreso" en "Efectivo", activar "Monto en Bolívares"
   - Ingresar 365 Bs y cotización 36.50; verificar que el campo USD muestra $10.00 calculado en vivo
   - Confirmar; verificar balance $60.00
   - Abrir otro egreso en Bs y verificar que la cotización sugiere 36.50

6. **Ver movimientos**
   - Click en "Efectivo"; verificar balance en el detalle
   - Verificar tabla de movimientos con fecha, badge Ingreso/Egreso, montos, descripción, y monto Bs/cotización solo en el egreso en Bs
   - Filtrar por "Egreso" y verificar que solo se listan retiros

7. **Eliminar cuenta**
   - Verificar que "Efectivo" (con balance) no muestra la opción Eliminar
   - Crear una cuenta nueva sin movimientos y eliminarla con confirmación

### Automated Test Commands

```bash
# Run all tests
php artisan test --compact

# Run specific resource
php artisan test --compact --filter=AccountResource

# Run with coverage
php artisan test --compact --coverage
```
