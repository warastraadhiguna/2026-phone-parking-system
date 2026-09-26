<?php

namespace App\Domain\Identity\Enums;

/**
 * Permission catalogue. Names are stable identifiers used in gates, routes and the frontend.
 *
 * Permissions for later phases are defined now so the full role matrix (Role::permissions())
 * can be reviewed as a whole. A permission guards nothing until its feature is built.
 */
enum Permission: string
{
    // Identity & system (Phase 1)
    case USERS_VIEW = 'users.view';
    case USERS_MANAGE = 'users.manage';
    case ROLES_VIEW = 'roles.view';
    case AUDIT_VIEW = 'audit.view';
    case SYSTEM_CONFIGURE = 'system.configure';
    case PAYMENT_CONFIGURE = 'payment.configure';

    // Master data (Phase 2)
    case LOCATIONS_VIEW = 'locations.view';
    case LOCATIONS_MANAGE = 'locations.manage';
    case ATTENDANTS_VIEW = 'attendants.view';
    case ATTENDANTS_MANAGE = 'attendants.manage';
    case ASSIGNMENTS_MANAGE = 'assignments.manage';
    case DEVICES_VIEW = 'devices.view';
    case DEVICES_MANAGE = 'devices.manage';
    case TARIFFS_VIEW = 'tariffs.view';
    case TARIFFS_MANAGE = 'tariffs.manage';
    case TARIFFS_APPROVE = 'tariffs.approve';

    // Operations monitoring (Phases 3–6)
    case SHIFTS_VIEW = 'shifts.view';
    case SHIFTS_FORCE_CLOSE = 'shifts.force_close';
    case TRANSACTIONS_VIEW = 'transactions.view';
    case TRANSACTIONS_VOID_REQUEST = 'transactions.void_request';
    case TRANSACTIONS_VOID_APPROVE = 'transactions.void_approve';
    case PAYMENTS_VIEW = 'payments.view';

    // Records a manual refund of a PAID QRIS payment (ADR-0006, owner decision Q3: finance).
    case PAYMENTS_REFUND_RECORD = 'payments.refund_record';

    // Finance (Phases 7–8)
    case SETTLEMENTS_VIEW = 'settlements.view';
    case SETTLEMENTS_VERIFY = 'settlements.verify';
    case RECONCILIATION_VIEW = 'reconciliation.view';
    case RECONCILIATION_RUN = 'reconciliation.run';
    case ADJUSTMENTS_APPROVE = 'adjustments.approve';

    // Review (Phase 9)
    case ANOMALIES_VIEW = 'anomalies.view';
    case ANOMALIES_REVIEW = 'anomalies.review';

    // Dashboards & reports (Phase 10)
    case DASHBOARD_OPERATIONAL = 'dashboard.operational';
    case DASHBOARD_EXECUTIVE = 'dashboard.executive';
    case REPORTS_VIEW = 'reports.view';
    case REPORTS_EXPORT = 'reports.export';

    // Mobile app (attendants)
    case MOBILE_SHIFT_OPERATE = 'mobile.shift.operate';
    case MOBILE_TRANSACTION_CREATE = 'mobile.transaction.create';
    case MOBILE_PAYMENT_QRIS = 'mobile.payment.qris';
    case MOBILE_SETTLEMENT_SUBMIT = 'mobile.settlement.submit';
    case MOBILE_VOID_REQUEST = 'mobile.void.request';

    public function isMobile(): bool
    {
        return str_starts_with($this->value, 'mobile.');
    }

    /** Read-only permissions: they grant visibility, never a state change. */
    public function isReadOnly(): bool
    {
        return in_array($this, [
            self::USERS_VIEW, self::ROLES_VIEW, self::AUDIT_VIEW, self::LOCATIONS_VIEW,
            self::ATTENDANTS_VIEW, self::DEVICES_VIEW, self::TARIFFS_VIEW, self::SHIFTS_VIEW,
            self::TRANSACTIONS_VIEW, self::PAYMENTS_VIEW, self::SETTLEMENTS_VIEW,
            self::RECONCILIATION_VIEW, self::ANOMALIES_VIEW, self::DASHBOARD_OPERATIONAL,
            self::DASHBOARD_EXECUTIVE, self::REPORTS_VIEW, self::REPORTS_EXPORT,
        ], true);
    }
}
