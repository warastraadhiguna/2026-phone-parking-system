package id.pati.parking.data.prefs

import android.content.Context
import android.security.keystore.KeyGenParameterSpec
import android.security.keystore.KeyProperties
import android.util.Base64
import id.pati.parking.data.remote.TokenPair
import kotlinx.serialization.json.Json
import java.security.KeyStore
import javax.crypto.Cipher
import javax.crypto.KeyGenerator
import javax.crypto.SecretKey
import javax.crypto.spec.GCMParameterSpec

/** Where the app keeps its tokens. */
interface TokenStore {
    fun save(tokens: TokenPair)
    fun load(): TokenPair?
    fun clear()
}

/**
 * Access/refresh tokens encrypted with an AES-GCM key held in the Android Keystore (master doc §34).
 * The key never leaves the Keystore; only ciphertext is stored in SharedPreferences.
 * Passwords are never stored.
 */
class SecureTokenStore(context: Context) : TokenStore {
    private val prefs = context.getSharedPreferences("secure_tokens", Context.MODE_PRIVATE)
    private val json = Json { ignoreUnknownKeys = true }

    override fun save(tokens: TokenPair) {
        val cipher = Cipher.getInstance(TRANSFORMATION).apply { init(Cipher.ENCRYPT_MODE, key()) }
        val encrypted = cipher.doFinal(json.encodeToString(TokenPair.serializer(), tokens).toByteArray(Charsets.UTF_8))
        prefs.edit()
            .putString(KEY_IV, Base64.encodeToString(cipher.iv, Base64.NO_WRAP))
            .putString(KEY_DATA, Base64.encodeToString(encrypted, Base64.NO_WRAP))
            .apply()
    }

    override fun load(): TokenPair? {
        val iv = prefs.getString(KEY_IV, null) ?: return null
        val data = prefs.getString(KEY_DATA, null) ?: return null

        return try {
            val cipher = Cipher.getInstance(TRANSFORMATION).apply {
                init(Cipher.DECRYPT_MODE, key(), GCMParameterSpec(128, Base64.decode(iv, Base64.NO_WRAP)))
            }
            json.decodeFromString(TokenPair.serializer(), String(cipher.doFinal(Base64.decode(data, Base64.NO_WRAP)), Charsets.UTF_8))
        } catch (e: Exception) {
            // Key invalidated (e.g. lock-screen reset): the attendant simply logs in again.
            clear()
            null
        }
    }

    override fun clear() {
        prefs.edit().clear().apply()
    }

    private fun key(): SecretKey {
        val keyStore = KeyStore.getInstance(ANDROID_KEYSTORE).apply { load(null) }
        (keyStore.getKey(KEY_ALIAS, null) as? SecretKey)?.let { return it }

        val generator = KeyGenerator.getInstance(KeyProperties.KEY_ALGORITHM_AES, ANDROID_KEYSTORE)
        generator.init(
            KeyGenParameterSpec.Builder(KEY_ALIAS, KeyProperties.PURPOSE_ENCRYPT or KeyProperties.PURPOSE_DECRYPT)
                .setBlockModes(KeyProperties.BLOCK_MODE_GCM)
                .setEncryptionPaddings(KeyProperties.ENCRYPTION_PADDING_NONE)
                .setKeySize(256)
                .build(),
        )
        return generator.generateKey()
    }

    private companion object {
        const val ANDROID_KEYSTORE = "AndroidKeyStore"
        const val KEY_ALIAS = "pati_parking_tokens"
        const val TRANSFORMATION = "AES/GCM/NoPadding"
        const val KEY_IV = "iv"
        const val KEY_DATA = "data"
    }
}
