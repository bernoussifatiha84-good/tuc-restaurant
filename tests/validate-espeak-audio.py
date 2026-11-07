#!/usr/bin/env python3
"""Check that offline eSpeak NG French and Arabic WAV samples contain audio."""

from __future__ import annotations

import argparse
import array
import json
import sys
import wave
from pathlib import Path


def inspect_sample(label: str, path: Path) -> dict[str, object]:
    with wave.open(str(path), "rb") as audio:
        channels = audio.getnchannels()
        sample_width = audio.getsampwidth()
        sample_rate = audio.getframerate()
        frames = audio.getnframes()
        pcm = audio.readframes(frames)

    duration = frames / sample_rate if sample_rate else 0
    if channels < 1 or sample_rate < 8000 or sample_width != 2:
        raise ValueError(f"{label}: unexpected WAV format ({channels} channels, {sample_rate} Hz, {sample_width * 8}-bit)")
    if duration < 0.25:
        raise ValueError(f"{label}: WAV is too short ({duration:.2f} seconds)")
    samples = array.array("h")
    samples.frombytes(pcm)
    if sys.byteorder != "little":
        samples.byteswap()
    peak = max((abs(value) for value in samples), default=0)
    if peak == 0:
        raise ValueError(f"{label}: all samples are silent")

    return {
        "language": label,
        "file": str(path),
        "sample_rate_hz": sample_rate,
        "channels": channels,
        "duration_seconds": round(duration, 2),
        "peak_amplitude": peak,
        "file_bytes": path.stat().st_size,
    }


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--french", required=True, type=Path)
    parser.add_argument("--arabic", required=True, type=Path)
    args = parser.parse_args()
    results = [inspect_sample("français", args.french), inspect_sample("arabe", args.arabic)]
    print(json.dumps(results, ensure_ascii=False))
    return 0


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except Exception as exc:
        print(f"eSpeak WAV validation failed: {exc}", file=sys.stderr)
        raise SystemExit(1)
