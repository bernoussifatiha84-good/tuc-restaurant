#!/usr/bin/env python3
"""Fail closed unless NVDA's bundled eSpeak produced the expected PCM/languages."""

from __future__ import annotations

import argparse
import hashlib
import json
import math
import struct
import wave
from collections import defaultdict
from pathlib import Path


REQUIRED_FRENCH = (
    "Nom du restaurant",
    "En français ou en arabe",
    "Photo du menu",
    "JPEG, PNG ou WebP",
    "Demander l’accompagnement",
    "Demande enregistrée",
    "Continuer dans WhatsApp",
    "nouvel onglet",
)
ARABIC_NAME = "مطعم الاختبار"


def normalized(text: str) -> str:
    # NVDA's bundled eSpeak pronounces WhatsApp and WebP as split words.
    # These are engine pronunciation differences, not missing announcements.
    return " ".join(text.casefold().replace("whats app", "whatsapp").replace("web p", "webp").split())


def pcm_stats(pcm: bytes, sample_width: int) -> dict:
    if sample_width != 2 or len(pcm) % 2:
        raise ValueError(f"Expected 16-bit PCM, got width={sample_width}, bytes={len(pcm)}")
    samples = struct.unpack(f"<{len(pcm) // 2}h", pcm)
    peak = max((abs(sample) for sample in samples), default=0)
    rms = math.sqrt(sum(sample * sample for sample in samples) / len(samples)) if samples else 0
    return {"peak": peak, "rms": round(rms, 2)}


def read_utterance(directory: Path, metadata: dict) -> tuple[dict, bytes]:
    if metadata.get("engine") != "NVDA bundled eSpeak NG, via nvdaBuiltin.synthDrivers.espeak":
        raise ValueError(f"Untrusted engine in {metadata.get('file')}")
    with wave.open(str(directory / metadata["file"]), "rb") as source:
        fmt = (source.getnchannels(), source.getsampwidth(), source.getframerate(), source.getcomptype())
        pcm = source.readframes(source.getnframes())
    if fmt[:3] != (metadata["channels"], metadata["sample_width"], metadata["sample_rate"]) or fmt[3] != "NONE":
        raise ValueError(f"WAV/metadata format mismatch: {metadata['file']}")
    if len(pcm) != metadata["pcm_bytes"]:
        raise ValueError(f"WAV/metadata length mismatch: {metadata['file']}")
    return fmt, pcm


def write_wav(path: Path, fmt: tuple, pcm: bytes):
    with wave.open(str(path), "wb") as output:
        output.setnchannels(fmt[0])
        output.setsampwidth(fmt[1])
        output.setframerate(fmt[2])
        output.writeframes(pcm)


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--capture", type=Path, required=True)
    parser.add_argument("--output", type=Path, required=True)
    args = parser.parse_args()
    args.output.mkdir(parents=True, exist_ok=True)

    initialization = json.loads((args.capture / "native-espeak-initialized.json").read_text(encoding="utf-8"))
    if initialization.get("synth") != "espeak" or initialization.get("class_module") != "nvdaBuiltin.synthDrivers.espeak":
        raise ValueError(f"Not NVDA's built-in eSpeak driver: {initialization}")
    if initialization.get("destination") != "tests/nvda/tuc_wav_sink.py::FileWavePlayer":
        raise ValueError(f"Unexpected audio destination: {initialization}")

    complete = []
    formats = set()
    transcript = []
    language_pcm = defaultdict(bytearray)
    language_chunks = defaultdict(int)
    for path in sorted(args.capture.glob("[0-9]*.json")):
        metadata = json.loads(path.read_text(encoding="utf-8"))
        transcript.append(metadata["text"])
        if not metadata.get("complete"):
            continue
        fmt, pcm = read_utterance(args.capture, metadata)
        formats.add(fmt)
        stats = pcm_stats(pcm, fmt[1])
        if not pcm or stats["peak"] < 100:
            # NVDA can complete an empty/cancelled utterance (e.g. a mode change
            # represented by a sound rather than speech). It is not language proof.
            continue
        complete.append({"file": metadata["file"], "text": metadata["text"], "bytes": len(pcm), **stats})
        for language in metadata["languages"]:
            start, end = language["start_byte"], language["end_byte"]
            if start is None or end is None or not 0 <= start <= end <= len(pcm):
                raise ValueError(f"Invalid language PCM range in {metadata['file']}: {language}")
            if start == end:
                # A cancelled/empty utterance can contain a marker but no PCM.
                continue
            code = language["language"].lower().replace("_", "-")
            family = "fr" if code.startswith("fr") else "ar" if code.startswith("ar") else "en" if code.startswith("en") else "other"
            chunk = pcm[start:end]
            chunk_stats = pcm_stats(chunk, fmt[1])
            if chunk_stats["peak"] < 100:
                raise ValueError(f"NVDA {family} language segment is silent: {metadata['file']} {language}")
            if language_pcm[family]:
                language_pcm[family].extend(b"\x00" * (fmt[0] * fmt[1] * fmt[2] // 20))
            language_pcm[family].extend(chunk)
            language_chunks[family] += 1

    if len(formats) != 1:
        raise ValueError(f"Missing or inconsistent PCM formats: {formats}")
    fmt = next(iter(formats))
    missing_audio = {"fr", "ar"} - language_pcm.keys()
    if missing_audio:
        raise ValueError(f"NVDA did not emit PCM for languages: {sorted(missing_audio)}")

    text = normalized("\n".join(transcript))
    missing_text = [phrase for phrase in REQUIRED_FRENCH if normalized(phrase) not in text]
    if missing_text:
        raise ValueError(f"NVDA did not speak required form announcements: {missing_text}")
    missing_characters = sorted({character for character in ARABIC_NAME if not character.isspace() and character not in text})
    if missing_characters:
        raise ValueError(f"NVDA did not echo Arabic input characters: {missing_characters}")
    if "not supported" in text or "non pris en charge" in text:
        raise ValueError("NVDA announced an unsupported language")

    samples = []
    for language in ("fr", "ar"):
        pcm = bytes(language_pcm[language])
        path = args.output / f"nvda-builtin-espeak-{language}.wav"
        write_wav(path, fmt, pcm)
        stats = pcm_stats(pcm, fmt[1])
        duration = len(pcm) / (fmt[0] * fmt[1] * fmt[2])
        if duration < 0.25 or stats["peak"] < 100 or stats["rms"] < 10:
            raise ValueError(f"Invalid {language} capture: {duration=}, {stats}")
        samples.append({
            "file": path.name,
            "language": language,
            "chunks": language_chunks[language],
            "duration_seconds": round(duration, 3),
            **stats,
            "sha256": hashlib.sha256(pcm).hexdigest(),
        })

    manifest = {
        "source": "PCM fed by NVDA 2026.3 built-in eSpeak NG to nvwave.WavePlayer; WASAPI replaced by CI file sink",
        "not_a_sound_card_loopback": True,
        "initialization": initialization,
        "completed_utterances": len(complete),
        "language_chunks": dict(sorted(language_chunks.items())),
        "required_french_announcements": list(REQUIRED_FRENCH),
        "arabic_input": ARABIC_NAME,
        "samples": samples,
        "utterances": complete,
    }
    (args.output / "manifest.json").write_text(json.dumps(manifest, ensure_ascii=False, indent=2), encoding="utf-8")
    (args.output / "nvda-transcript.txt").write_text("\n".join(transcript), encoding="utf-8")
    print(json.dumps(manifest, ensure_ascii=False, indent=2))
    return 0


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except Exception as error:
        print(f"NVDA native eSpeak capture validation failed: {error}")
        raise SystemExit(1)
