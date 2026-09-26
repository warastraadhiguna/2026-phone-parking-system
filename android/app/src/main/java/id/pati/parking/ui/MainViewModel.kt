package id.pati.parking.ui

import androidx.lifecycle.ViewModel
import androidx.lifecycle.ViewModelProvider
import androidx.lifecycle.viewModelScope
import id.pati.parking.data.ActionResult
import id.pati.parking.data.ParkingRepository
import id.pati.parking.data.QrisSession
import id.pati.parking.data.QrisStart
import id.pati.parking.data.local.LocalShiftEntity
import id.pati.parking.data.local.LocalTransactionEntity
import id.pati.parking.data.remote.Bootstrap
import id.pati.parking.data.remote.CashSummaryDto
import id.pati.parking.domain.Price
import id.pati.parking.domain.QrisPolicy
import id.pati.parking.domain.VehicleType
import kotlinx.coroutines.ExperimentalCoroutinesApi
import kotlinx.coroutines.Job
import kotlinx.coroutines.delay
import kotlinx.coroutines.isActive
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.SharingStarted
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.flatMapLatest
import kotlinx.coroutines.flow.flowOf
import kotlinx.coroutines.flow.stateIn
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch
import java.time.OffsetDateTime

data class UiState(
    val loggedIn: Boolean = false,
    val busy: Boolean = false,
    val message: String? = null,
    val isError: Boolean = false,
    val bootstrap: Bootstrap? = null,
    val prices: Map<VehicleType, Price?> = emptyMap(),
    val cashBalance: Long = 0,
    /** The QR currently shown to a customer, with the status the server reported last. */
    val qris: QrisUi? = null,
    /** Server ledger view: expected, deposited, outstanding (null until loaded). */
    val cashSummary: CashSummaryDto? = null,
)

data class QrisUi(val session: QrisSession, val status: String)

@OptIn(ExperimentalCoroutinesApi::class)
class MainViewModel(private val repo: ParkingRepository) : ViewModel() {
    private val _state = MutableStateFlow(UiState(loggedIn = repo.isLoggedIn(), cashBalance = repo.lastCashBalance()))
    val state: StateFlow<UiState> = _state.asStateFlow()

    val openShift: StateFlow<LocalShiftEntity?> = repo.observeOpenShift().stateIn(viewModelScope, SharingStarted.Eagerly, null)
    val transactions: StateFlow<List<LocalTransactionEntity>> = openShift
        .flatMapLatest { shift -> shift?.let { repo.observeTransactions(it.shiftUuid) } ?: flowOf(emptyList()) }
        .stateIn(viewModelScope, SharingStarted.Eagerly, emptyList())
    val shiftTotal: StateFlow<Long> = openShift
        .flatMapLatest { shift -> shift?.let { repo.observeShiftTotal(it.shiftUuid) } ?: flowOf(0L) }
        .stateIn(viewModelScope, SharingStarted.Eagerly, 0L)
    val qrisTotal: StateFlow<Long> = openShift
        .flatMapLatest { shift -> shift?.let { repo.observeQrisTotal(it.shiftUuid) } ?: flowOf(0L) }
        .stateIn(viewModelScope, SharingStarted.Eagerly, 0L)
    val pendingSync: StateFlow<Int> = repo.observePendingSync().stateIn(viewModelScope, SharingStarted.Eagerly, 0)
    val failedSync: StateFlow<Int> = repo.observeFailedSync().stateIn(viewModelScope, SharingStarted.Eagerly, 0)

    init {
        if (repo.isLoggedIn()) refresh()
    }

    fun login(username: String, password: String) = run {
        val result = repo.login(username, password)
        _state.update { it.copy(loggedIn = repo.isLoggedIn()) }
        loadLocal()
        result
    }

    fun refresh() = run {
        repo.refreshBootstrap()
        repo.refreshCashBalance()
        openShift.value?.let { repo.refreshOpenQris(it.shiftUuid) }
        loadSummary()
        loadLocal()
        null
    }

    fun startShift() = run { repo.startShift() }
    fun recordCash(vehicle: VehicleType, plate: String?) = run { repo.recordCash(vehicle, plate) }
    fun endShift() = run { repo.endShift() }

    fun submitSettlement(amount: Long, notes: String?) = run {
        val result = repo.submitSettlement(amount, notes)
        loadSummary()
        result
    }

    fun cancelSettlement() = run {
        val pending = _state.value.cashSummary?.pendingSettlement ?: return@run null
        val result = repo.cancelSettlement(pending.settlementUuid)
        loadSummary()
        result
    }

    private suspend fun loadSummary() {
        repo.cashSummary()?.let { summary -> _state.update { it.copy(cashSummary = summary, cashBalance = summary.cashBalance) } }
    }

    private var pollJob: Job? = null

    fun startQris(vehicle: VehicleType, plate: String?) = run {
        when (val started = repo.startQris(vehicle, plate)) {
            is QrisStart.Shown -> {
                _state.update { it.copy(qris = QrisUi(started.session, started.session.status)) }
                poll()
                null
            }
            is QrisStart.Failed -> ActionResult.Error(started.message)
        }
    }

    /** Withdraws the QR at the provider. The result is whatever the provider confirms (it may be PAID). */
    fun cancelQris() = run {
        val qris = _state.value.qris ?: return@run null
        val result = repo.cancelQris(qris.session.paymentUuid)
        repo.pollPayment(qris.session.paymentUuid)?.let { p -> _state.update { it.copy(qris = it.qris?.copy(status = p.status)) } }
        result
    }

    fun closeQris() {
        pollJob?.cancel()
        _state.update { it.copy(qris = null) }
    }

    private fun poll() {
        pollJob?.cancel()
        pollJob = viewModelScope.launch {
            while (isActive) {
                delay(POLL_MS)
                val qris = _state.value.qris ?: break
                val payment = repo.pollPayment(qris.session.paymentUuid) ?: continue
                _state.update { it.copy(qris = it.qris?.copy(status = payment.status)) }
                if (!QrisPolicy.shouldPoll(payment.status, qris.session.expiresAt, OffsetDateTime.now())) break
            }
        }
    }

    fun logout() = run {
        repo.logout()
        _state.value = UiState(loggedIn = false)
        null
    }

    fun dismissMessage() = _state.update { it.copy(message = null) }

    private suspend fun loadLocal() {
        val bootstrap = repo.cachedBootstrap()
        val prices = VehicleType.entries.associateWith { repo.priceFor(it) }
        _state.update { it.copy(bootstrap = bootstrap, prices = prices, cashBalance = repo.lastCashBalance()) }
    }

    private fun run(block: suspend () -> ActionResult?) {
        viewModelScope.launch {
            _state.update { it.copy(busy = true) }
            val result = try {
                block()
            } catch (e: Exception) {
                ActionResult.Error("Terjadi kesalahan: ${e.message}")
            }
            _state.update {
                it.copy(
                    busy = false,
                    message = when (result) { is ActionResult.Ok -> result.message; is ActionResult.Error -> result.message; null -> it.message },
                    isError = result is ActionResult.Error,
                    cashBalance = repo.lastCashBalance(),
                )
            }
        }
    }

    private companion object {
        const val POLL_MS = 3_000L
    }

    class Factory(private val repo: ParkingRepository) : ViewModelProvider.Factory {
        @Suppress("UNCHECKED_CAST")
        override fun <T : ViewModel> create(modelClass: Class<T>): T = MainViewModel(repo) as T
    }
}
