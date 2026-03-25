"""
PrintScript – native macOS app entry point.

Starts the Flask server on a random free port in a background thread,
waits for it to be ready, then opens a native WKWebView window via pywebview.
"""

import os
import socket
import sys
import threading
import time


def _resource_path(relative: str) -> str:
    """
    Resolve a path relative to the project root.
    Works both in development (plain Python) and in a PyInstaller bundle
    where all data files are extracted to sys._MEIPASS.
    """
    if getattr(sys, 'frozen', False):
        base = sys._MEIPASS  # type: ignore[attr-defined]
    else:
        base = os.path.dirname(os.path.abspath(__file__))
    return os.path.join(base, relative)


def _find_free_port() -> int:
    with socket.socket(socket.AF_INET, socket.SOCK_STREAM) as s:
        s.bind(('127.0.0.1', 0))
        return s.getsockname()[1]


def _wait_for_port(port: int, timeout: float = 15.0) -> bool:
    deadline = time.monotonic() + timeout
    while time.monotonic() < deadline:
        try:
            with socket.create_connection(('127.0.0.1', port), timeout=0.5):
                return True
        except OSError:
            time.sleep(0.05)
    return False


def _error_html(message: str) -> str:
    safe_msg = message.replace('&', '&amp;').replace('<', '&lt;').replace('>', '&gt;')
    return f"""<!DOCTYPE html>
<html lang="nl">
<head>
  <meta charset="UTF-8">
  <style>
    * {{ box-sizing: border-box; margin: 0; padding: 0; }}
    body {{
      font-family: -apple-system, BlinkMacSystemFont, sans-serif;
      background: #1a1d27; color: #e8eaf6;
      display: flex; align-items: center; justify-content: center;
      min-height: 100vh; padding: 32px; text-align: center;
    }}
    .box {{ max-width: 460px; display: flex; flex-direction: column; gap: 16px; }}
    .icon {{ font-size: 3rem; }}
    h2 {{ font-size: 1.2rem; font-weight: 600; }}
    pre {{
      background: #0f1117; border-radius: 8px; padding: 16px;
      text-align: left; font-size: .82rem; white-space: pre-wrap;
      color: #f4a261; line-height: 1.6; border: 1px solid #2e3248;
    }}
    p {{ color: #8b90b0; font-size: .9rem; line-height: 1.5; }}
  </style>
</head>
<body>
  <div class="box">
    <div class="icon">&#9888;&#65039;</div>
    <h2>PrintScript kan niet starten</h2>
    <pre>{safe_msg}</pre>
    <p>Installeer de ontbrekende software en start PrintScript opnieuw.</p>
  </div>
</body>
</html>"""


def main():
    import webview  # noqa: import-outside-toplevel

    # Check LibreOffice before opening the UI window
    try:
        from processor import _find_libreoffice
        _find_libreoffice()
    except RuntimeError as exc:
        webview.create_window(
            'PrintScript – Fout',
            html=_error_html(str(exc)),
            width=540,
            height=380,
            resizable=False,
        )
        webview.start()
        sys.exit(1)

    # Tell app.py where to find templates/static when running as a bundle
    os.environ['PRINTSCRIPT_BASE_DIR'] = _resource_path('.')

    port = _find_free_port()

    # Import Flask app after setting the env var so it picks up the right paths
    from app import app as flask_app  # noqa: import-outside-toplevel

    def _run_flask():
        flask_app.run(
            host='127.0.0.1',
            port=port,
            use_reloader=False,
            threaded=True,
        )

    threading.Thread(target=_run_flask, daemon=True).start()

    if not _wait_for_port(port):
        webview.create_window(
            'PrintScript – Fout',
            html=_error_html('Kon de interne server niet starten.'),
            width=540,
            height=280,
            resizable=False,
        )
        webview.start()
        sys.exit(1)

    webview.create_window(
        'PrintScript',
        url=f'http://127.0.0.1:{port}',
        width=720,
        height=820,
        resizable=True,
        min_size=(620, 680),
    )
    webview.start()


if __name__ == '__main__':
    main()
