import Flutter
import UIKit

@main
@objc class AppDelegate: FlutterAppDelegate, FlutterImplicitEngineDelegate {
  override func application(
    _ application: UIApplication,
    didFinishLaunchingWithOptions launchOptions: [UIApplication.LaunchOptionsKey: Any]?
  ) -> Bool {
    return super.application(application, didFinishLaunchingWithOptions: launchOptions)
  }

  func didInitializeImplicitFlutterEngine(_ engineBridge: FlutterImplicitEngineBridge) {
    GeneratedPluginRegistrant.register(with: engineBridge.pluginRegistry)
    // `nawy/platform` bridge (no pub packages, project rule): app-support dir for
    // the file-backed SecureStore, opening URLs and the maps app. takePhoto is
    // not implemented on iOS (needs image_picker), so the app falls back to
    // the signature.
    // Replace with path_provider / url_launcher once the owner approves them.
    guard let registrar = engineBridge.pluginRegistry.registrar(forPlugin: "NawyPlatform") else { return }
    let channel = FlutterMethodChannel(name: "nawy/platform", binaryMessenger: registrar.messenger())
    channel.setMethodCallHandler { call, result in
      switch call.method {
      case "appSupportDir":
        let dir = FileManager.default.urls(for: .applicationSupportDirectory, in: .userDomainMask).first
        if let dir = dir { try? FileManager.default.createDirectory(at: dir, withIntermediateDirectories: true) }
        result(dir?.path)
      case "openMaps":
        guard let args = call.arguments as? [String: Any], let query = args["query"] as? String,
              let encoded = query.addingPercentEncoding(withAllowedCharacters: .urlQueryAllowed),
              let url = URL(string: "https://maps.apple.com/?daddr=\(encoded)") else { result(false); return }
        UIApplication.shared.open(url, options: [:]) { ok in result(ok) }
      case "openUrl":
        guard let args = call.arguments as? [String: Any], let raw = args["url"] as? String,
              let url = URL(string: raw), url.scheme == "https" || url.scheme == "http" else { result(false); return }
        UIApplication.shared.open(url, options: [:]) { ok in result(ok) }
      default:
        result(FlutterMethodNotImplemented)
      }
    }
  }
}
