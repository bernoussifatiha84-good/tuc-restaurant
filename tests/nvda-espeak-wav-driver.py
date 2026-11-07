"""NVDA scratchpad synthesizer for headless CI audio capture.

It receives NVDA's actual speech sequences, applies their language-change commands,
and asks eSpeak NG to render each utterance to a WAV file instead of a sound device.
This is a CI adapter, not NVDA's built-in eSpeak driver.
"""

from __future__ import annotations

from collections import OrderedDict
import os
from pathlib import Path
import subprocess
import threading

import synthDriverHandler
from speech.commands import IndexCommand, LangChangeCommand
from synthDriverHandler import VoiceInfo, synthDoneSpeaking, synthIndexReached


class SynthDriver(synthDriverHandler.SynthDriver):
    name = "espeakWav"
    description = "eSpeak NG WAV capture (headless CI)"
    supportedSettings = (synthDriverHandler.SynthDriver.VoiceSetting(),)
    supportedCommands = {IndexCommand, LangChangeCommand}
    supportedNotifications = {synthIndexReached, synthDoneSpeaking}
    _availableVoices = OrderedDict(
        (
            ("en-gb", VoiceInfo("en-gb", "English", "en-gb")),
            ("fr-fr", VoiceInfo("fr-fr", "French", "fr-fr")),
            ("ar", VoiceInfo("ar", "Arabic", "ar")),
        ),
    )

    @classmethod
    def check(cls):
        return bool(os.environ.get("TUC_ESPEAK_EXE") and os.environ.get("TUC_NVDA_SPEECH_DIR"))

    def __init__(self):
        super().__init__()
        self._exe = os.environ["TUC_ESPEAK_EXE"]
        self._speechDir = Path(os.environ["TUC_NVDA_SPEECH_DIR"])
        self._speechDir.mkdir(parents=True, exist_ok=True)
        self._voice = "en-gb"
        self._counter = 0
        self._lock = threading.Lock()

    def _get_voice(self):
        return self._voice

    def _set_voice(self, voice):
        if voice in self._availableVoices:
            self._voice = voice

    def _get_language(self):
        return self._availableVoices[self._voice].language

    @staticmethod
    def _voiceForLanguage(language, fallback):
        normalized = (language or "").replace("_", "-").lower()
        if normalized.startswith("fr"):
            return "fr-fr"
        if normalized.startswith("ar"):
            return "ar"
        if normalized.startswith("en"):
            return "en-gb"
        return fallback

    def speak(self, speechSequence):
        currentVoice = self._voice
        segments = []
        text = []
        indices = []

        def flushText():
            joined = "".join(text).strip()
            if joined:
                segments.append((currentVoice, joined))
            text.clear()

        for item in speechSequence:
            if isinstance(item, LangChangeCommand):
                flushText()
                currentVoice = self._voiceForLanguage(item.lang, currentVoice)
            elif isinstance(item, IndexCommand):
                flushText()
                indices.append(item.index)
            elif isinstance(item, str):
                text.append(item)
        flushText()

        with self._lock:
            for voice, utterance in segments:
                self._counter += 1
                sequence = self._counter
                textPath = self._speechDir / f"{sequence:06d}-{voice}.txt"
                wavPath = self._speechDir / f"{sequence:06d}-{voice}.wav"
                textPath.write_text(utterance, encoding="utf-8")
                subprocess.run(
                    [self._exe, "-v", voice, "-s", "150", "-f", str(textPath), "-w", str(wavPath)],
                    check=True,
                    stdout=subprocess.PIPE,
                    stderr=subprocess.PIPE,
                    timeout=20,
                )
                if not wavPath.exists() or wavPath.stat().st_size <= 44:
                    raise RuntimeError(f"eSpeak NG produced no audio for NVDA voice {voice}")

        for index in indices:
            synthIndexReached.notify(synth=self, index=index)
        synthDoneSpeaking.notify(synth=self)

    def cancel(self):
        # Speech is rendered synchronously and never sent to a playback device.
        return

    def pause(self, switch):
        return

    def terminate(self):
        super().terminate()
