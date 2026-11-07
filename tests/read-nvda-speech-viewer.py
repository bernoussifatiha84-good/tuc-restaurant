import json
import sys
import time
from pywinauto import Desktop


def read_speech():
    fragments = []
    for backend in ('uia', 'win32'):
        try:
            window = Desktop(backend=backend).window(title='NVDA Speech Viewer')
            window.wait('exists visible', timeout=1)
        except Exception:
            continue

        for control in [window, *window.descendants()]:
            try:
                value = control.get_value()
                if value:
                    fragments.append(str(value))
            except Exception:
                pass
            try:
                text = control.window_text()
                if text:
                    fragments.append(str(text))
            except Exception:
                pass
        try:
            text_range = window.iface_text.DocumentRange.GetText(-1)
            if text_range:
                fragments.append(str(text_range))
        except Exception:
            pass
    return '\n'.join(dict.fromkeys(fragments))


if __name__ == '__main__':
    timeout = float(sys.argv[1]) if len(sys.argv) > 1 else 20
    deadline = time.time() + timeout
    latest = ''
    while time.time() < deadline:
        latest = read_speech()
        if latest:
            print(json.dumps({'text': latest}, ensure_ascii=False))
            sys.exit(0)
        time.sleep(0.25)
    print(json.dumps({'text': latest}, ensure_ascii=False))
    sys.exit(2 if not latest else 0)
