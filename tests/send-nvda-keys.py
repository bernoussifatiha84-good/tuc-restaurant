import ctypes
import sys
from pywinauto import Desktop, mouse
from pywinauto.keyboard import send_keys


def move_mouse_away_from_speech_viewer():
    width = ctypes.windll.user32.GetSystemMetrics(0)
    height = ctypes.windll.user32.GetSystemMetrics(1)
    mouse.move(coords=(max(0, width - 12), max(0, height - 12)))


def focus_browser_window():
    target_title = 'Demande restaurateur TUC'
    windows = Desktop(backend='win32').windows()
    titled_windows = []
    for window in windows:
        try:
            title = window.window_text()
        except Exception:
            continue
        if title:
            titled_windows.append((window, title))
    browser = next((window for window, title in titled_windows if target_title in title), None)
    if browser is None:
        browser = next(
            (window for window, title in titled_windows if 'chrome' in title.lower() or 'chromium' in title.lower()),
            None,
        )
    if browser is None:
        visible_titles = [title for _, title in titled_windows]
        raise RuntimeError(f'Browser window not found; visible window titles: {visible_titles!r}')
    browser.set_focus()
    move_mouse_away_from_speech_viewer()


def main():
    if len(sys.argv) < 2:
        raise SystemExit('Usage: send-nvda-keys.py --focus | KEY_SEQUENCE')
    if sys.argv[1] == '--focus':
        focus_browser_window()
        return

    # Focus the browser once per test; avoid repeatedly enumerating windows while typing.
    move_mouse_away_from_speech_viewer()
    send_keys(sys.argv[1], pause=0.04)


if __name__ == '__main__':
    main()
