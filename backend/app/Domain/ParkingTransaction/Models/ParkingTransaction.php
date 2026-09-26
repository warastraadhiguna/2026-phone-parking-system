<?php

namespace App\Domain\ParkingTransaction\Models;

use App\Domain\CashLedger\Models\CashLedgerEntry;
use App\Domain\Device\Models\Device;
use App\Domain\ParkingAttendant\Models\ParkingAttendant;
use App\Domain\ParkingLocation\Enums\GeofenceResult;
use App\Domain\ParkingLocation\Models\ParkingLocation;
use App\Domain\ParkingTransaction\Enums\PaymentMethod;
use App\Domain\ParkingTransaction\Enums\TransactionFlag;
use App\Domain\ParkingTransaction\Enums\TransactionStatus;
use App\Domain\Shift\Models\Shift;
use App\Domain\Tariff\Enums\VehicleType;
use App\Domain\Tariff\Models\Tariff;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A parking transaction. Written only by the ParkingTransaction Actions; after insert only
 * status (along §47) and review flags can change — enforced by a database trigger.
 *
 * @property int $id
 * @property string $transaction_uuid
 * @property string $transaction_number
 * @property int $shift_id
 * @property int $attendant_id
 * @property int $location_id
 * @property int $device_id
 * @property int $sync_sequence
 * @property VehicleType $vehicle_type
 * @property string|null $vehicle_plate
 * @property int|null $tariff_id
 * @property int|null $device_tariff_id
 * @property int $charged_tariff_amount
 * @property int|null $server_expected_tariff_amount
 * @property int|null $tariff_difference_amount
 * @property PaymentMethod $payment_method
 * @property TransactionStatus $status
 * @property CarbonImmutable $transaction_time_device
 * @property CarbonImmutable $transaction_time_server
 * @property string|null $latitude
 * @property string|null $longitude
 * @property string|null $gps_accuracy_m
 * @property bool $mock_location
 * @property GeofenceResult $geofence_result
 * @property int|null $distance_m
 * @property bool $offline_created
 * @property list<string> $review_flags
 * @property string $payload_hash
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
class ParkingTransaction extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'sync_sequence' => 'integer',
            'vehicle_type' => VehicleType::class,
            'charged_tariff_amount' => 'integer',
            'server_expected_tariff_amount' => 'integer',
            'tariff_difference_amount' => 'integer',
            'payment_method' => PaymentMethod::class,
            'status' => TransactionStatus::class,
            'transaction_time_device' => 'immutable_datetime',
            'transaction_time_server' => 'immutable_datetime',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'gps_accuracy_m' => 'decimal:2',
            'mock_location' => 'boolean',
            'geofence_result' => GeofenceResult::class,
            'offline_created' => 'boolean',
            'review_flags' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Shift, $this> */
    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    /** @return BelongsTo<ParkingAttendant, $this> */
    public function attendant(): BelongsTo
    {
        return $this->belongsTo(ParkingAttendant::class, 'attendant_id');
    }

    /** @return BelongsTo<ParkingLocation, $this> */
    public function location(): BelongsTo
    {
        return $this->belongsTo(ParkingLocation::class, 'location_id');
    }

    /** @return BelongsTo<Device, $this> */
    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class, 'device_id');
    }

    /** @return BelongsTo<Tariff, $this> */
    public function tariff(): BelongsTo
    {
        return $this->belongsTo(Tariff::class);
    }

    /** @return HasMany<CashLedgerEntry, $this> */
    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(CashLedgerEntry::class, 'transaction_id');
    }

    /** @return HasMany<VoidRequest, $this> */
    public function voidRequests(): HasMany
    {
        return $this->hasMany(VoidRequest::class, 'transaction_id');
    }

    /** @param  list<TransactionFlag>  $flags */
    public function withFlags(array $flags): self
    {
        $this->review_flags = array_values(array_unique([...($this->review_flags ?? []), ...array_map(fn (TransactionFlag $f) => $f->value, $flags)]));

        return $this;
    }
}
