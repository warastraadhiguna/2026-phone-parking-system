package id.pati.parking.data.prefs

import android.content.Context
import java.util.UUID

/** Non-secret device settings. */
class AppPrefs(context: Context) {
    private val prefs = context.getSharedPreferences("app", Context.MODE_PRIVATE)

    /**
     * Generated once per installation and reused forever (device binding, ADR-0005). A reinstall
     * gives a new UUID, which the server registers as a new, pending device.
     */
    val deviceUuid: String
        get() = prefs.getString(KEY_DEVICE_UUID, null) ?: UUID.randomUUID().toString().also {
            prefs.edit().putString(KEY_DEVICE_UUID, it).apply()
        }

    var username: String?
        get() = prefs.getString(KEY_USERNAME, null)
        set(value) = prefs.edit().putString(KEY_USERNAME, value).apply()

    /** ADR-0008 offline precondition 2: the attendant has logged in successfully on this device. */
    var hasLoggedInBefore: Boolean
        get() = prefs.getBoolean(KEY_LOGGED_IN_BEFORE, false)
        set(value) = prefs.edit().putBoolean(KEY_LOGGED_IN_BEFORE, value).apply()

    var lastCashBalance: Long
        get() = prefs.getLong(KEY_CASH_BALANCE, 0)
        set(value) = prefs.edit().putLong(KEY_CASH_BALANCE, value).apply()

    private companion object {
        const val KEY_DEVICE_UUID = "device_uuid"
        const val KEY_USERNAME = "username"
        const val KEY_LOGGED_IN_BEFORE = "logged_in_before"
        const val KEY_CASH_BALANCE = "cash_balance"
    }
}
