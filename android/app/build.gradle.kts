import java.util.Properties

plugins {
    // AGP 9+ compiles Kotlin itself; Compose, serialization and KSP stay separate plugins.
    alias(libs.plugins.android.application)
    alias(libs.plugins.kotlin.compose)
    alias(libs.plugins.kotlin.serialization)
    alias(libs.plugins.kotlin.ksp)
}

android {
    namespace = "id.pati.parking"
    compileSdk = 37

    defaultConfig {
        applicationId = "id.pati.parking"
        minSdk = 26
        targetSdk = 37
        versionCode = 1
        versionName = "1.0.0-uji"

        testInstrumentationRunner = "androidx.test.runner.AndroidJUnitRunner"
    }

    // Release signing for side-loaded test installs. The keystore and its passwords live only on
    // the build machine (android/keystore.properties + *.jks, both git-ignored). Keep a backup:
    // updates of an installed app must be signed with the same key.
    val signingFile = rootProject.file("keystore.properties")
    if (signingFile.exists()) {
        val props = Properties().apply { signingFile.inputStream().use { load(it) } }
        signingConfigs.create("release") {
            storeFile = rootProject.file(props.getProperty("storeFile"))
            storePassword = props.getProperty("storePassword")
            keyAlias = props.getProperty("keyAlias")
            keyPassword = props.getProperty("keyPassword")
        }
    }

    buildTypes {
        debug {
            // Cleartext HTTP to the local docker stack is allowed only in debug (src/debug/res/xml).
            buildConfigField("String", "API_BASE_URL", "\"${project.property("patiApiBaseUrlDebug")}\"")
        }
        release {
            buildConfigField("String", "API_BASE_URL", "\"${project.property("patiApiBaseUrlRelease")}\"")
            isMinifyEnabled = false
            signingConfigs.findByName("release")?.let { signingConfig = it }
            proguardFiles(getDefaultProguardFile("proguard-android-optimize.txt"), "proguard-rules.pro")
        }
    }

    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_17
        targetCompatibility = JavaVersion.VERSION_17
    }

    buildFeatures {
        compose = true
        buildConfig = true
    }

    testOptions {
        unitTests.isReturnDefaultValues = true
    }

    packaging {
        resources {
            excludes += "/META-INF/{AL2.0,LGPL2.1}"
        }
    }
}

// Release guard (Phase 11): a release APK must talk HTTPS to a real server. The default release
// URL is a .invalid placeholder, so a release build fails until -PpatiApiBaseUrlRelease=https://… is given.
gradle.taskGraph.whenReady {
    if (allTasks.any { it.project == project && it.name.contains("Release") }) {
        val url = project.property("patiApiBaseUrlRelease").toString()
        if (!url.startsWith("https://") || url.contains(".invalid")) {
            throw GradleException("patiApiBaseUrlRelease must be a real https:// URL for release builds (got $url).")
        }
    }
}

// Room schema JSON is committed so every schema version is reviewable and migrations can be
// checked against the real previous version.
ksp {
    arg("room.schemaLocation", "$projectDir/schemas")
}

dependencies {
    implementation(libs.androidx.core.ktx)
    implementation(libs.androidx.lifecycle.runtime.ktx)
    implementation(libs.androidx.lifecycle.runtime.compose)
    implementation(libs.androidx.lifecycle.viewmodel.compose)
    implementation(libs.androidx.activity.compose)
    implementation(platform(libs.androidx.compose.bom))
    implementation(libs.androidx.ui)
    implementation(libs.androidx.ui.tooling.preview)
    implementation(libs.androidx.material3)
    debugImplementation(libs.androidx.ui.tooling)

    implementation(libs.okhttp)
    implementation(libs.kotlinx.serialization.json)
    implementation(libs.kotlinx.coroutines.android)

    implementation(libs.room.runtime)
    implementation(libs.room.ktx)
    ksp(libs.room.compiler)

    implementation(libs.work.runtime.ktx)
    implementation(libs.play.services.location)
    implementation(libs.zxing.core)
    implementation(libs.androidx.fragment.ktx)

    testImplementation(libs.junit)
    testImplementation(libs.kotlinx.coroutines.test)
}
