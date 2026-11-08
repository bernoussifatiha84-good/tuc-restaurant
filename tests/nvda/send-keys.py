"""Send native Windows keys to the foreground Chromium fixture window."""

import ctypes
import json
import sys
import time
from pywinauto import Desktop
from pywinauto.keyboard import send_keys


def focus_browser():
    user32 = ctypes.windll.user32
    candidates = []
    for window in Desktop(backend='win32').windows():
        try:
            title = window.window_text()
            if 'Demande restaurateur TUC' in title and window.is_visible():
                candidates.append(window)
        except Exception:
            continue
    if not candidates:
        raise RuntimeError('Fixture Chromium window not found')
    window = candidates[0]
    handle = window.handle
    if user32.GetForegroundWindow() != handle:
        # A harmless Alt keypress permits SetForegroundWindow from this process.
        send_keys('%')
        time.sleep(0.1)
        user32.SetForegroundWindow(handle)
    deadline = time.monotonic() + 5
    while user32.GetForegroundWindow() != handle and time.monotonic() < deadline:
        time.sleep(0.05)
    if user32.GetForegroundWindow() != handle:
        raise RuntimeError(f'Could not foreground Chromium HWND {handle}')


def main():
    if len(sys.argv) != 2:
        raise SystemExit('Usage: send-keys.py JSON_KEY_ARRAY')
    keys = json.loads(sys.argv[1])
    if not isinstance(keys, list) or not all(isinstance(key, str) for key in keys):
        raise ValueError('Expected a JSON array of key strings')
    focus_browser()
    for key in keys:
        send_keys(key, pause=0.08, with_spaces=True)
        time.sleep(0.3)


if __name__ == '__main__':
    main()
