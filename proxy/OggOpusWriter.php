<?php
/**
 * NewUI v4.0 - Minimal Ogg/Opus Container Writer
 *
 * Builds a valid .ogg file from raw Opus audio frames so browsers can
 * play them via the <audio> element. Follows:
 *   - RFC 3533 (Ogg Encapsulation)
 *   - RFC 7845 (Ogg Opus)
 *
 * Usage:
 *   $writer = new OggOpusWriter(16000, 1, 60);
 *   $oggBytes = $writer->build($arrayOfRawOpusFrames);
 *   file_put_contents('output.ogg', $oggBytes);
 *
 * Designed for Zello Channel API audio which uses:
 *   - 16 kHz sample rate, mono, 60ms frames
 */

namespace NewUI\Proxy;

class OggOpusWriter
{
    /** @var int Original sample rate (e.g. 16000) */
    private $sampleRate;

    /** @var int Number of channels (1 = mono) */
    private $channels;

    /** @var int Duration of each Opus frame in milliseconds */
    private $frameDurationMs;

    /** @var int Samples per frame at 48kHz (Opus internal rate) */
    private $samplesPerFrame;

    /** @var int Serial number for the Ogg stream */
    private $serialNo;

    /**
     * @param int $sampleRate      Original sample rate (e.g. 16000)
     * @param int $channels        Number of channels (1 or 2)
     * @param int $frameDurationMs Duration per Opus frame in ms (e.g. 20, 60)
     */
    public function __construct(int $sampleRate = 16000, int $channels = 1, int $frameDurationMs = 60)
    {
        $this->sampleRate      = $sampleRate;
        $this->channels        = $channels;
        $this->frameDurationMs = $frameDurationMs;
        // Opus always uses 48kHz internally for granule position
        $this->samplesPerFrame = (int) (48000 * $frameDurationMs / 1000);
        $this->serialNo        = mt_rand(1, 0x7FFFFFFF);
    }

    /** @var int Page sequence counter for streaming mode */
    private $streamPageSeq = 0;

    /** @var int Granule position counter for streaming mode */
    private $streamGranule = 0;

    /**
     * Build the Ogg header pages (OpusHead + OpusTags) for streaming.
     * Send this to the browser first, then send audio pages via buildAudioPages().
     *
     * @return string  Binary Ogg header (2 pages: OpusHead + OpusTags)
     */
    public function buildHeader(): string
    {
        $this->streamPageSeq = 0;
        $this->streamGranule = 0;

        $output = '';

        // Page 0: OpusHead (BOS)
        $output .= $this->buildPage(
            $this->buildOpusHead(),
            0, 0, 0x02
        );

        // Page 1: OpusTags
        $output .= $this->buildPage(
            $this->buildOpusTags(),
            0, 1, 0x00
        );

        $this->streamPageSeq = 2;
        return $output;
    }

    /**
     * Build audio data pages from a batch of Opus frames (for streaming).
     * Call this repeatedly as audio packets arrive. Each call produces
     * one Ogg page per Opus frame with sequential page/granule numbering.
     *
     * @param  array $opusFrames  Array of raw Opus packets
     * @param  bool  $isLast      True if this is the final batch (sets EOS flag)
     * @return string             Binary Ogg audio pages
     */
    public function buildAudioPages(array $opusFrames, bool $isLast = false): string
    {
        $output = '';
        $count = count($opusFrames);

        foreach ($opusFrames as $i => $frame) {
            $this->streamGranule += $this->samplesPerFrame;
            $lastFrame = $isLast && ($i === $count - 1);
            $flags = $lastFrame ? 0x04 : 0x00;

            $output .= $this->buildPage($frame, $this->streamGranule, $this->streamPageSeq, $flags);
            $this->streamPageSeq++;
        }

        return $output;
    }

    /**
     * Build a complete Ogg/Opus file from raw Opus frames.
     *
     * @param  array $opusFrames  Array of binary strings, each a raw Opus packet
     * @return string             Complete .ogg file as binary string
     */
    public function build(array $opusFrames): string
    {
        $output = '';

        // Page 0: OpusHead (BOS — beginning of stream)
        $output .= $this->buildPage(
            $this->buildOpusHead(),
            0,   // granule position
            0,   // page sequence
            0x02 // BOS flag
        );

        // Page 1: OpusTags (comment header)
        $output .= $this->buildPage(
            $this->buildOpusTags(),
            0,   // granule position (0 for header pages)
            1,   // page sequence
            0x00 // continuation flag (no special flags)
        );

        // Audio data pages — one Opus frame per page for simplicity
        $granulePos = 0;
        $pageSeq    = 2;

        foreach ($opusFrames as $i => $frame) {
            $granulePos += $this->samplesPerFrame;
            $isLast = ($i === count($opusFrames) - 1);
            $flags  = $isLast ? 0x04 : 0x00; // EOS flag on last page

            $output .= $this->buildPage($frame, $granulePos, $pageSeq, $flags);
            $pageSeq++;
        }

        // If no frames were provided, send an empty EOS page
        if (empty($opusFrames)) {
            $output .= $this->buildPage('', 0, 2, 0x04);
        }

        return $output;
    }

    /**
     * Build the OpusHead identification header (RFC 7845 §5.1).
     *
     * Structure (19 bytes for mono):
     *   'OpusHead'       (8 bytes) — magic signature
     *   version          (1 byte)  — always 1
     *   channel_count    (1 byte)
     *   pre_skip         (2 bytes, LE) — encoder delay in samples at 48kHz
     *   input_sample_rate(4 bytes, LE) — original sample rate
     *   output_gain      (2 bytes, LE) — 0 = no gain
     *   mapping_family   (1 byte)      — 0 = single stream
     */
    private function buildOpusHead(): string
    {
        return 'OpusHead'
            . chr(1)                                    // version
            . chr($this->channels)                      // channel count
            . pack('v', 312)                            // pre-skip (312 samples typical)
            . pack('V', $this->sampleRate)              // input sample rate
            . pack('v', 0)                              // output gain (0 dB)
            . chr(0);                                   // channel mapping family (0 = simple)
    }

    /**
     * Build the OpusTags comment header (RFC 7845 §5.2).
     *
     * Minimal: vendor string + zero user comments.
     */
    private function buildOpusTags(): string
    {
        $vendor = 'NewUI ZelloProxy';
        return 'OpusTags'
            . pack('V', strlen($vendor))     // vendor string length
            . $vendor                        // vendor string
            . pack('V', 0);                  // user comment list length (0)
    }

    /**
     * Build a single Ogg page (RFC 3533 §6).
     *
     * Page header structure (27 bytes + segment table):
     *   'OggS'           (4 bytes)  — capture pattern
     *   stream_version   (1 byte)   — always 0
     *   header_type      (1 byte)   — flags: 0x01=continuation, 0x02=BOS, 0x04=EOS
     *   granule_position (8 bytes, LE)
     *   serial_number    (4 bytes, LE)
     *   page_sequence    (4 bytes, LE)
     *   checksum         (4 bytes, LE) — CRC-32 (computed over entire page)
     *   page_segments    (1 byte)   — number of segment table entries
     *   segment_table    (N bytes)  — each byte is a segment length (max 255)
     *
     * @param string $data      Page body data
     * @param int    $granule   Granule position (cumulative sample count at 48kHz)
     * @param int    $pageSeq   Page sequence number
     * @param int    $flags     Header type flags
     * @return string           Complete Ogg page as binary string
     */
    private function buildPage(string $data, int $granule, int $pageSeq, int $flags): string
    {
        // Build segment table: each segment is max 255 bytes.
        // A packet ending on a 255-byte boundary needs a trailing 0-length segment.
        $segments = $this->buildSegmentTable($data);

        // Assemble header (without checksum — filled with zeros first)
        $header = 'OggS'                              // capture pattern
            . chr(0)                                   // stream structure version
            . chr($flags)                              // header type flags
            . $this->packInt64LE($granule)             // granule position (64-bit LE)
            . pack('V', $this->serialNo)               // serial number
            . pack('V', $pageSeq)                      // page sequence number
            . pack('V', 0)                             // checksum placeholder
            . chr(count($segments))                    // number of page segments
            . implode('', array_map('chr', $segments));// segment table

        $page = $header . $data;

        // Compute CRC-32 and inject it at offset 22
        $crc = $this->oggCrc32($page);
        $page[22] = chr($crc & 0xFF);
        $page[23] = chr(($crc >> 8) & 0xFF);
        $page[24] = chr(($crc >> 16) & 0xFF);
        $page[25] = chr(($crc >> 24) & 0xFF);

        return $page;
    }

    /**
     * Build the segment table for a data packet.
     * Ogg segments are max 255 bytes each. A packet whose length is a
     * multiple of 255 needs a trailing 0-length segment to signal completion.
     *
     * @param  string $data
     * @return int[]  Array of segment lengths
     */
    private function buildSegmentTable(string $data): array
    {
        $len = strlen($data);
        if ($len === 0) {
            return [0];
        }

        $segments = [];
        while ($len >= 255) {
            $segments[] = 255;
            $len -= 255;
        }
        $segments[] = $len; // final segment (0-254)

        return $segments;
    }

    /**
     * Pack a 64-bit integer as little-endian (8 bytes).
     * PHP's pack() doesn't have a portable 64-bit LE format on 32-bit systems,
     * so we split into two 32-bit halves.
     */
    private function packInt64LE(int $value): string
    {
        $lo = $value & 0xFFFFFFFF;
        $hi = ($value >> 32) & 0xFFFFFFFF;
        return pack('V', $lo) . pack('V', $hi);
    }

    /**
     * Compute Ogg CRC-32 (polynomial 0x04C11DB7, no bit reversal).
     * This is NOT the same as PHP's crc32() which uses the standard zlib polynomial.
     *
     * @param  string $data
     * @return int    CRC-32 value (unsigned 32-bit)
     */
    private function oggCrc32(string $data): int
    {
        static $table = null;

        if ($table === null) {
            $table = [];
            for ($i = 0; $i < 256; $i++) {
                $r = $i << 24;
                for ($j = 0; $j < 8; $j++) {
                    if ($r & 0x80000000) {
                        $r = (($r << 1) ^ 0x04C11DB7) & 0xFFFFFFFF;
                    } else {
                        $r = ($r << 1) & 0xFFFFFFFF;
                    }
                }
                $table[$i] = $r;
            }
        }

        $crc = 0;
        $len = strlen($data);
        for ($i = 0; $i < $len; $i++) {
            $crc = (($crc << 8) ^ $table[(($crc >> 24) & 0xFF) ^ ord($data[$i])]) & 0xFFFFFFFF;
        }

        return $crc;
    }
}
