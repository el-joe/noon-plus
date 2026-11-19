package com.example.delivery_app

import android.Manifest
import android.content.ActivityNotFoundException
import android.content.ContentValues
import android.content.Intent
import android.content.pm.PackageManager
import android.net.Uri
import android.os.Build
import android.os.Environment
import android.provider.MediaStore
import io.flutter.embedding.android.FlutterActivity
import io.flutter.embedding.engine.FlutterEngine
import io.flutter.plugin.common.MethodChannel
import java.io.File

// The hand-written `nawy/platform` channel was replaced by url_launcher,
// path_provider and image_picker (owner-review D16). Two small channels
// remain for what no installed package does:
// - `nawy.delivery/files`: save a payslip PDF to the public Downloads folder
//   (MediaStore, Android 10+) and open it in the PDF viewer;
// - `nawy.delivery/permissions`: ask for POST_NOTIFICATIONS (Android 13+) so
//   the ongoing "Delivery in progress" notification of the location
//   foreground service is shown.
class MainActivity : FlutterActivity() {
    override fun configureFlutterEngine(flutterEngine: FlutterEngine) {
        super.configureFlutterEngine(flutterEngine)
        val messenger = flutterEngine.dartExecutor.binaryMessenger

        MethodChannel(messenger, "nawy.delivery/files").setMethodCallHandler { call, result ->
            when (call.method) {
                "saveToDownloads" -> {
                    val name = call.argument<String>("name") ?: "file.pdf"
                    val bytes = call.argument<ByteArray>("bytes")
                    val mime = call.argument<String>("mime") ?: "application/pdf"
                    if (bytes == null) {
                        result.error("no_bytes", "Nothing to save", null)
                    } else {
                        try {
                            result.success(saveToDownloads(name.replace(Regex("[^A-Za-z0-9._-]"), "_"), bytes, mime))
                        } catch (e: Exception) {
                            result.error("save_failed", e.message, null)
                        }
                    }
                }
                "open" -> {
                    val uri = call.argument<String>("uri")
                    val mime = call.argument<String>("mime") ?: "application/pdf"
                    if (uri == null) {
                        result.success(false)
                    } else {
                        try {
                            val intent = Intent(Intent.ACTION_VIEW)
                                .setDataAndType(Uri.parse(uri), mime)
                                .addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION or Intent.FLAG_ACTIVITY_NEW_TASK)
                            startActivity(intent)
                            result.success(true)
                        } catch (e: ActivityNotFoundException) {
                            result.success(false)
                        }
                    }
                }
                else -> result.notImplemented()
            }
        }

        MethodChannel(messenger, "nawy.delivery/permissions").setMethodCallHandler { call, result ->
            when (call.method) {
                "requestNotifications" -> {
                    if (Build.VERSION.SDK_INT < Build.VERSION_CODES.TIRAMISU) {
                        result.success(true)
                    } else if (checkSelfPermission(Manifest.permission.POST_NOTIFICATIONS) == PackageManager.PERMISSION_GRANTED) {
                        result.success(true)
                    } else {
                        requestPermissions(arrayOf(Manifest.permission.POST_NOTIFICATIONS), NOTIFICATIONS_REQUEST)
                        result.success(false)
                    }
                }
                else -> result.notImplemented()
            }
        }
    }

    /** Download/Nawy/<name> through MediaStore (no storage permission); the
     * app's own Downloads folder before Android 10. */
    private fun saveToDownloads(name: String, bytes: ByteArray, mime: String): Map<String, String?> {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.Q) {
            val values = ContentValues().apply {
                put(MediaStore.MediaColumns.DISPLAY_NAME, name)
                put(MediaStore.MediaColumns.MIME_TYPE, mime)
                put(MediaStore.MediaColumns.RELATIVE_PATH, Environment.DIRECTORY_DOWNLOADS + "/Nawy")
            }
            val uri = contentResolver.insert(MediaStore.Downloads.EXTERNAL_CONTENT_URI, values)
                ?: throw IllegalStateException("MediaStore refused the file")
            contentResolver.openOutputStream(uri)?.use { it.write(bytes) }
                ?: throw IllegalStateException("Cannot write the file")
            return mapOf("uri" to uri.toString(), "path" to Environment.DIRECTORY_DOWNLOADS + "/Nawy/" + name)
        }
        val dir = getExternalFilesDir(Environment.DIRECTORY_DOWNLOADS) ?: filesDir
        val file = File(dir, name)
        file.writeBytes(bytes)
        return mapOf("uri" to null, "path" to file.absolutePath)
    }

    companion object {
        private const val NOTIFICATIONS_REQUEST = 4711
    }
}
