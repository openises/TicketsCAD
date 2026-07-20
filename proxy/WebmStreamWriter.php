<?php
/**
 * NewUI v4.0 - Minimal WebM Stream Writer for MSE (MediaSource Extensions)
 *
 * Generates WebM binary data suitable for feeding into the browser's
 * MediaSource / SourceBuffer API for gapless streaming Opus playback.
 *
 * Two methods:
 *   getInitSegment()  — returns the WebM header (EBML + Segment + Tracks)
 *   buildCluster()    — returns a Cluster element containing Opus frames
 *
 * The browser appends the init segment once, then appends clusters as they
 * arrive. The browser's Opus decoder maintains state across clusters, giving
 * seamless, artifact-free playback.
 *
 * References:
 *   - WebM spec: https://www.webmproject.org/docs/container/
 *   - Matroska/EBML: https://www.matroska.org/technical/elements.html
 *   - RFC 8794 (EBML)
 */

namespace NewUI\Proxy;

class WebmStreamWriter
{
    /** @var int Frame duration in milliseconds */
    private $frameDurationMs;

    /** @var int Number of channels */
    private $channels;

    /** @var int Input sample rate */
    private $sampleRate;

    /**
     * @param int $sampleRate      Original sample rate (e.g. 16000)
     * @param int $channels        Number of channels (1 = mono)
     * @param int $frameDurationMs Duration per Opus frame in ms (e.g. 20, 60)
     */
    public function __construct(int $sampleRate = 16000, int $channels = 1, int $frameDurationMs = 60)
    {
        $this->sampleRate      = $sampleRate;
        $this->channels        = $channels;
        $this->frameDurationMs = $frameDurationMs;
    }

    /**
     * Build the WebM initialization segment.
     *
     * Contains: EBML Header + Segment (open-ended) + Info + Tracks
     * This is sent once to the browser's SourceBuffer before any audio data.
     *
     * @return string Binary WebM init segment
     */
    public function getInitSegment(): string
    {
        $ebmlHeader = $this->buildEbmlHeader();
        $info       = $this->buildInfo();
        $tracks     = $this->buildTracks();

        // Segment element with unknown size (open-ended for streaming)
        // Element ID 0x18538067, size = unknown (0x01FFFFFFFFFFFFFF)
        $segmentContent = $info . $tracks;
        $segment = $this->ebmlId(0x18538067)
            . "\x01\xFF\xFF\xFF\xFF\xFF\xFF\xFF" // unknown size
            . $segmentContent;

        return $ebmlHeader . $segment;
    }

    /**
     * Build a WebM Cluster element containing Opus audio frames.
     *
     * Each cluster contains SimpleBlock elements with sequential timestamps.
     * The browser's MSE decoder maintains Opus codec state across clusters.
     *
     * @param  array $opusFrames     Array of raw Opus frame byte strings
     * @param  int   $timestampMs    Cluster base timestamp in milliseconds
     * @return string                Binary WebM Cluster element
     */
    public function buildCluster(array $opusFrames, int $timestampMs): string
    {
        // Cluster Timecode element
        $clusterContent = $this->ebmlElement(0xE7, $this->ebmlUint($timestampMs));

        // Add SimpleBlock for each frame
        foreach ($opusFrames as $i => $frame) {
            $offsetMs = $i * $this->frameDurationMs;

            // SimpleBlock format:
            //   track_number (VINT) = 0x81 (track 1)
            //   timecode_offset (int16 BE, relative to cluster)
            //   flags (1 byte) = 0x80 (keyframe)
            //   opus data
            $simpleBlock = "\x81"                           // track 1 (VINT)
                . pack('n', $offsetMs & 0xFFFF)             // timecode offset (int16 BE)
                . "\x80"                                    // flags: keyframe
                . $frame;                                   // opus data

            $clusterContent .= $this->ebmlElement(0xA3, $simpleBlock);
        }

        // Wrap in Cluster element (unknown size for streaming compatibility)
        return $this->ebmlId(0x1F43B675)
            . $this->ebmlSize(strlen($clusterContent))
            . $clusterContent;
    }

    // ── Private: EBML builders ──────────────────────────────────────

    /**
     * Build the EBML Header element.
     */
    private function buildEbmlHeader(): string
    {
        $content = $this->ebmlElement(0x4286, $this->ebmlUint(1))   // EBMLVersion = 1
            . $this->ebmlElement(0x42F7, $this->ebmlUint(1))        // EBMLReadVersion = 1
            . $this->ebmlElement(0x42F2, $this->ebmlUint(4))        // EBMLMaxIDLength = 4
            . $this->ebmlElement(0x42F3, $this->ebmlUint(8))        // EBMLMaxSizeLength = 8
            . $this->ebmlElement(0x4282, "webm")                    // DocType = "webm"
            . $this->ebmlElement(0x4287, $this->ebmlUint(4))        // DocTypeVersion = 4
            . $this->ebmlElement(0x4285, $this->ebmlUint(2));       // DocTypeReadVersion = 2

        return $this->ebmlElement(0x1A45DFA3, $content);
    }

    /**
     * Build the Segment Info element.
     */
    private function buildInfo(): string
    {
        $content = $this->ebmlElement(0x2AD7B1, $this->ebmlUint(1000000))   // TimecodeScale = 1ms
            . $this->ebmlElement(0x4D80, "NewUI-ZelloProxy")                // MuxingApp
            . $this->ebmlElement(0x5741, "NewUI-ZelloProxy");               // WritingApp

        return $this->ebmlElement(0x1549A966, $content);
    }

    /**
     * Build the Tracks element with a single Opus audio track.
     */
    private function buildTracks(): string
    {
        // OpusHead for CodecPrivate (RFC 7845)
        $opusHead = "OpusHead"
            . chr(1)                                    // version
            . chr($this->channels)                      // channel count
            . pack('v', 312)                            // pre-skip (312 samples at 48kHz)
            . pack('V', $this->sampleRate)              // input sample rate
            . pack('v', 0)                              // output gain (0 dB)
            . chr(0);                                   // channel mapping family

        // CodecDelay in nanoseconds (312 samples at 48kHz = 6.5ms = 6500000ns)
        $codecDelay = 6500000;

        // SeekPreRoll in nanoseconds (80ms = 80000000ns, standard for Opus)
        $seekPreRoll = 80000000;

        // Audio sub-element
        // Opus always decodes to 48kHz — SamplingFrequency must be 48000.0 for MSE
        $audio = $this->ebmlElement(0xB5, $this->ebmlFloat(48000.0))   // SamplingFrequency
            . $this->ebmlElement(0x9F, $this->ebmlUint($this->channels)); // Channels

        $trackEntry = $this->ebmlElement(0xD7, $this->ebmlUint(1))       // TrackNumber = 1
            . $this->ebmlElement(0x73C5, $this->ebmlUint(1))             // TrackUID = 1
            . $this->ebmlElement(0x9C, $this->ebmlUint(0))               // FlagLacing = 0
            . $this->ebmlElement(0x83, $this->ebmlUint(2))               // TrackType = 2 (audio)
            . $this->ebmlElement(0x86, "A_OPUS")                         // CodecID
            . $this->ebmlElement(0x63A2, $opusHead)                      // CodecPrivate
            . $this->ebmlElement(0x56AA, $this->ebmlUint($codecDelay))   // CodecDelay
            . $this->ebmlElement(0x56BB, $this->ebmlUint($seekPreRoll))  // SeekPreRoll
            . $this->ebmlElement(0xE1, $audio);                          // Audio

        $trackEntryElement = $this->ebmlElement(0xAE, $trackEntry);

        return $this->ebmlElement(0x1654AE6B, $trackEntryElement);
    }

    // ── Private: EBML encoding helpers ──────────────────────────────

    /**
     * Encode an EBML element ID as raw bytes.
     *
     * Element IDs in EBML are variable-length (1-4 bytes).
     * They're already in their encoded form (include the VINT marker bit).
     *
     * @param  int $id  Element ID value
     * @return string   Raw bytes
     */
    private function ebmlId(int $id): string
    {
        if ($id <= 0xFF) {
            return chr($id);
        } elseif ($id <= 0xFFFF) {
            return pack('n', $id);
        } elseif ($id <= 0xFFFFFF) {
            return chr(($id >> 16) & 0xFF) . pack('n', $id & 0xFFFF);
        } else {
            return pack('N', $id);
        }
    }

    /**
     * Encode an EBML data size as a VINT.
     *
     * VINT format: leading 1-bit indicates width, followed by size bits.
     *   1 byte:  1xxx xxxx (max 127)
     *   2 bytes: 01xx xxxx xxxx xxxx (max 16383)
     *   3 bytes: 001x xxxx ... (max 2097151)
     *   4 bytes: 0001 xxxx ... (max 268435455)
     *   ...up to 8 bytes
     *
     * @param  int $size  Size value
     * @return string     VINT-encoded bytes
     */
    private function ebmlSize(int $size): string
    {
        if ($size < 0x7F) {
            return chr(0x80 | $size);
        } elseif ($size < 0x3FFF) {
            return pack('n', 0x4000 | $size);
        } elseif ($size < 0x1FFFFF) {
            $b = 0x200000 | $size;
            return chr(($b >> 16) & 0xFF) . pack('n', $b & 0xFFFF);
        } elseif ($size < 0x0FFFFFFF) {
            return pack('N', 0x10000000 | $size);
        } else {
            // 5+ byte sizes — for very large elements
            // Use 8-byte VINT
            return "\x01" . substr(pack('J', $size), 1);
        }
    }

    /**
     * Build a complete EBML element (ID + size + data).
     *
     * @param  int    $id   Element ID
     * @param  string $data Element body
     * @return string       Complete element
     */
    private function ebmlElement(int $id, string $data): string
    {
        return $this->ebmlId($id) . $this->ebmlSize(strlen($data)) . $data;
    }

    /**
     * Encode an unsigned integer as minimal big-endian bytes.
     *
     * EBML unsigned integers use the minimum number of bytes needed.
     *
     * @param  int $value
     * @return string
     */
    private function ebmlUint(int $value): string
    {
        if ($value === 0) {
            return "\x00";
        }

        $bytes = '';
        $temp = $value;
        while ($temp > 0) {
            $bytes = chr($temp & 0xFF) . $bytes;
            $temp >>= 8;
        }
        return $bytes;
    }

    /**
     * Encode a 64-bit IEEE 754 double-precision float.
     *
     * EBML floats are stored as big-endian IEEE 754 (4 or 8 bytes).
     *
     * @param  float $value
     * @return string  8-byte big-endian double
     */
    private function ebmlFloat(float $value): string
    {
        // pack('E', ...) = big-endian double (PHP 7.2+)
        return pack('E', $value);
    }
}
