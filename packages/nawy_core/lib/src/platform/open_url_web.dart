import 'dart:js_interop';

@JS('window.open')
external JSAny? _open(JSString url, JSString target);

bool openInNewTab(String url) {
  _open(url.toJS, '_blank'.toJS);
  return true;
}
