import sys
import ctypes
from pywinauto import Desktop, mouse
from pywinauto.keyboard import send_keys


def main():
    if len(sys.argv) < 2:
        raise SystemExit('Usage: send-nvda-keys.py KEY_SEQUENCE')
    target_title = 'Demande restaurateur TUC'
    windows = Desktop(backend='uia').windows()
    browser_window = None
    for window in windows:
        try:
            title = window.window_text()
        except Exception:
            continue
        if target_title in title:
            browser_window = window
            break
    if browser_window is None:
        raise RuntimeError(f'Browser window containing {target_title!r} was not found')
    browser_window.set_focus()
    # NVDA Speech Viewer intentionally pauses its transcript when the mouse is over its text area.
    width = ctypes.windll.user32.GetSystemMetrics(0)
    height = ctypes.windll.user32.GetSystemMetrics(1)
    mouse.move(coords=(max(0, width - 12), max(0, height - 12)))
    send_keys(sys.argv[1], pause=0.04)


if __name__ == '__main__':
    main()
