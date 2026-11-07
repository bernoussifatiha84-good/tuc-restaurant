import sys
from pywinauto import Desktop
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
    send_keys(sys.argv[1], pause=0.04)


if __name__ == '__main__':
    main()
