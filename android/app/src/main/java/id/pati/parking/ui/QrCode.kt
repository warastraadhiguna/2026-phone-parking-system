package id.pati.parking.ui

import android.graphics.Bitmap
import com.google.zxing.BarcodeFormat
import com.google.zxing.EncodeHintType
import com.google.zxing.qrcode.QRCodeWriter

/** Renders a QRIS payload string as a black-on-white QR bitmap (display only). */
fun qrBitmap(content: String, size: Int = 640): Bitmap {
    val matrix = QRCodeWriter().encode(content, BarcodeFormat.QR_CODE, size, size, mapOf(EncodeHintType.MARGIN to 1))
    val pixels = IntArray(size * size) { i -> if (matrix.get(i % size, i / size)) 0xFF000000.toInt() else 0xFFFFFFFF.toInt() }

    return Bitmap.createBitmap(size, size, Bitmap.Config.ARGB_8888).apply { setPixels(pixels, 0, size, 0, 0, size, size) }
}
