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
    for window in Desktop(backend='uia').windows():
        try:
            if target_title in window.window_text():
                window.set_focus()
                move_mouse_away_from_speech_viewer()
                return
        except Exception:
            continue
    raise RuntimeError(f'Browser window containing {target_title!r} was not found')


def main():
    if len(sys.argv) < 2:
        raise SystemExit('Usage: send-nvda-keys.py --focus | KEY_SEQUENCE')
    if sys.argv[1] == '--focus':
        focus_browser_window()
        return

    # Focus the browser once per test; avoid repeatedly enumerating UIA windows while typing.
    move_mouse_away_from_speech_viewer()
    send_keys(sys.argv[1], pause=0.04)


if __name__ == '__main__':
    main()
