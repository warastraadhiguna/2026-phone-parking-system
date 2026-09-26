package id.pati.parking.ui

import android.Manifest
import android.os.Bundle
import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.activity.result.contract.ActivityResultContracts
import androidx.activity.viewModels
import androidx.compose.foundation.Image
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ColumnScope
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Button
import androidx.compose.material3.Card
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.asImageBitmap
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardCapitalization
import androidx.compose.ui.text.input.PasswordVisualTransformation
import androidx.compose.ui.unit.dp
import id.pati.parking.PatiParkingApp
import id.pati.parking.domain.QrisPolicy
import id.pati.parking.domain.VehicleType

class MainActivity : ComponentActivity() {
    private val viewModel: MainViewModel by viewModels {
        MainViewModel.Factory((application as PatiParkingApp).container.repository)
    }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        // Location is optional: without it transactions are still recorded (geofence UNKNOWN).
        registerForActivityResult(ActivityResultContracts.RequestMultiplePermissions()) {}
            .launch(arrayOf(Manifest.permission.ACCESS_FINE_LOCATION, Manifest.permission.ACCESS_COARSE_LOCATION))

        setContent { MaterialTheme { App(viewModel) } }
    }
}

@Composable
private fun App(vm: MainViewModel) {
    val state by vm.state.collectAsState()

    Scaffold { padding ->
        Column(Modifier.padding(padding).padding(16.dp).fillMaxSize()) {
            state.message?.let { msg ->
                Card(Modifier.fillMaxWidth()) {
                    Row(Modifier.padding(12.dp)) {
                        Text(msg, color = if (state.isError) Color(0xFFB00020) else Color(0xFF1B5E20), modifier = Modifier.weight(1f))
                        TextButton(onClick = vm::dismissMessage) { Text("Tutup") }
                    }
                }
                Spacer(Modifier.height(12.dp))
            }
            if (state.loggedIn) HomeScreen(vm, state) else LoginScreen(vm, state)
            state.qris?.let { QrisDialog(vm, it, state.busy) }
        }
    }
}

@Composable
private fun LoginScreen(vm: MainViewModel, state: UiState) {
    var username by remember { mutableStateOf("") }
    var password by remember { mutableStateOf("") }

    Text("Pati Parking", style = MaterialTheme.typography.headlineMedium)
    Text("Login juru parkir", style = MaterialTheme.typography.bodyMedium)
    Spacer(Modifier.height(16.dp))
    OutlinedTextField(username, { username = it }, label = { Text("Username (kode jukir)") }, singleLine = true, modifier = Modifier.fillMaxWidth())
    OutlinedTextField(
        password, { password = it }, label = { Text("Kata sandi") }, singleLine = true,
        visualTransformation = PasswordVisualTransformation(), modifier = Modifier.fillMaxWidth(),
    )
    Spacer(Modifier.height(16.dp))
    Button(onClick = { vm.login(username, password) }, enabled = !state.busy && username.isNotBlank() && password.isNotBlank(), modifier = Modifier.fillMaxWidth()) {
        Text(if (state.busy) "Memproses…" else "Masuk")
    }
}

@Composable
private fun ColumnScope.HomeScreen(vm: MainViewModel, state: UiState) {
    val shift by vm.openShift.collectAsState()
    val transactions by vm.transactions.collectAsState()
    val shiftTotal by vm.shiftTotal.collectAsState()
    val qrisTotal by vm.qrisTotal.collectAsState()
    val pending by vm.pendingSync.collectAsState()
    val failed by vm.failedSync.collectAsState()
    var plate by remember { mutableStateOf("") }
    val b = state.bootstrap

    Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween) {
        Column(Modifier.weight(1f)) {
            Text(b?.attendant?.let { "${it.attendantCode} — ${it.name}" } ?: "Juru parkir", fontWeight = FontWeight.Bold)
            Text("Perangkat: ${b?.device?.statusLabel ?: "belum diketahui"}", style = MaterialTheme.typography.bodySmall)
            Text(b?.location?.let { "Lokasi: ${it.locationCode} — ${it.name}" } ?: "Belum ada penugasan", style = MaterialTheme.typography.bodySmall)
        }
        TextButton(onClick = vm::refresh, enabled = !state.busy) { Text("Muat ulang") }
    }
    Text(
        "Sinkron: $pending menunggu" + if (failed > 0) " · $failed gagal (hubungi admin)" else "",
        style = MaterialTheme.typography.bodySmall,
        color = if (failed > 0) Color(0xFFB00020) else Color.Gray,
    )
    Text("Kas dipegang (server): ${rupiah(state.cashBalance)}", style = MaterialTheme.typography.bodySmall)
    SettlementSection(vm, state)
    HorizontalDivider(Modifier.padding(vertical = 8.dp))

    val open = shift
    if (open == null) {
        Button(onClick = vm::startShift, enabled = !state.busy, modifier = Modifier.fillMaxWidth()) { Text("Mulai shift") }
    } else {
        Text("Shift berjalan di ${open.locationCode}" + if (open.offlineCreated) " (offline)" else "", fontWeight = FontWeight.Bold)
        Text("Tunai shift ini: ${rupiah(shiftTotal)} · QRIS lunas: ${rupiah(qrisTotal)} · ${transactions.size} transaksi", style = MaterialTheme.typography.bodySmall)
        Spacer(Modifier.height(8.dp))
        OutlinedTextField(
            plate, { plate = it.uppercase() }, label = { Text("Plat nomor (opsional)") }, singleLine = true,
            keyboardOptions = KeyboardOptions(capitalization = KeyboardCapitalization.Characters), modifier = Modifier.fillMaxWidth(),
        )
        Spacer(Modifier.height(8.dp))
        VehicleType.entries.forEach { vehicle ->
            val price = state.prices[vehicle]
            Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                Button(
                    onClick = { vm.recordCash(vehicle, plate); plate = "" },
                    enabled = !state.busy && price != null,
                    modifier = Modifier.weight(2f).padding(vertical = 2.dp),
                ) {
                    Text("${vehicle.label} — " + (price?.let { "Tunai ${rupiah(it.amount)}" } ?: "tarif tidak tersedia"))
                }
                OutlinedButton(
                    onClick = { vm.startQris(vehicle, plate); plate = "" },
                    enabled = !state.busy && price != null,
                    modifier = Modifier.weight(1f).padding(vertical = 2.dp),
                ) { Text("QRIS") }
            }
        }
        Spacer(Modifier.height(8.dp))
        OutlinedButton(onClick = vm::endShift, enabled = !state.busy, modifier = Modifier.fillMaxWidth()) { Text("Akhiri shift") }
        HorizontalDivider(Modifier.padding(vertical = 8.dp))
        LazyColumn(Modifier.weight(1f)) {
            items(transactions, key = { it.transactionUuid }) { tx ->
                Row(Modifier.fillMaxWidth().padding(vertical = 4.dp), horizontalArrangement = Arrangement.SpaceBetween) {
                    Column {
                        Text("${tx.vehicleType} ${tx.vehiclePlate ?: ""}".trim())
                        Text(tx.serverNumber ?: "#${tx.syncSequence} · ${statusLabel(tx.syncStatus)}", style = MaterialTheme.typography.bodySmall)
                        if (tx.paymentMethod == "QRIS") Text("QRIS · ${qrisLabel(tx.paymentStatus)}", style = MaterialTheme.typography.bodySmall)
                        tx.lastError?.let { Text(it, style = MaterialTheme.typography.bodySmall, color = Color(0xFFB00020)) }
                    }
                    Text(rupiah(tx.chargedAmount), fontWeight = FontWeight.Bold)
                }
            }
        }
    }
    Spacer(Modifier.height(8.dp))
    TextButton(onClick = vm::logout, enabled = !state.busy && pending == 0) {
        Text(if (pending == 0) "Keluar" else "Keluar (tunggu sinkron selesai)")
    }
}

/** Cash summary and deposit (docs/api/settlements.md). Money only leaves the balance when finance verifies. */
@Composable
private fun SettlementSection(vm: MainViewModel, state: UiState) {
    val summary = state.cashSummary ?: return
    var amount by remember(summary.cashBalance) { mutableStateOf(if (summary.cashBalance > 0) summary.cashBalance.toString() else "") }
    var expanded by remember { mutableStateOf(false) }

    Text(
        "Diharapkan ${rupiah(summary.total.collected)} · disetor ${rupiah(summary.total.deposited)} · belum disetor ${rupiah(summary.total.outstanding)}",
        style = MaterialTheme.typography.bodySmall,
    )
    val pending = summary.pendingSettlement
    if (pending != null) {
        Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween) {
            Text("Setoran ${pending.settlementNumber}: ${rupiah(pending.amount)} — ${pending.statusLabel}", style = MaterialTheme.typography.bodySmall, modifier = Modifier.weight(1f))
            TextButton(onClick = vm::cancelSettlement, enabled = !state.busy) { Text("Batalkan") }
        }
    } else if (summary.cashBalance > 0) {
        if (!expanded) {
            TextButton(onClick = { expanded = true }, enabled = !state.busy) { Text("Setor kas") }
        } else {
            Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                OutlinedTextField(
                    amount, { amount = it.filter(Char::isDigit) }, label = { Text("Jumlah setoran (Rp)") }, singleLine = true,
                    keyboardOptions = KeyboardOptions(keyboardType = androidx.compose.ui.text.input.KeyboardType.Number), modifier = Modifier.weight(1f),
                )
                Button(onClick = { vm.submitSettlement(amount.toLongOrNull() ?: 0, null); expanded = false }, enabled = !state.busy && (amount.toLongOrNull() ?: 0) > 0) {
                    Text("Ajukan")
                }
            }
        }
    }
}

private fun qrisLabel(status: String?) = when (status) {
    "PAID" -> "lunas"
    "EXPIRED" -> "kedaluwarsa (batal)"
    "FAILED" -> "gagal (batal)"
    "CANCELLED" -> "dibatalkan"
    else -> "menunggu pembayaran"
}

/** The QR for the customer. Closes only on the attendant's action; status comes from the server. */
@Composable
fun QrisDialog(vm: MainViewModel, qris: QrisUi, busy: Boolean) {
    val final = QrisPolicy.isFinal(qris.status)
    val bitmap = remember(qris.session.qrString) { qris.session.qrString?.let { runCatching { qrBitmap(it) }.getOrNull() } }

    AlertDialog(
        onDismissRequest = {},
        title = { Text("QRIS ${rupiah(qris.session.amount)}") },
        text = {
            Column(horizontalAlignment = androidx.compose.ui.Alignment.CenterHorizontally) {
                if (!final && bitmap != null) {
                    Image(bitmap.asImageBitmap(), contentDescription = "Kode QRIS", modifier = Modifier.size(260.dp))
                } else if (!final) {
                    Text("QR tidak dapat ditampilkan di perangkat ini. Batalkan dan gunakan tunai.")
                }
                Text(qris.session.transactionNumber, style = MaterialTheme.typography.bodySmall)
                Spacer(Modifier.height(8.dp))
                Text(QrisPolicy.message(qris.status), fontWeight = FontWeight.Bold, color = if (qris.status == "PAID") Color(0xFF1B5E20) else Color.Unspecified)
                qris.session.expiresAt?.let { if (!final) Text("Berlaku sampai ${it.toLocalTime().withNano(0)}", style = MaterialTheme.typography.bodySmall) }
            }
        },
        confirmButton = {
            if (final) TextButton(onClick = vm::closeQris) { Text("Tutup") }
        },
        dismissButton = {
            if (!final) TextButton(onClick = vm::cancelQris, enabled = !busy) { Text("Batalkan QR") }
        },
    )
}

private fun statusLabel(status: String) = when (status) {
    "SYNCED" -> "tersinkron"
    "FAILED" -> "gagal"
    else -> "menunggu sinkron"
}

private fun rupiah(amount: Long): String = "Rp" + "%,d".format(amount).replace(',', '.')
