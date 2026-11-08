"""Scratchpad synthDrivers/espeak.py — keep NVDA's real bundled eSpeak driver.

Only the WASAPI destination is replaced during driver construction. Speech
processing, language selection, SSML, prosody, character echo and the eSpeak DLL
all remain NVDA's built-in implementation. This file must never be installed in
a user's normal NVDA profile; the workflow uses an isolated CI profile.
"""

from __future__ import annotations

import os

import nvwave
import synthDriverHandler
from logHandler import log
from speech.commands import IndexCommand, LangChangeCommand
from nvdaBuiltin.synthDrivers.espeak import SynthDriver as BuiltinEspeak
from nvdaBuiltin.synthDrivers import _espeak

from .tuc_wav_sink import FileWavePlayer, get_capture


class SynthDriver(BuiltinEspeak):
    name = "espeak"
    description = "eSpeak NG — NVDA bundled engine, CI WAV destination"

    @classmethod
    def check(cls):
        return (
            os.environ.get("GITHUB_ACTIONS") == "true"
            and bool(os.environ.get("TUC_NVDA_CAPTURE_DIR"))
            and BuiltinEspeak.check()
        )

    def __init__(self):
        capture = get_capture()
        previous_player = nvwave.WavePlayer
        nvwave.WavePlayer = FileWavePlayer
        try:
            super().__init__()
        finally:
            nvwave.WavePlayer = previous_player
        self._tucCapture = capture
        self._tucMarker = 1000000
        self._tucLanguages = []
        voices = {key: voice.displayName for key, voice in self.availableVoices.items()}
        if not any(lang and lang.lower().startswith("fr") for lang in self.availableLanguages):
            raise RuntimeError(f"NVDA bundled eSpeak has no French voice: {voices}")
        if not any(lang and lang.lower().startswith("ar") for lang in self.availableLanguages):
            raise RuntimeError(f"NVDA bundled eSpeak has no Arabic voice: {voices}")
        capture.initialized({
            "synth": self.name,
            "class_module": BuiltinEspeak.__module__,
            "engine_version": _espeak.info(),
            "destination": "tests/nvda/tuc_wav_sink.py::FileWavePlayer",
            "default_voice": self.voice,
            "voices": voices,
            "available_languages": sorted(lang for lang in self.availableLanguages if lang),
        })
        log.info("TUC CI: NVDA bundled eSpeak initialized with a file PCM destination; no audio device opened")

    def speak(self, speechSequence):
        # Put private index markers at each language boundary. The built-in
        # driver converts them to eSpeak marks; _onIndexReached gives their PCM
        # offsets. Thus language WAVs are cut from actual engine output, not
        # guessed from Unicode or rendered again by a separate executable.
        self._tucMarker += 1000
        marker_id = self._tucMarker
        languages = []
        augmented = []
        current_language = self.voice
        pending_marker = False
        for item in speechSequence:
            if isinstance(item, LangChangeCommand):
                normalized = self._normalizeLangCommand(item)
                current_language = normalized.lang or self.voice
                pending_marker = True
                augmented.append(item)
            elif isinstance(item, str) and item:
                if pending_marker or not languages:
                    identifier = marker_id + len(languages)
                    languages.append({"id": identifier, "language": current_language, "start_byte": None, "end_byte": None})
                    augmented.append(IndexCommand(identifier))
                    pending_marker = False
                augmented.append(item)
            else:
                augmented.append(item)
        self._tucLanguages = languages
        # A final private mark closes the last language even when eSpeak emits
        # trailing callbacks after the last audible sample.
        end_id = marker_id + len(languages)
        languages.append({"id": end_id, "language": "boundary", "start_byte": None, "end_byte": None})
        augmented.append(IndexCommand(end_id))
        self._tucCapture.begin(languages, speechSequence)
        super().speak(augmented)

    def _onIndexReached(self, index):
        if index is not None and index >= self._tucMarker:
            with self._tucCapture.lock:
                offset = self._tucCapture.bytes_written
                for position, language in enumerate(self._tucLanguages):
                    if language["id"] == index:
                        language["start_byte"] = offset
                        if position:
                            self._tucLanguages[position - 1]["end_byte"] = offset
                        break
            return
        if index is None:
            with self._tucCapture.lock:
                if self._tucLanguages:
                    self._tucLanguages[-1]["end_byte"] = self._tucCapture.bytes_written
        super()._onIndexReached(index)

    def terminate(self):
        try:
            super().terminate()
        finally:
            self._tucCapture.finish(complete=False, reason="terminated")
