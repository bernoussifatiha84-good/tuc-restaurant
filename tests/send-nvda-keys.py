import ctypes
import sys
import time
from ctypes import wintypes

from pywinauto import mouse
from pywinauto.keyboard import send_keys


def find_window_by_title(fragment):
    user32 = ctypes.windll.user32
    matches = []
    callback_type = ctypes.WINFUNCTYPE(wintypes.BOOL, wintypes.HWND, wintypes.LPARAM)

    def inspect_window(hwnd, _):
        if not user32.IsWindowVisible(hwnd):
            return True
        length = user32.GetWindowTextLengthW(hwnd)
        if not length:
            return True
        title = ctypes.create_unicode_buffer(length + 1)
        user32.GetWindowTextW(hwnd, title, length + 1)
        if fragment in title.value:
            matches.append(hwnd)
            return False
        return True

    callback = callback_type(inspect_window)
    user32.EnumWindows(callback, 0)
    return matches[0] if matches else None


def main():
    if len(sys.argv) < 2:
        raise SystemExit('Usage: send-nvda-keys.py KEY_SEQUENCE')
    target_title = 'Demande restaurateur TUC'
    user32 = ctypes.windll.user32
    browser_window = find_window_by_title(target_title)
    if browser_window is None:
        raise RuntimeError(f'Browser window containing {target_title!r} was not found')
    user32.ShowWindow(browser_window, 9)  # SW_RESTORE
    user32.BringWindowToTop(browser_window)
    user32.SetForegroundWindow(browser_window)
    time.sleep(0.15)
    if user32.GetForegroundWindow() != browser_window:
        raise RuntimeError(f'Could not focus browser window {browser_window}')

    # NVDA Speech Viewer intentionally pauses its transcript when the mouse is over its text area.
    width = user32.GetSystemMetrics(0)
    height = user32.GetSystemMetrics(1)
    mouse.move(coords=(max(0, width - 12), max(0, height - 12)))
    send_keys(sys.argv[1], pause=0.04)


if __name__ == '__main__':
    main()
