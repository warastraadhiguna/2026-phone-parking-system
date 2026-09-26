<?php

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\CashSettlement\Models\CashSettlement;
use App\Domain\FraudReview\Actions\CollectAnomalies;
use App\Domain\Identity\Enums\Role;
use App\Domain\Payment\Contracts\PaymentGatewayInterface;
use App\Domain\Payment\Internal\Gateways\FakePaymentGateway;
use App\Domain\Payment\Models\Payment;
use App\Domain\Reporting\Enums\ExportStatus;
use App\Domain\Reporting\Internal\ExportWriter;
use App\Domain\Reporting\Models\ReportExport;
use App\Domain\SystemConfiguration\Actions\UpdateSetting;
use App\Domain\SystemConfiguration\Enums\SettingKey;
use App\Support\Time\BusinessTime;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/*
| Phase 10: dashboards (§29–§31), reports and queued exports (§32, §39).
*/

beforeEach(function () {
    arrangeCashTest($this);
    syncRoles();
    Storage::fake('local');
    $this->today = now(BusinessTime::timezone())->toDateString();

    // Today: 2 cash (4000), 1 QRIS paid (2000), 1 with mock location, a pending settlement of 1000.
    txApi('POST', '/api/v1/parking-transactions', cashPayload())->assertCreated();
    txApi('POST', '/api/v1/parking-transactions', cashPayload(['mock_location' => true]))->assertCreated();
    $uuid = txApi('POST', '/api/v1/parking-transactions/qris', cashPayload(['payment_method' => 'QRIS']))->json('data.payment.payment_uuid');
    /** @var FakePaymentGateway $gateway */
    $gateway = app(PaymentGatewayInterface::class);
    app('auth')->forgetGuards();
    $this->postJson('/api/v1/payments/webhooks/fake', $gateway->simulate(Payment::where('payment_uuid', $uuid)->value('provider_order_id')))->assertOk();
    txApi('POST', '/api/v1/settlements', ['settlement_uuid' => (string) Str::uuid(), 'amount' => 1000])->assertCreated();
    app(CollectAnomalies::class)->handle();
    app('auth')->forgetGuards();
});

it('shows the operational dashboard widgets (§29)', function () {
    $this->actingAs(staffUser(Role::PARKING_OPERATOR))->get('/')->assertOk()
        ->assertInertia(fn (Assert $p) => $p->component('Home')
            ->where('view', 'operational')
            ->where('operational.total_revenue', 6000)
            ->where('operational.cash_revenue', 4000)
            ->where('operational.qris_revenue', 2000)
            ->where('operational.transaction_count', 3)
            ->where('operational.open_shifts', 1)
            ->where('operational.active_attendants', 1)
            ->where('operational.active_locations', 1)
            ->where('operational.outstanding_cash', 4000)
            ->where('operational.pending_settlements.count', 1)
            ->where('operational.pending_settlements.amount', 1000)
            ->where('operational.anomalies.HIGH', 1)
            ->where('operational.locations.0.transactions', 3)
            ->where('operational.locations.0.staffed', true));
});

it('shows the executive dashboard with target progress, trend and top locations (§30)', function () {
    app(UpdateSetting::class)->handle(SettingKey::MONTHLY_REVENUE_TARGET, 60000, staffUser(Role::SUPER_ADMIN));

    $this->actingAs(staffUser(Role::EXECUTIVE_VIEWER))->get('/')->assertOk()
        ->assertInertia(fn (Assert $p) => $p->where('view', 'executive')
            ->where('executive.today.total_revenue', 6000)
            ->where('executive.month.total_revenue', 6000)
            ->where('executive.target', 60000)
            ->where('executive.target_progress', 10)
            ->has('executive.trend', 30)
            ->where('executive.trend.29.cash', 4000)
            ->where('executive.top_locations.0.revenue', 6000)
            ->where('operational', null));

    // Roles without dashboards get the welcome card.
    $this->actingAs(staffUser(Role::AUDITOR))->get('/')->assertInertia(fn (Assert $p) => $p->where('view', null));
});

it('previews every report with the same revenue definition as reconciliation', function () {
    $finance = staffUser(Role::FINANCE);
    $get = fn (string $type, array $extra = []) => $this->actingAs($finance)->get('/reports?'.http_build_query(['type' => $type, 'from' => $this->today, 'to' => $this->today, ...$extra]));

    $get('revenue_by_date')->assertInertia(fn (Assert $p) => $p->where('preview.rows.0.total', 6000)->where('preview.rows.0.cash', 4000)->where('preview.rows.0.transactions', 3));
    $get('revenue_by_location')->assertInertia(fn (Assert $p) => $p->where('preview.rows.0.total', 6000));
    $get('revenue_by_attendant')->assertInertia(fn (Assert $p) => $p->where('preview.rows.0.attendant_code', $this->attendant->attendant_code));
    $get('revenue_by_vehicle')->assertInertia(fn (Assert $p) => $p->where('preview.rows.0.vehicle_type', 'MOTORCYCLE'));
    $get('cash_vs_qris')->assertInertia(fn (Assert $p) => $p->where('preview.rows.0.qris_pct', fn ($v) => (float) $v === 33.3));
    $get('cash_outstanding')->assertInertia(fn (Assert $p) => $p->where('preview.rows.0.outstanding', 4000)->where('preview.rows.0.pending_settlement', 1000));
    $get('settlements')->assertInertia(fn (Assert $p) => $p->where('preview.rows.0.amount', 1000));
    $get('transaction_detail', ['payment_method' => 'QRIS'])->assertInertia(fn (Assert $p) => $p->has('preview.rows', 1)->where('preview.rows.0.payment_method', 'QRIS'));
    $get('anomalies')->assertInertia(fn (Assert $p) => $p->where('preview.rows.0.code', 'MOCK_LOCATION'));
    $get('reconciliation')->assertOk();

    // A report never widens access: the audit log needs audit.view as well.
    $get('audit_log')->assertForbidden();
    $this->actingAs(staffUser(Role::AUDITOR))->get('/reports?'.http_build_query(['type' => 'audit_log', 'from' => $this->today, 'to' => $this->today]))
        ->assertInertia(fn (Assert $p) => $p->where('preview.columns.action', 'Aksi'));

    $get('revenue_by_date', ['from' => '2024-01-01'])->assertSessionHasErrors('to');
    $this->actingAs(staffUser(Role::EXECUTIVE_VIEWER))->get('/reports')->assertForbidden();
});

it('exports on the queue to CSV, XLSX and PDF, downloadable only by the requester', function () {
    $finance = staffUser(Role::FINANCE);
    foreach (['csv', 'xlsx', 'pdf'] as $format) {
        $this->actingAs($finance)->post('/reports/exports', ['type' => 'transaction_detail', 'from' => $this->today, 'to' => $this->today, 'format' => $format])->assertSessionHasNoErrors();
    }

    $exports = ReportExport::query()->orderBy('id')->get();
    expect($exports->pluck('status')->all())->toBe([ExportStatus::DONE, ExportStatus::DONE, ExportStatus::DONE])
        ->and($exports->pluck('row_count')->all())->toBe([3, 3, 3]);

    $csv = Storage::disk('local')->get((string) $exports[0]->file_path);
    expect($csv)->toStartWith("\xEF\xBB\xBF".'"No. transaksi"')
        ->and(substr_count((string) $csv, "\n"))->toBe(4)
        ->and(Storage::disk('local')->get((string) $exports[1]->file_path))->toStartWith('PK')
        ->and(Storage::disk('local')->get((string) $exports[2]->file_path))->toStartWith('%PDF');

    $this->actingAs($finance)->get("/reports/exports/{$exports[0]->id}")->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
    $this->actingAs(staffUser(Role::FINANCE))->get("/reports/exports/{$exports[0]->id}")->assertNotFound();
    $this->actingAs(staffUser(Role::SUPERVISOR))->post('/reports/exports', ['type' => 'transaction_detail', 'from' => $this->today, 'to' => $this->today, 'format' => 'csv'])->assertForbidden();

    expect(auditOf(AuditAction::REPORT_EXPORT_REQUESTED)->count())->toBe(3)
        ->and(auditOf(AuditAction::REPORT_EXPORT_DOWNLOADED)->count())->toBe(1);

    // Retention: expired files are deleted, the record stays.
    $this->travel(8)->days();
    $this->artisan('reports:prune-exports')->assertSuccessful();
    expect($exports[0]->refresh()->status)->toBe(ExportStatus::EXPIRED)
        ->and(Storage::disk('local')->exists('exports/'.$exports[0]->export_uuid.'.csv'))->toBeFalse();
});

it('neutralises spreadsheet formulas in CSV cells', function () {
    expect(ExportWriter::safeCell('=HYPERLINK("x")'))->toBe("'=HYPERLINK(\"x\")")
        ->and(ExportWriter::safeCell('+1+1'))->toBe("'+1+1")
        ->and(ExportWriter::safeCell('@SUM(A1)'))->toBe("'@SUM(A1)")
        ->and(ExportWriter::safeCell('-500'))->toBe('-500')
        ->and(ExportWriter::safeCell(-500))->toBe(-500)
        ->and(ExportWriter::safeCell('K1234AB'))->toBe('K1234AB')
        ->and(ExportWriter::safeCell(null))->toBeNull();

    $settlement = CashSettlement::sole();
    expect($settlement->amount)->toBe(1000);
});
