<?php

namespace App\Domain\Identity\Enums;

use App\Domain\Identity\Enums\Permission as P;

/**
 * Roles (master doc §6) and the role → permission matrix.
 *
 * This enum is the single source of truth for the matrix. `php artisan identity:sync-roles`
 * applies it to the database (also run by the seeder and at deploy). Changing it changes who
 * may do what: it needs owner review. See docs/architecture/roles-and-permissions.md.
 */
enum Role: string
{
    case SUPER_ADMIN = 'SUPER_ADMIN';
    case DISHUB_ADMIN = 'DISHUB_ADMIN';
    case PARKING_OPERATOR = 'PARKING_OPERATOR';
    case FINANCE = 'FINANCE';
    case SUPERVISOR = 'SUPERVISOR';
    case AUDITOR = 'AUDITOR';
    case EXECUTIVE_VIEWER = 'EXECUTIVE_VIEWER';
    case PARKING_ATTENDANT = 'PARKING_ATTENDANT';

    public function label(): string
    {
        return match ($this) {
            self::SUPER_ADMIN => 'Super Admin',
            self::DISHUB_ADMIN => 'Admin Dishub',
            self::PARKING_OPERATOR => 'Operator Parkir',
            self::FINANCE => 'Keuangan',
            self::SUPERVISOR => 'Supervisor',
            self::AUDITOR => 'Auditor',
            self::EXECUTIVE_VIEWER => 'Pimpinan (Executive Viewer)',
            self::PARKING_ATTENDANT => 'Juru Parkir',
        };
    }

    /** The only account type that may hold this role. */
    public function accountType(): AccountType
    {
        return $this === self::PARKING_ATTENDANT ? AccountType::ATTENDANT : AccountType::STAFF;
    }

    /**
     * @return list<self>
     */
    public static function forAccountType(AccountType $type): array
    {
        return array_values(array_filter(self::cases(), fn (self $role) => $role->accountType() === $type));
    }

    /**
     * Separation of duties: Super Admin configures the system and master data but performs no
     * financial approvals; Finance verifies money; Supervisor approves exceptions; Auditor and
     * Executive Viewer are read-only.
     *
     * @return list<P>
     */
    public function permissions(): array
    {
        return match ($this) {
            self::SUPER_ADMIN => [
                P::USERS_VIEW, P::USERS_MANAGE, P::ROLES_VIEW, P::AUDIT_VIEW,
                P::SYSTEM_CONFIGURE, P::PAYMENT_CONFIGURE,
                P::LOCATIONS_VIEW, P::LOCATIONS_MANAGE, P::ATTENDANTS_VIEW, P::ATTENDANTS_MANAGE,
                P::ASSIGNMENTS_MANAGE, P::DEVICES_VIEW, P::DEVICES_MANAGE,
                P::TARIFFS_VIEW, P::TARIFFS_MANAGE, P::TARIFFS_APPROVE,
                P::SHIFTS_VIEW, P::TRANSACTIONS_VIEW, P::PAYMENTS_VIEW, P::SETTLEMENTS_VIEW,
                P::RECONCILIATION_VIEW, P::ANOMALIES_VIEW,
                P::DASHBOARD_OPERATIONAL, P::DASHBOARD_EXECUTIVE, P::REPORTS_VIEW, P::REPORTS_EXPORT,
            ],
            self::DISHUB_ADMIN => [
                P::LOCATIONS_VIEW, P::LOCATIONS_MANAGE, P::ATTENDANTS_VIEW, P::ATTENDANTS_MANAGE,
                P::ASSIGNMENTS_MANAGE, P::DEVICES_VIEW, P::DEVICES_MANAGE,
                P::TARIFFS_VIEW, P::TARIFFS_MANAGE, P::TARIFFS_APPROVE,
                P::SHIFTS_VIEW, P::TRANSACTIONS_VIEW, P::PAYMENTS_VIEW, P::SETTLEMENTS_VIEW,
                P::RECONCILIATION_VIEW, P::ANOMALIES_VIEW,
                P::DASHBOARD_OPERATIONAL, P::DASHBOARD_EXECUTIVE, P::REPORTS_VIEW, P::REPORTS_EXPORT,
            ],
            self::PARKING_OPERATOR => [
                P::LOCATIONS_VIEW, P::ATTENDANTS_VIEW, P::DEVICES_VIEW, P::TARIFFS_VIEW,
                P::SHIFTS_VIEW, P::TRANSACTIONS_VIEW, P::TRANSACTIONS_VOID_REQUEST, P::PAYMENTS_VIEW,
                P::ANOMALIES_VIEW, P::DASHBOARD_OPERATIONAL,
            ],
            self::FINANCE => [
                P::LOCATIONS_VIEW, P::ATTENDANTS_VIEW, P::TARIFFS_VIEW,
                P::SHIFTS_VIEW, P::TRANSACTIONS_VIEW, P::PAYMENTS_VIEW, P::PAYMENTS_REFUND_RECORD,
                P::SETTLEMENTS_VIEW, P::SETTLEMENTS_VERIFY, P::RECONCILIATION_VIEW, P::RECONCILIATION_RUN,
                P::DASHBOARD_OPERATIONAL, P::REPORTS_VIEW, P::REPORTS_EXPORT,
            ],
            self::SUPERVISOR => [
                P::LOCATIONS_VIEW, P::ATTENDANTS_VIEW, P::DEVICES_VIEW, P::TARIFFS_VIEW,
                P::SHIFTS_VIEW, P::SHIFTS_FORCE_CLOSE, P::TRANSACTIONS_VIEW, P::TRANSACTIONS_VOID_APPROVE,
                P::PAYMENTS_VIEW, P::SETTLEMENTS_VIEW, P::ADJUSTMENTS_APPROVE,
                P::ANOMALIES_VIEW, P::ANOMALIES_REVIEW, P::DASHBOARD_OPERATIONAL, P::REPORTS_VIEW,
            ],
            self::AUDITOR => [
                P::USERS_VIEW, P::ROLES_VIEW, P::AUDIT_VIEW,
                P::LOCATIONS_VIEW, P::ATTENDANTS_VIEW, P::DEVICES_VIEW, P::TARIFFS_VIEW,
                P::SHIFTS_VIEW, P::TRANSACTIONS_VIEW, P::PAYMENTS_VIEW, P::SETTLEMENTS_VIEW,
                P::RECONCILIATION_VIEW, P::ANOMALIES_VIEW, P::REPORTS_VIEW, P::REPORTS_EXPORT,
            ],
            self::EXECUTIVE_VIEWER => [
                P::LOCATIONS_VIEW, P::DASHBOARD_EXECUTIVE,
            ],
            self::PARKING_ATTENDANT => [
                P::MOBILE_SHIFT_OPERATE, P::MOBILE_TRANSACTION_CREATE, P::MOBILE_PAYMENT_QRIS,
                P::MOBILE_SETTLEMENT_SUBMIT, P::MOBILE_VOID_REQUEST,
            ],
        };
    }
}
