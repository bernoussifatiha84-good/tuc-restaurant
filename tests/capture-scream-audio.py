#!/usr/bin/env python3
"""Capture the Scream virtual sound card's multicast PCM stream as a WAV file."""

from __future__ import annotations

import argparse
import json
import socket
import struct
import sys
import time
import wave
from pathlib import Path

GROUP = "239.255.77.77"
PORT = 4010
HEADER_BYTES = 5


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser()
    parser.add_argument("--output", required=True, type=Path)
    parser.add_argument("--stop-file", required=True, type=Path)
    parser.add_argument("--timeout", type=float, default=90)
    parser.add_argument("--minimum-seconds", type=float, default=2)
    return parser.parse_args()


def main() -> int:
    args = parse_args()
    args.output.parent.mkdir(parents=True, exist_ok=True)
    pcm_parts: list[bytes] = []
    audio_format: tuple[int, int, int] | None = None
    packet_count = 0
    started = time.monotonic()

    receiver = socket.socket(socket.AF_INET, socket.SOCK_DGRAM, socket.IPPROTO_UDP)
    receiver.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
    receiver.bind(("", PORT))
    membership = socket.inet_aton(GROUP) + socket.inet_aton("0.0.0.0")
    receiver.setsockopt(socket.IPPROTO_IP, socket.IP_ADD_MEMBERSHIP, membership)
    receiver.settimeout(0.5)
    try:
        while time.monotonic() - started < args.timeout:
            if args.stop_file.exists() and time.monotonic() - started >= args.minimum_seconds:
                break
            try:
                packet, _ = receiver.recvfrom(65535)
            except socket.timeout:
                continue
            if len(packet) <= HEADER_BYTES:
                continue

            rate_code, sample_width, channels, _, _ = packet[:HEADER_BYTES]
            sample_rate = (44100 if rate_code >= 128 else 48000) * (rate_code % 128)
            if sample_rate <= 0 or sample_width not in (8, 16, 24, 32) or channels not in (1, 2, 4, 6, 8):
                raise RuntimeError(
                    f"Unsupported Scream audio format: {sample_rate} Hz, {sample_width}-bit, {channels} channels"
                )
            current_format = (sample_rate, sample_width, channels)
            if audio_format is not None and current_format != audio_format:
                raise RuntimeError(f"Scream audio format changed during capture: {audio_format} -> {current_format}")
            audio_format = current_format
            payload = packet[HEADER_BYTES:]
            frame_width = channels * (sample_width // 8)
            payload = payload[: len(payload) - (len(payload) % frame_width)]
            if payload:
                pcm_parts.append(payload)
                packet_count += 1
    finally:
        receiver.close()

    pcm = b"".join(pcm_parts)
    if audio_format is None or packet_count == 0:
        raise RuntimeError("No Scream PCM packets were received; NVDA audio output was not captured")
    sample_rate, sample_width, channels = audio_format
    sample_size = sample_width // 8
    frames = len(pcm) // (channels * sample_size)
    duration = frames / sample_rate
    peak = max(pcm, default=0)
    if duration < args.minimum_seconds:
        raise RuntimeError(f"Captured only {duration:.2f} seconds of PCM; expected at least {args.minimum_seconds:.2f}")
    if peak == 0:
        raise RuntimeError("Captured PCM is silent (all samples are zero)")

    with wave.open(str(args.output), "wb") as wav:
        wav.setnchannels(channels)
        wav.setsampwidth(sample_size)
        wav.setframerate(sample_rate)
        wav.writeframes(pcm)

    print(
        json.dumps(
            {
                "file": str(args.output),
                "packets": packet_count,
                "sample_rate_hz": sample_rate,
                "sample_width_bits": sample_width,
                "channels": channels,
                "duration_seconds": round(duration, 2),
                "peak_byte": peak,
                "wav_bytes": args.output.stat().st_size,
            },
            ensure_ascii=False,
        )
    )
    return 0


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except Exception as exc:
        print(f"Scream audio capture failed: {exc}", file=sys.stderr)
        raise SystemExit(1)
