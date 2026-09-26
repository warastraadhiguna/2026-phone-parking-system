package id.pati.parking.data.local

import android.content.Context
import androidx.room.Database
import androidx.room.Room
import androidx.room.RoomDatabase
import androidx.room.migration.Migration
import androidx.sqlite.db.SupportSQLiteDatabase

@Database(
    entities = [
        LocalShiftEntity::class,
        LocalTransactionEntity::class,
        SyncQueueEntity::class,
        LocalConfigEntity::class,
        CounterEntity::class,
    ],
    version = 2,
    exportSchema = true,
)
abstract class AppDatabase : RoomDatabase() {
    abstract fun shifts(): ShiftDao
    abstract fun transactions(): TransactionDao
    abstract fun syncQueue(): SyncQueueDao
    abstract fun config(): ConfigDao
    abstract fun counters(): CounterDao

    companion object {
        // No destructive migration: unsynced financial records must never be dropped (§23).
        fun create(context: Context): AppDatabase =
            Room.databaseBuilder(context, AppDatabase::class.java, "pati-parking.db")
                .addMigrations(MIGRATION_1_2)
                .build()

        /** Phase 6: QRIS columns. Existing rows are cash transactions. */
        val MIGRATION_1_2 = object : Migration(1, 2) {
            override fun migrate(db: SupportSQLiteDatabase) {
                db.execSQL("ALTER TABLE local_transactions ADD COLUMN paymentMethod TEXT NOT NULL DEFAULT 'CASH'")
                db.execSQL("ALTER TABLE local_transactions ADD COLUMN paymentStatus TEXT")
                db.execSQL("ALTER TABLE local_transactions ADD COLUMN paymentUuid TEXT")
            }
        }
    }
}
