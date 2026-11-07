"""Focus Chromium and send a small batch of real Windows keyboard input to NVDA."""

import ctypes
import json
import sys
import time
from pywinauto import Desktop, mouse
from pywinauto.keyboard import send_keys


def focus_browser_window():
    target_title = 'Demande restaurateur TUC'
    for window in Desktop(backend='uia').windows():
        try:
            if target_title in window.window_text():
                window.set_focus()
                width = ctypes.windll.user32.GetSystemMetrics(0)
                height = ctypes.windll.user32.GetSystemMetrics(1)
                mouse.move(coords=(max(0, width - 12), max(0, height - 12)))
                return
        except Exception:
            continue
    raise RuntimeError(f'Browser window containing {target_title!r} was not found')


def main():
    if len(sys.argv) != 2:
        raise SystemExit('Usage: send-nvda-keys.py JSON_KEY_SEQUENCE_ARRAY')
    sequence = json.loads(sys.argv[1])
    if not isinstance(sequence, list) or not all(isinstance(key, str) for key in sequence):
        raise ValueError('Keyboard input must be a JSON array of strings')
    focus_browser_window()
    for key in sequence:
        send_keys(key, pause=0.04)
        time.sleep(0.2)


if __name__ == '__main__':
    main()
