package id.pati.parking.domain

import java.time.OffsetDateTime

/**
 * App-side QRIS rules (master doc §16, §19; docs/api/payments.md). The app never decides that a
 * payment succeeded: it only shows what the server reports, which the server takes from the
 * provider.
 */
object QrisPolicy {
    private val FINAL = setOf("PAID", "EXPIRED", "FAILED", "CANCELLED")

    /** After the QR expires, keep asking a little longer: the provider may still confirm. */
    const val GRACE_SECONDS = 120L

    fun isFinal(status: String): Boolean = status in FINAL

    fun shouldPoll(status: String, expiresAt: OffsetDateTime?, now: OffsetDateTime): Boolean =
        !isFinal(status) && (expiresAt == null || !now.isAfter(expiresAt.plusSeconds(GRACE_SECONDS)))

    fun message(status: String): String = when (status) {
        "PAID" -> "Pembayaran QRIS diterima. Transaksi selesai."
        "EXPIRED" -> "QR kedaluwarsa. Transaksi batal; buat transaksi baru bila pelanggan masih ingin membayar."
        "FAILED" -> "Pembayaran QRIS gagal. Transaksi batal."
        "CANCELLED" -> "QR dibatalkan. Transaksi batal."
        "CREATED" -> "Menyiapkan QR…"
        else -> "Menunggu pelanggan membayar…"
    }
}
