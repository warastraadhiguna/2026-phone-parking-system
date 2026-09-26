<?php

namespace App\Domain\Reporting\Enums;

use App\Domain\Identity\Enums\Permission;

/** Master doc §32 minimum reports. */
enum ReportType: string
{
    case REVENUE_BY_DATE = 'revenue_by_date';
    case REVENUE_BY_LOCATION = 'revenue_by_location';
    case REVENUE_BY_ATTENDANT = 'revenue_by_attendant';
    case REVENUE_BY_VEHICLE = 'revenue_by_vehicle';
    case CASH_VS_QRIS = 'cash_vs_qris';
    case CASH_OUTSTANDING = 'cash_outstanding';
    case SETTLEMENTS = 'settlements';
    case RECONCILIATION = 'reconciliation';
    case TRANSACTION_DETAIL = 'transaction_detail';
    case ANOMALIES = 'anomalies';
    case AUDIT_LOG = 'audit_log';

    public function label(): string
    {
        return match ($this) {
            self::REVENUE_BY_DATE => 'Pendapatan per tanggal',
            self::REVENUE_BY_LOCATION => 'Pendapatan per lokasi',
            self::REVENUE_BY_ATTENDANT => 'Pendapatan per juru parkir',
            self::REVENUE_BY_VEHICLE => 'Pendapatan per jenis kendaraan',
            self::CASH_VS_QRIS => 'Tunai vs QRIS',
            self::CASH_OUTSTANDING => 'Kas belum disetor (saat ini)',
            self::SETTLEMENTS => 'Setoran kas',
            self::RECONCILIATION => 'Rekonsiliasi harian',
            self::TRANSACTION_DETAIL => 'Detail transaksi',
            self::ANOMALIES => 'Anomali / tinjauan',
            self::AUDIT_LOG => 'Log audit',
        };
    }

    /** Permissions needed besides reports.view (the report must not widen access). */
    public function extraPermission(): ?Permission
    {
        return $this === self::AUDIT_LOG ? Permission::AUDIT_VIEW : null;
    }

    /** Whether the report uses the date range (the outstanding report is a current snapshot). */
    public function usesDateRange(): bool
    {
        return $this !== self::CASH_OUTSTANDING;
    }
}
