import json
import sys
import time
from pywinauto import Desktop


def read_speech():
    fragments = []
    diagnostics = []
    for backend in ('uia', 'win32'):
        try:
            window = Desktop(backend=backend).window(title='NVDA Speech Viewer')
            window.wait('exists visible', timeout=1)
        except Exception as error:
            diagnostics.append(f'{backend}: speech viewer not found ({error})')
            continue

        diagnostics.append(f'{backend}: speech viewer found')
        for control in [window, *window.descendants()]:
            try:
                info = control.element_info
                diagnostics.append(f'{backend}: {info.control_type} {info.class_name} {info.name}')
            except Exception:
                pass
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
    return '\n'.join(dict.fromkeys(fragments)), diagnostics


if __name__ == '__main__':
    if hasattr(sys.stdout, 'reconfigure'):
        sys.stdout.reconfigure(encoding='utf-8')
    timeout = float(sys.argv[1]) if len(sys.argv) > 1 else 20
    deadline = time.time() + timeout
    latest = ''
    diagnostics = []
    while time.time() < deadline:
        latest, diagnostics = read_speech()
        if latest:
            break
        time.sleep(0.25)
    print(json.dumps({'text': latest, 'diagnostics': diagnostics}, ensure_ascii=False))
