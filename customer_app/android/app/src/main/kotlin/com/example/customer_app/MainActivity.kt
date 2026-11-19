package com.example.customer_app

import android.content.Intent
import io.flutter.embedding.android.FlutterActivity
import io.flutter.embedding.engine.FlutterEngine
import io.flutter.plugin.common.MethodChannel

/**
 * `nawy/platform` channel, kept only for the `nawy://` deep link that brings
 * the Google / Apple web sign-in back to the app (`initialLink` on a cold
 * start, `deepLink` pushed to Dart while running): `url_launcher` cannot
 * receive links. Opening URLs and the app-support directory moved to
 * `url_launcher` / `path_provider` (owner-review D16).
 */
class MainActivity : FlutterActivity() {
    private var channel: MethodChannel? = null
    private var initialLink: String? = null

    override fun configureFlutterEngine(flutterEngine: FlutterEngine) {
        super.configureFlutterEngine(flutterEngine)
        initialLink = nawyLink(intent)
        val ch = MethodChannel(flutterEngine.dartExecutor.binaryMessenger, "nawy/platform")
        channel = ch
        ch.setMethodCallHandler { call, result ->
            when (call.method) {
                "initialLink" -> {
                    result.success(initialLink)
                    initialLink = null
                }
                else -> result.notImplemented()
            }
        }
    }

    override fun onNewIntent(intent: Intent) {
        super.onNewIntent(intent)
        setIntent(intent)
        val link = nawyLink(intent) ?: return
        channel?.invokeMethod("deepLink", link) ?: run { initialLink = link }
    }

    private fun nawyLink(intent: Intent?): String? {
        val data = intent?.data ?: return null
        return if (intent.action == Intent.ACTION_VIEW && data.scheme == "nawy") data.toString() else null
    }
}
