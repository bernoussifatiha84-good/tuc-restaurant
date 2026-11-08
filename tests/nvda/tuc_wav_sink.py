"""CI-only PCM sink for NVDA's WavePlayer API.

This does not synthesize text. It receives the PCM produced by NVDA's bundled
eSpeak NG engine and records the byte offsets of language markers. No Windows
audio endpoint, kernel driver, devcon or pnputil is involved.
"""

from __future__ import annotations

import ctypes
import json
import os
import threading
import wave
from pathlib import Path


class Capture:
    def __init__(self, directory: str):
        self.directory = Path(directory)
        self.directory.mkdir(parents=True, exist_ok=True)
        self.lock = threading.RLock()
        self.counter = 0
        self.current = None
        self.format = (1, 2, 22050)

    @staticmethod
    def _write_json(path: Path, value):
        temporary = path.with_suffix(path.suffix + ".tmp")
        temporary.write_text(json.dumps(value, ensure_ascii=False, indent=2), encoding="utf-8")
        temporary.replace(path)

    def initialized(self, evidence: dict):
        self._write_json(self.directory / "native-espeak-initialized.json", evidence)

    def begin(self, languages: list[dict], sequence: list):
        with self.lock:
            self.finish(complete=False, reason="superseded")
            self.counter += 1
            stem = f"{self.counter:06d}"
            path = self.directory / f"{stem}.wav"
            self.current = {
                "path": path,
                "metadata_path": self.directory / f"{stem}.json",
                "wav": None,
                "channels": 1,
                "sample_width": 2,
                "sample_rate": 22050,
                "bytes": 0,
                "languages": languages,
                "sequence": [repr(item) for item in sequence],
                "text": "".join(item for item in sequence if isinstance(item, str)),
            }
            self._open_current()

    def attach(self, channels: int, sample_width: int, sample_rate: int):
        with self.lock:
            self.format = (channels, sample_width, sample_rate)
            if self.current is not None:
                self._open_current()

    def _open_current(self):
        item = self.current
        channels, sample_width, sample_rate = self.format
        item.update(channels=channels, sample_width=sample_width, sample_rate=sample_rate)
        item["wav"] = wave.open(str(item["path"]), "wb")
        item["wav"].setnchannels(channels)
        item["wav"].setsampwidth(sample_width)
        item["wav"].setframerate(sample_rate)

    def feed(self, data: bytes):
        with self.lock:
            if self.current is None or self.current["wav"] is None:
                # eSpeak's background callback can race with cancel(). PCM after
                # cancellation was not requested speech and must not be recorded.
                return
            self.current["wav"].writeframesraw(data)
            self.current["bytes"] += len(data)

    @property
    def bytes_written(self):
        with self.lock:
            return self.current["bytes"] if self.current else 0

    def finish(self, *, complete: bool, reason: str):
        with self.lock:
            item = self.current
            if item is None:
                return
            self.current = None
            if item["wav"] is not None:
                item["wav"].close()
            byte_rate = item["channels"] * item["sample_width"] * item["sample_rate"]
            for position, language in enumerate(item["languages"]):
                if language["start_byte"] is None:
                    language["start_byte"] = 0
                if language["end_byte"] is None:
                    language["end_byte"] = (
                        item["languages"][position + 1]["start_byte"]
                        if position + 1 < len(item["languages"]) and item["languages"][position + 1]["start_byte"] is not None
                        else item["bytes"]
                    )
                language["duration_seconds"] = (language["end_byte"] - language["start_byte"]) / byte_rate
            self._write_json(item["metadata_path"], {
                "file": item["path"].name,
                "complete": complete,
                "reason": reason,
                "engine": "NVDA bundled eSpeak NG, via nvdaBuiltin.synthDrivers.espeak",
                "channels": item["channels"],
                "sample_width": item["sample_width"],
                "sample_rate": item["sample_rate"],
                "pcm_bytes": item["bytes"],
                "duration_seconds": item["bytes"] / byte_rate,
                "text": item["text"],
                "sequence": item["sequence"],
                "languages": item["languages"],
            })


_capture = None


def get_capture() -> Capture:
    global _capture
    if _capture is None:
        directory = os.environ.get("TUC_NVDA_CAPTURE_DIR")
        if not directory or os.environ.get("GITHUB_ACTIONS") != "true":
            raise RuntimeError("The WAV sink is restricted to GitHub Actions with TUC_NVDA_CAPTURE_DIR set")
        _capture = Capture(directory)
    return _capture


class FileWavePlayer:
    """Small, synchronous implementation of the WavePlayer API used by _espeak."""

    def __init__(self, channels, samplesPerSec, bitsPerSample, **kwargs):
        if bitsPerSample != 16:
            raise RuntimeError(f"Unexpected eSpeak sample width: {bitsPerSample}")
        self.channels = channels
        self.samplesPerSec = samplesPerSec
        self.bitsPerSample = bitsPerSample
        self.capture = get_capture()
        self.capture.attach(channels, bitsPerSample // 8, samplesPerSec)

    def open(self):
        pass

    def feed(self, data, size=None, onDone=None):
        if isinstance(data, bytes):
            pcm = data if size is None else data[:size]
        else:
            length = len(data) if size is None else size
            pcm = ctypes.string_at(data, length)
        self.capture.feed(pcm)
        # This is offline capture, not simulated real-time playback. Completion
        # means the PCM was written, not that a physical device played it.
        if onDone is not None:
            onDone()

    def sync(self):
        pass

    def idle(self):
        self.capture.finish(complete=True, reason="synth-done")

    def stop(self):
        self.capture.finish(complete=False, reason="cancelled")

    def close(self):
        self.capture.finish(complete=False, reason="closed")

    def pause(self, switch):
        pass

    def setVolume(self, **kwargs):
        pass

    def enableTrimmingLeadingSilence(self, enable):
        pass

    def startTrimmingLeadingSilence(self, start=True):
        pass
