<?php

/*
| Architecture rules for the modular monolith (docs/architecture/overview.md#module-boundaries).
| These catch obvious boundary violations; code review catches the rest.
*/

$modules = array_map('basename', glob(__DIR__.'/../../app/Domain/*', GLOB_ONLYDIR) ?: []);

arch('every module listed in the master documentation exists')
    ->expect($modules)
    ->toEqualCanonicalizing([
        'Assignment', 'Audit', 'CashLedger', 'CashSettlement', 'Device', 'FraudReview', 'Identity',
        'Notification', 'ParkingAttendant', 'ParkingLocation', 'ParkingTransaction', 'Payment',
        'Reconciliation', 'Reporting', 'Shift', 'SystemConfiguration', 'Tariff',
    ]);

foreach ($modules as $module) {
    arch("{$module}: Internal namespace is private to the module")
        ->expect("App\\Domain\\{$module}\\Internal")
        ->toOnlyBeUsedIn("App\\Domain\\{$module}");
}

arch('domain code does not depend on the HTTP layer')
    ->expect('App\Domain')
    ->not->toUse(['App\Http', 'Illuminate\Http\Request', 'Illuminate\Support\Facades\Request', 'Inertia']);

arch('shared support code does not depend on domain modules')
    ->expect('App\Support')
    ->not->toUse('App\Domain');

arch('payment provider SDKs and adapters are used only inside the Payment module')
    ->expect(['Midtrans', 'App\Domain\Payment\Internal\Gateways'])
    ->toOnlyBeUsedIn('App\Domain\Payment');

arch('no debugging or process-terminating calls')
    ->expect(['dd', 'dump', 'ray', 'var_dump', 'print_r', 'phpinfo'])
    ->not->toBeUsed();

arch('application code does not read env() outside config files')
    ->expect('App')
    ->not->toUse('env');

arch('role/permission storage is managed only by the Identity module')
    ->expect(['Spatie\Permission\Models', 'App\Domain\Identity\Models\MobileRefreshToken'])
    ->toOnlyBeUsedIn('App\Domain\Identity');

arch('audit records are written only through RecordAuditEvent')
    ->expect('App\Domain\Audit\Models\AuditLog')
    ->toOnlyBeUsedIn('App\Domain\Audit');
