package id.pati.parking.location

import android.Manifest
import android.annotation.SuppressLint
import android.content.Context
import android.content.pm.PackageManager
import android.location.Location
import android.os.Build
import androidx.core.content.ContextCompat
import com.google.android.gms.location.LocationServices
import com.google.android.gms.location.Priority
import com.google.android.gms.tasks.CancellationTokenSource
import kotlinx.coroutines.suspendCancellableCoroutine
import kotlinx.coroutines.withTimeoutOrNull
import kotlin.coroutines.resume

/** A GPS fix as sent to the server. Mock detection is a signal only (master doc §26). */
data class GpsFix(val latitude: Double, val longitude: Double, val accuracyM: Double?, val mock: Boolean)

/**
 * Best-effort current position. Never blocks a transaction: without permission, provider or a
 * fix within the timeout it returns null and the server records geofence UNKNOWN.
 */
class LocationReader(private val context: Context) {
    @SuppressLint("MissingPermission")
    suspend fun current(timeoutMs: Long = 8_000): GpsFix? {
        if (ContextCompat.checkSelfPermission(context, Manifest.permission.ACCESS_FINE_LOCATION) != PackageManager.PERMISSION_GRANTED) {
            return null
        }
        val client = LocationServices.getFusedLocationProviderClient(context)
        val cancel = CancellationTokenSource()

        val location: Location? = withTimeoutOrNull(timeoutMs) {
            suspendCancellableCoroutine { cont ->
                client.getCurrentLocation(Priority.PRIORITY_HIGH_ACCURACY, cancel.token)
                    .addOnSuccessListener { cont.resume(it) }
                    .addOnFailureListener { cont.resume(null) }
                cont.invokeOnCancellation { cancel.cancel() }
            }
        }

        return location?.let {
            GpsFix(
                latitude = it.latitude,
                longitude = it.longitude,
                accuracyM = if (it.hasAccuracy()) it.accuracy.toDouble() else null,
                mock = if (Build.VERSION.SDK_INT >= 31) it.isMock else @Suppress("DEPRECATION") it.isFromMockProvider,
            )
        }
    }
}
