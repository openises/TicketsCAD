<?php
/**
 * Phase 113 — Piper driver (offline, the default + mandatory fallback).
 *
 * config_json: { bin, voice, native_rate (default 22050), ffmpeg (default
 * 'ffmpeg') }. text(stdin) → Piper → raw s16le PCM @ native_rate → ffmpeg
 * resample to $rate → s16le mono PCM. A per-application/engine voice override
 * ($voice) replaces the config voice model when set.
 */

require_once __DIR__ . '/engine.php';

function tts_driver_piper(array $cfg, string $text, string $voice, int $rate): array
{
    $bin        = trim((string) ($cfg['bin'] ?? ''));
    $voiceModel = trim($voice) !== '' ? trim($voice) : trim((string) ($cfg['voice'] ?? ''));
    $nativeRate = (int) ($cfg['native_rate'] ?? 22050) ?: 22050;
    $ffmpeg     = trim((string) ($cfg['ffmpeg'] ?? '')) ?: 'ffmpeg';
    $rate       = $rate > 0 ? $rate : 8000;

    if ($bin === '' || $voiceModel === '') {
        return ['ok' => false, 'pcm' => '', 'rate' => $rate,
                'detail' => 'Piper not configured (bin/voice unset)'];
    }
    if (!is_file($bin) && !tts_bin_on_path($bin)) {
        return ['ok' => false, 'pcm' => '', 'rate' => $rate, 'detail' => "Piper binary not found: {$bin}"];
    }
    if (!is_file($voiceModel)) {
        return ['ok' => false, 'pcm' => '', 'rate' => $rate, 'detail' => "Piper voice model not found: {$voiceModel}"];
    }

    // 1. Piper: text → raw s16le PCM @ native_rate.
    $piperCmd = escapeshellarg($bin) . ' -m ' . escapeshellarg($voiceModel) . ' --output-raw';
    $pcmNative = tts_run_pipe($piperCmd, $text, 30);
    if ($pcmNative === null || $pcmNative === '') {
        return ['ok' => false, 'pcm' => '', 'rate' => $rate, 'detail' => 'Piper produced no audio'];
    }

    // 2. ffmpeg: resample native_rate → $rate, mono s16le raw.
    if ($nativeRate === $rate) {
        return ['ok' => true, 'pcm' => $pcmNative, 'rate' => $rate, 'detail' => 'ok'];
    }
    $ffmpegCmd = escapeshellarg($ffmpeg)
        . ' -hide_banner -loglevel error'
        . ' -f s16le -ar ' . $nativeRate . ' -ac 1 -i pipe:0'
        . ' -ar ' . $rate . ' -ac 1 -f s16le pipe:1';
    $pcm = tts_run_pipe($ffmpegCmd, $pcmNative, 20);
    if ($pcm === null || $pcm === '') {
        // Resample failed — hand back the native-rate PCM rather than nothing.
        return ['ok' => true, 'pcm' => $pcmNative, 'rate' => $nativeRate,
                'detail' => 'ok (native rate; ffmpeg resample unavailable)'];
    }
    return ['ok' => true, 'pcm' => $pcm, 'rate' => $rate, 'detail' => 'ok'];
}
