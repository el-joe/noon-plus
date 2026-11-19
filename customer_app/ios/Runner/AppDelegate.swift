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
    // `nawy/platform` channel, kept only for `nawy://` links (Google / Apple web
    // sign-in coming back): url_launcher cannot receive links. Opening URLs and
    // the app-support directory use url_launcher / path_provider (D16).
    guard let registrar = engineBridge.pluginRegistry.registrar(forPlugin: "NawyPlatform") else { return }
    let channel = FlutterMethodChannel(name: "nawy/platform", binaryMessenger: registrar.messenger())
    NawyLinks.shared.channel = channel
    // `nawy://` links (Google / Apple web sign-in coming back) from the scene.
    registrar.addSceneDelegate(NawyLinks.shared)
    channel.setMethodCallHandler { call, result in
      switch call.method {
      case "initialLink":
        result(NawyLinks.shared.initialLink)
        NawyLinks.shared.initialLink = nil
      default:
        result(FlutterMethodNotImplemented)
      }
    }
  }
}

/// Hands `nawy://` URLs to Dart over `nawy/platform`: kept as `initialLink`
/// on a cold start, pushed as `deepLink` while the app runs.
class NawyLinks: NSObject, FlutterSceneLifeCycleDelegate {
  static let shared = NawyLinks()
  var channel: FlutterMethodChannel?
  var initialLink: String?

  func scene(_ scene: UIScene, willConnectTo session: UISceneSession, options connectionOptions: UIScene.ConnectionOptions?) -> Bool {
    if let url = connectionOptions?.urlContexts.first?.url, url.scheme == "nawy" {
      initialLink = url.absoluteString
    }
    return false
  }

  func scene(_ scene: UIScene, openURLContexts URLContexts: Set<UIOpenURLContext>) -> Bool {
    var handled = false
    for context in URLContexts where context.url.scheme == "nawy" {
      handled = true
      if let channel = channel {
        channel.invokeMethod("deepLink", arguments: context.url.absoluteString)
      } else {
        initialLink = context.url.absoluteString
      }
    }
    return handled
  }
}
