package com.example.customer_app

import android.content.Intent
import android.net.Uri
import io.flutter.embedding.android.FlutterActivity
import io.flutter.embedding.engine.FlutterEngine
import io.flutter.plugin.common.MethodChannel

/**
 * `nawy/platform` bridge (no pub packages, project rule): app-support
 * directory for the file-backed SecureStore and opening gateway URLs in the
 * system browser. Replace with path_provider / url_launcher once approved.
 */
class MainActivity : FlutterActivity() {
    override fun configureFlutterEngine(flutterEngine: FlutterEngine) {
        super.configureFlutterEngine(flutterEngine)
        MethodChannel(flutterEngine.dartExecutor.binaryMessenger, "nawy/platform").setMethodCallHandler { call, result ->
            when (call.method) {
                "appSupportDir" -> result.success(filesDir.absolutePath)
                "openUrl" -> {
                    val url = call.argument<String>("url")
                    if (url == null || !(url.startsWith("https://") || url.startsWith("http://"))) {
                        result.success(false)
                    } else {
                        try {
                            startActivity(Intent(Intent.ACTION_VIEW, Uri.parse(url)).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK))
                            result.success(true)
                        } catch (e: Exception) {
                            result.success(false)
                        }
                    }
                }
                else -> result.notImplemented()
            }
        }
    }
}
