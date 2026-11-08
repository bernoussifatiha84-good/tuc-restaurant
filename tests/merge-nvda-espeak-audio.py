#!/usr/bin/env python3
"""Merge headless eSpeak WAV chunks rendered from NVDA speech sequences."""

from __future__ import annotations

import argparse
import json
import re
import sys
import wave
from collections import defaultdict
from pathlib import Path


CHUNK_PATTERN = re.compile(r"^(\d+)-(en-gb|fr-fr|ar)\.wav$")


def read_chunk(path: Path) -> tuple[tuple[int, int, int], bytes]:
    with wave.open(str(path), "rb") as wav:
        params = wav.getparams()
        pcm = wav.readframes(params.nframes)
    if params.comptype != "NONE":
        raise ValueError(f"Unexpected compressed audio in {path.name}")
    return (params.nchannels, params.sampwidth, params.framerate), pcm


def write_wave(path: Path, fmt: tuple[int, int, int], pcm: bytes) -> None:
    channels, sample_width, sample_rate = fmt
    with wave.open(str(path), "wb") as wav:
        wav.setnchannels(channels)
        wav.setsampwidth(sample_width)
        wav.setframerate(sample_rate)
        wav.writeframes(pcm)


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--chunks", required=True, type=Path)
    parser.add_argument("--output", required=True, type=Path)
    args = parser.parse_args()
    args.output.mkdir(parents=True, exist_ok=True)

    chunks = []
    per_language = defaultdict(list)
    for path in sorted(args.chunks.glob("*.wav")):
        match = CHUNK_PATTERN.match(path.name)
        if not match:
            continue
        sequence = int(match.group(1))
        voice = match.group(2)
        fmt, pcm = read_chunk(path)
        chunks.append((sequence, voice, path, fmt, pcm))
        language = "fr" if voice.startswith("fr") else "ar" if voice == "ar" else "en"
        per_language[language].append((sequence, path, fmt, pcm))

    missing = {language for language in ("fr", "ar") if not per_language[language]}
    if missing:
        raise ValueError(f"NVDA did not send speech in required language(s): {', '.join(sorted(missing))}")

    formats = {chunk[3] for chunk in chunks}
    if len(formats) != 1:
        raise ValueError(f"eSpeak output formats differ between chunks: {sorted(formats)}")
    fmt = next(iter(formats))
    channels, sample_width, sample_rate = fmt
    silence_frames = max(1, int(sample_rate * 0.08))
    silence = b"\x00" * silence_frames * channels * sample_width

    all_pcm = bytearray()
    for sequence, voice, path, chunk_fmt, pcm in chunks:
        if all_pcm:
            all_pcm.extend(silence)
        all_pcm.extend(pcm)
    all_path = args.output / "nvda-espeak-all.wav"
    write_wave(all_path, fmt, bytes(all_pcm))

    results = []
    for language in ("fr", "ar"):
        pcm = bytearray()
        for sequence, path, chunk_fmt, frames in per_language[language]:
            if pcm:
                pcm.extend(silence)
            pcm.extend(frames)
        output_path = args.output / f"nvda-espeak-{language}.wav"
        write_wave(output_path, fmt, bytes(pcm))
        duration = len(pcm) / (sample_rate * channels * sample_width)
        peak_byte = max(pcm, default=0)
        if duration < 0.25 or peak_byte == 0:
            raise ValueError(f"NVDA {language} WAV is empty or silent ({duration:.2f}s, peak byte {peak_byte})")
        results.append(
            {
                "language": "français" if language == "fr" else "arabe",
                "voice_chunks": len(per_language[language]),
                "file": str(output_path),
                "sample_rate_hz": sample_rate,
                "channels": channels,
                "duration_seconds": round(duration, 2),
                "peak_byte": peak_byte,
                "file_bytes": output_path.stat().st_size,
            },
        )

    write_manifest = args.output / "nvda-espeak-manifest.json"
    write_manifest.write_text(
        json.dumps(
            {
                "source": "NVDA speech sequences through the CI eSpeakWav synthesizer",
                "chunks": len(chunks),
                "languages": {language: len(items) for language, items in sorted(per_language.items())},
                "samples": results,
            },
            ensure_ascii=False,
            indent=2,
        ),
        encoding="utf-8",
    )
    print(json.dumps(results, ensure_ascii=False))
    return 0


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except Exception as exc:
        print(f"NVDA eSpeak WAV merge failed: {exc}", file=sys.stderr)
        raise SystemExit(1)
