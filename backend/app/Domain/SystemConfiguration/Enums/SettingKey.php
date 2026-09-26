<?php

namespace App\Domain\SystemConfiguration\Enums;

/**
 * Runtime policy settings (ADR-0008). Defaults are development/policy values, not regulation;
 * authorised staff change them in the Control Center (system.configure). All values are integers.
 */
enum SettingKey: string
{
    /** Offline data older than this is accepted but flagged for review. */
    case OFFLINE_TRANSACTION_WARNING_HOURS = 'offline_transaction_warning_hours';

    /** Shifts open longer than this are flagged; supervisors may force-close them. */
    case MAX_OPEN_SHIFT_HOURS = 'max_open_shift_hours';

    /** Cached bootstrap data older than this may not be used to start a shift offline. */
    case OFFLINE_CONFIG_MAX_AGE_HOURS = 'offline_config_max_age_hours';

    /** Device and server clocks differing by more than this are flagged. */
    case MAX_CLOCK_SKEW_MINUTES = 'max_clock_skew_minutes';

    /** GPS fixes less accurate than this cannot decide the geofence result (UNKNOWN). */
    case GPS_MAX_ACCURACY_M = 'gps_max_accuracy_m';

    /** Lifetime of a dynamic QRIS code (master doc §19). */
    case QRIS_EXPIRY_MINUTES = 'qris_expiry_minutes';

    /** Minimum interval between provider status calls for one payment when the app polls. */
    case QRIS_STATUS_CHECK_SECONDS = 'qris_status_check_seconds';

    /** Impossible movement: a jump faster than this between two transactions is flagged. */
    case MOVEMENT_MAX_SPEED_KMH = 'movement_max_speed_kmh';

    /** Impossible movement: jumps shorter than this are ignored (GPS jitter). */
    case MOVEMENT_MIN_DISTANCE_M = 'movement_min_distance_m';

    /** Executive dashboard: monthly revenue target in rupiah (0 = no target set). */
    case MONTHLY_REVENUE_TARGET = 'monthly_revenue_target';

    public function default(): int
    {
        return match ($this) {
            self::OFFLINE_TRANSACTION_WARNING_HOURS => 24,
            self::MAX_OPEN_SHIFT_HOURS => 16,
            self::OFFLINE_CONFIG_MAX_AGE_HOURS => 72,
            self::MAX_CLOCK_SKEW_MINUTES => 10,
            self::GPS_MAX_ACCURACY_M => 100,
            self::QRIS_EXPIRY_MINUTES => 15,
            self::QRIS_STATUS_CHECK_SECONDS => 10,
            self::MOVEMENT_MAX_SPEED_KMH => 60,
            self::MOVEMENT_MIN_DISTANCE_M => 1000,
            self::MONTHLY_REVENUE_TARGET => 0,
        };
    }

    /** @return array{0: int, 1: int} inclusive bounds */
    public function bounds(): array
    {
        return match ($this) {
            self::OFFLINE_TRANSACTION_WARNING_HOURS => [1, 168],
            self::MAX_OPEN_SHIFT_HOURS => [1, 48],
            self::OFFLINE_CONFIG_MAX_AGE_HOURS => [1, 720],
            self::MAX_CLOCK_SKEW_MINUTES => [1, 120],
            self::GPS_MAX_ACCURACY_M => [10, 1000],
            self::QRIS_EXPIRY_MINUTES => [1, 60],
            self::QRIS_STATUS_CHECK_SECONDS => [3, 300],
            self::MOVEMENT_MAX_SPEED_KMH => [5, 300],
            self::MOVEMENT_MIN_DISTANCE_M => [100, 100000],
            self::MONTHLY_REVENUE_TARGET => [0, 1000000000000],
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::OFFLINE_TRANSACTION_WARNING_HOURS => 'Batas peringatan transaksi offline (jam)',
            self::MAX_OPEN_SHIFT_HOURS => 'Batas maksimal shift terbuka (jam)',
            self::OFFLINE_CONFIG_MAX_AGE_HOURS => 'Umur maksimal data konfigurasi offline (jam)',
            self::MAX_CLOCK_SKEW_MINUTES => 'Selisih jam perangkat yang ditoleransi (menit)',
            self::GPS_MAX_ACCURACY_M => 'Akurasi GPS minimal untuk penilaian geofence (meter)',
            self::QRIS_EXPIRY_MINUTES => 'Masa berlaku QRIS dinamis (menit)',
            self::QRIS_STATUS_CHECK_SECONDS => 'Jeda minimal cek status pembayaran ke penyedia (detik)',
            self::MOVEMENT_MAX_SPEED_KMH => 'Kecepatan perpindahan maksimal yang wajar (km/jam)',
            self::MOVEMENT_MIN_DISTANCE_M => 'Jarak perpindahan minimal yang dinilai (meter)',
            self::MONTHLY_REVENUE_TARGET => 'Target pendapatan bulanan (Rp)',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::OFFLINE_TRANSACTION_WARNING_HOURS => 'Data offline yang lebih tua dari batas ini tetap diterima, tetapi ditandai untuk ditinjau.',
            self::MAX_OPEN_SHIFT_HOURS => 'Shift yang terbuka lebih lama dari batas ini ditandai dan dapat ditutup paksa oleh supervisor.',
            self::OFFLINE_CONFIG_MAX_AGE_HOURS => 'Aplikasi tidak boleh memulai shift offline dengan data penugasan/tarif yang lebih tua dari batas ini.',
            self::MAX_CLOCK_SKEW_MINUTES => 'Waktu perangkat yang berbeda lebih dari batas ini dari waktu server ditandai untuk ditinjau.',
            self::GPS_MAX_ACCURACY_M => 'Jika akurasi GPS lebih buruk dari nilai ini, hasil geofence dicatat sebagai tidak diketahui.',
            self::QRIS_EXPIRY_MINUTES => 'QR yang tidak dibayar dalam waktu ini kedaluwarsa; transaksinya menjadi batal.',
            self::QRIS_STATUS_CHECK_SECONDS => 'Saat aplikasi menunggu pembayaran, server menanyakan status ke penyedia paling sering sekali dalam jeda ini.',
            self::MOVEMENT_MAX_SPEED_KMH => 'Dua transaksi juru parkir yang sama dengan perpindahan lebih cepat dari ini ditandai "perpindahan tidak wajar".',
            self::MOVEMENT_MIN_DISTANCE_M => 'Perpindahan lebih pendek dari jarak ini diabaikan agar ketidakakuratan GPS tidak menimbulkan tanda.',
            self::MONTHLY_REVENUE_TARGET => 'Dipakai untuk progres target di dashboard pimpinan. Isi 0 bila belum ada target resmi.',
        };
    }
}
