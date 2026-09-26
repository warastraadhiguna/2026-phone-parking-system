package id.pati.parking

import android.app.Application
import id.pati.parking.data.ParkingRepository
import id.pati.parking.data.local.AppDatabase
import id.pati.parking.data.prefs.AppPrefs
import id.pati.parking.data.prefs.SecureTokenStore
import id.pati.parking.data.remote.ApiClient
import id.pati.parking.location.LocationReader
import id.pati.parking.sync.RoomSyncStore
import id.pati.parking.sync.SyncEngine
import id.pati.parking.sync.SyncWorker

/** Manual dependency wiring; small enough that a DI framework would add more than it saves. */
class AppContainer(app: Application) {
    val prefs = AppPrefs(app)
    val tokens = SecureTokenStore(app)
    val db = AppDatabase.create(app)
    val api = ApiClient(BuildConfig.API_BASE_URL, tokens) { prefs.deviceUuid }
    val syncEngine = SyncEngine(api, RoomSyncStore(db, prefs))
    val repository = ParkingRepository(app, db, api, tokens, prefs, LocationReader(app))
}

class PatiParkingApp : Application() {
    lateinit var container: AppContainer
        private set

    override fun onCreate() {
        super.onCreate()
        container = AppContainer(this)
        // Anything left in the queue from a previous run is sent as soon as there is a connection.
        SyncWorker.schedule(this)
    }
}
