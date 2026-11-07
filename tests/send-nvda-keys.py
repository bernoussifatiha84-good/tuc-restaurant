"""Send real Windows keyboard input to the NVDA browser window without UIA enumeration."""

import ctypes
import sys
import time
from ctypes import wintypes

user32 = ctypes.windll.user32
KEYEVENTF_KEYUP = 0x0002
KEYEVENTF_UNICODE = 0x0004
INPUT_KEYBOARD = 1
SW_RESTORE = 9


class KEYBDINPUT(ctypes.Structure):
    _fields_ = [
        ('wVk', wintypes.WORD),
        ('wScan', wintypes.WORD),
        ('dwFlags', wintypes.DWORD),
        ('time', wintypes.DWORD),
        ('dwExtraInfo', ctypes.c_size_t),
    ]


class INPUT_UNION(ctypes.Union):
    _fields_ = [('ki', KEYBDINPUT)]


class INPUT(ctypes.Structure):
    _fields_ = [('type', wintypes.DWORD), ('union', INPUT_UNION)]


def find_browser_window():
    target_title = 'Demande restaurateur TUC'
    matches = []
    callback_type = ctypes.WINFUNCTYPE(wintypes.BOOL, wintypes.HWND, wintypes.LPARAM)

    def inspect(hwnd, _):
        if not user32.IsWindowVisible(hwnd):
            return True
        length = user32.GetWindowTextLengthW(hwnd)
        if not length:
            return True
        title = ctypes.create_unicode_buffer(length + 1)
        user32.GetWindowTextW(hwnd, title, length + 1)
        if target_title in title.value:
            matches.append(hwnd)
            return False
        return True

    callback = callback_type(inspect)
    user32.EnumWindows(callback, 0)
    if not matches:
        raise RuntimeError(f'Browser window containing {target_title!r} was not found')
    return matches[0]


def focus_browser(hwnd):
    user32.ShowWindow(hwnd, SW_RESTORE)
    foreground = user32.GetForegroundWindow()
    foreground_thread = user32.GetWindowThreadProcessId(foreground, None) if foreground else 0
    target_thread = user32.GetWindowThreadProcessId(hwnd, None)
    current_thread = user32.GetCurrentThreadId()
    attached = []
    for thread in {foreground_thread, target_thread} - {0, current_thread}:
        if user32.AttachThreadInput(current_thread, thread, True):
            attached.append(thread)
    try:
        user32.BringWindowToTop(hwnd)
        user32.SetForegroundWindow(hwnd)
        user32.SetActiveWindow(hwnd)
        user32.SetFocus(hwnd)
    finally:
        for thread in attached:
            user32.AttachThreadInput(current_thread, thread, False)
    time.sleep(0.12)
    foreground = user32.GetForegroundWindow()
    foreground_pid = wintypes.DWORD()
    target_pid = wintypes.DWORD()
    if foreground:
        user32.GetWindowThreadProcessId(foreground, ctypes.byref(foreground_pid))
    user32.GetWindowThreadProcessId(hwnd, ctypes.byref(target_pid))
    if foreground_pid.value != target_pid.value:
        raise RuntimeError(f'Browser did not become foreground (target pid {target_pid.value}, foreground pid {foreground_pid.value})')


def send_inputs(events):
    inputs = (INPUT * len(events))()
    for index, (vk, scan, flags) in enumerate(events):
        inputs[index].type = INPUT_KEYBOARD
        inputs[index].union.ki = KEYBDINPUT(vk, scan, flags, 0, 0)
    sent = user32.SendInput(len(inputs), inputs, ctypes.sizeof(INPUT))
    if sent != len(inputs):
        raise ctypes.WinError()


def send_key(vk, modifiers=()):
    events = [(modifier, 0, 0) for modifier in modifiers]
    events.extend(((vk, 0, 0), (vk, 0, KEYEVENTF_KEYUP)))
    events.extend((modifier, 0, KEYEVENTF_KEYUP) for modifier in reversed(modifiers))
    send_inputs(events)


def send_unicode(text):
    events = []
    for character in text:
        codepoint = ord(character)
        events.extend(((0, codepoint, KEYEVENTF_UNICODE), (0, codepoint, KEYEVENTF_UNICODE | KEYEVENTF_KEYUP)))
    if events:
        send_inputs(events)


def move_pointer_away():
    width = user32.GetSystemMetrics(0)
    height = user32.GetSystemMetrics(1)
    user32.SetCursorPos(max(0, width - 12), max(0, height - 12))


def main():
    if len(sys.argv) < 2:
        raise SystemExit('Usage: send-nvda-keys.py --focus | KEY_SEQUENCE')
    hwnd = find_browser_window()
    focus_browser(hwnd)
    move_pointer_away()
    if sys.argv[1] == '--focus':
        return

    sequence = sys.argv[1]
    if sequence == '{TAB}':
        send_key(0x09)
    elif sequence == '+{TAB}':
        send_key(0x09, (0x10,))
    elif sequence == '{ENTER}':
        send_key(0x0D)
    elif sequence == '^a':
        send_key(0x41, (0x11,))
    else:
        send_unicode(sequence)


if __name__ == '__main__':
    main()
