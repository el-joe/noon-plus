package com.example.supervisor_app

import android.app.Activity
import android.content.Intent
import android.graphics.Bitmap
import android.net.Uri
import android.provider.MediaStore
import io.flutter.embedding.android.FlutterActivity
import io.flutter.embedding.engine.FlutterEngine
import io.flutter.plugin.common.MethodChannel
import java.io.ByteArrayOutputStream

/**
 * `nawy/platform` bridge (no pub packages, project rule): app-support
 * directory for the file-backed SecureStore, opening URLs, handing an
 * address to the maps app (geo: intent) and a camera photo through
 * ACTION_IMAGE_CAPTURE (preview-size bitmap). Replace with path_provider,
 * url_launcher and image_picker once the owner approves them.
 */
class MainActivity : FlutterActivity() {
    private val photoRequest = 4711
    private var pendingPhoto: MethodChannel.Result? = null

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
                        result.success(startView(Uri.parse(url)))
                    }
                }
                "openMaps" -> {
                    val query = call.argument<String>("query")
                    if (query.isNullOrBlank()) result.success(false) else result.success(startView(Uri.parse("geo:0,0?q=" + Uri.encode(query))))
                }
                "takePhoto" -> {
                    if (pendingPhoto != null) {
                        result.error("busy", "A photo is already being taken", null)
                    } else {
                        try {
                            pendingPhoto = result
                            startActivityForResult(Intent(MediaStore.ACTION_IMAGE_CAPTURE), photoRequest)
                        } catch (e: Exception) {
                            pendingPhoto = null
                            result.success(null)
                        }
                    }
                }
                else -> result.notImplemented()
            }
        }
    }

    private fun startView(uri: Uri): Boolean = try {
        startActivity(Intent(Intent.ACTION_VIEW, uri).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK))
        true
    } catch (e: Exception) {
        false
    }

    @Deprecated("Activity result API needs androidx.activity; FlutterActivity is a plain Activity")
    override fun onActivityResult(requestCode: Int, resultCode: Int, data: Intent?) {
        super.onActivityResult(requestCode, resultCode, data)
        if (requestCode != photoRequest) return
        val result = pendingPhoto ?: return
        pendingPhoto = null
        @Suppress("DEPRECATION")
        val bitmap = if (resultCode == Activity.RESULT_OK) data?.extras?.get("data") as? Bitmap else null
        if (bitmap == null) {
            result.success(null)
            return
        }
        val out = ByteArrayOutputStream()
        bitmap.compress(Bitmap.CompressFormat.JPEG, 90, out)
        result.success(out.toByteArray())
    }
}
