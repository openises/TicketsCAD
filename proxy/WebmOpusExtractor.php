<?php
/**
 * NewUI v4.0 - WebM/Opus Audio Frame Extractor
 *
 * Extracts raw Opus audio frames from a WebM container (as produced by
 * the browser's MediaRecorder API with mimeType 'audio/webm;codecs=opus').
 *
 * WebM uses the Matroska container format based on EBML (Extensible Binary
 * Meta Language). This parser handles just enough EBML to locate the Cluster
 * and SimpleBlock elements containing Opus audio data.
 *
 * Usage:
 *   $extractor = new WebmOpusExtractor();
 *   $opusFrames = $extractor->extract($webmBinaryData);
 *   // $opusFrames is an array of raw Opus packet byte strings
 *
 * References:
 *   - Matroska spec: https://www.matroska.org/technical/elements.html
 *   - EBML spec: RFC 8794
 *   - WebM spec: https://www.webmproject.org/docs/container/
 */

namespace NewUI\Proxy;

class WebmOpusExtractor
{
    /** @var string Binary data being parsed */
    private $data;

    /** @var int Current read position */
    private $pos;

    /** @var int Total data length */
    private $len;

    // EBML Element IDs (variable-length, stored as integers for comparison)
    // See: https://www.matroska.org/technical/elements.html
    const EBML_ID           = 0x1A45DFA3;
    const SEGMENT_ID        = 0x18538067;
    const CLUSTER_ID        = 0x1F43B675;
    const SIMPLE_BLOCK_ID   = 0xA3;
    const BLOCK_GROUP_ID    = 0xA0;
    const BLOCK_ID          = 0xA1;
    const TIMECODE_ID       = 0xE7;
    const TRACKS_ID         = 0x1654AE6B;
    const TRACK_ENTRY_ID    = 0xAE;
    const CODEC_ID_ID       = 0x86;

    /**
     * Extract Opus frames from WebM binary data.
     *
     * @param  string $webmData  Raw WebM file content (binary string)
     * @return array             Array of raw Opus frame byte strings
     */
    public function extract(string $webmData): array
    {
        $this->data = $webmData;
        $this->pos  = 0;
        $this->len  = strlen($webmData);

        $frames = [];

        try {
            while ($this->pos < $this->len) {
                $element = $this->readElement();
                if ($element === null) break;

                $id   = $element['id'];
                $size = $element['size'];
                $start = $this->pos;

                // EBML header or Segment — recurse into children
                if ($id === self::EBML_ID) {
                    $this->pos = $start + $size; // skip EBML header
                    continue;
                }

                if ($id === self::SEGMENT_ID) {
                    // Segment is the top-level container — parse children
                    continue; // don't skip; parse children inline
                }

                if ($id === self::CLUSTER_ID) {
                    // Cluster contains SimpleBlocks — parse children
                    continue;
                }

                if ($id === self::SIMPLE_BLOCK_ID) {
                    $blockData = substr($this->data, $start, $size);
                    $frame = $this->parseSimpleBlock($blockData);
                    if ($frame !== null) {
                        $frames[] = $frame;
                    }
                    $this->pos = $start + $size;
                    continue;
                }

                if ($id === self::BLOCK_GROUP_ID) {
                    // Block group contains a Block element — parse children
                    continue;
                }

                if ($id === self::BLOCK_ID) {
                    $blockData = substr($this->data, $start, $size);
                    $frame = $this->parseSimpleBlock($blockData);
                    if ($frame !== null) {
                        $frames[] = $frame;
                    }
                    $this->pos = $start + $size;
                    continue;
                }

                // Skip all other elements
                $this->pos = $start + $size;
            }
        } catch (\Exception $e) {
            \plog("[WebmExtractor] Parse error at pos {$this->pos}: " . $e->getMessage());
        }

        \plog("[WebmExtractor] Extracted " . count($frames) . " Opus frames from " . strlen($webmData) . " bytes of WebM data");

        return $frames;
    }

    /**
     * Read an EBML element header (ID + size).
     *
     * EBML uses variable-length integers (VINT) for both element IDs
     * and data sizes. The number of leading zeros in the first byte
     * determines the total byte count.
     *
     * @return array|null  ['id' => int, 'size' => int] or null at end
     */
    private function readElement(): ?array
    {
        if ($this->pos >= $this->len) return null;

        $id = $this->readVint(false); // ID keeps VINT marker bit
        if ($id === null) return null;

        $size = $this->readVint(true); // Size strips VINT marker bit
        if ($size === null) return null;

        // Handle unknown size (all 1s) — treat as "until end of data"
        if ($size === -1) {
            $size = $this->len - $this->pos;
        }

        return ['id' => $id, 'size' => $size];
    }

    /**
     * Read a variable-length integer (VINT) from the current position.
     *
     * EBML VINT encoding:
     *   1-byte: 1xxx xxxx (7 data bits)
     *   2-byte: 01xx xxxx xxxx xxxx (14 data bits)
     *   3-byte: 001x xxxx ... (21 data bits)
     *   4-byte: 0001 xxxx ... (28 data bits)
     *   ...up to 8 bytes
     *
     * @param  bool $stripMarker  If true, remove the VINT width marker bit
     * @return int|null           Decoded integer value, or null at end
     */
    private function readVint(bool $stripMarker): ?int
    {
        if ($this->pos >= $this->len) return null;

        $first = ord($this->data[$this->pos]);
        if ($first === 0) return null;

        // Count leading zeros to determine width
        $width = 1;
        $mask = 0x80;
        while ($width <= 8 && ($first & $mask) === 0) {
            $width++;
            $mask >>= 1;
        }

        if ($width > 8) return null;
        if ($this->pos + $width > $this->len) return null;

        // Read the value
        $value = $first;
        if ($stripMarker) {
            $value = $first & (~$mask & 0xFF); // remove marker bit
        }

        for ($i = 1; $i < $width; $i++) {
            $value = ($value << 8) | ord($this->data[$this->pos + $i]);
        }

        $this->pos += $width;

        // Check for "unknown size" (all data bits set to 1)
        if ($stripMarker) {
            $maxVal = (1 << (7 * $width)) - 1;
            if ($value === $maxVal) {
                return -1; // unknown size marker
            }
        }

        return $value;
    }

    /**
     * Parse a SimpleBlock or Block element to extract the Opus frame.
     *
     * SimpleBlock format:
     *   - Track number (VINT, typically 1 byte: 0x81 = track 1)
     *   - Timecode offset (int16, big-endian, relative to Cluster timecode)
     *   - Flags (1 byte: keyframe, lacing, etc.)
     *   - Frame data (rest of the block)
     *
     * Lacing is used when multiple frames are in one block.
     * For MediaRecorder output, lacing is typically not used (no lacing = 0).
     *
     * @param  string $blockData  Raw block content
     * @return string|null        Opus frame data, or null on error
     */
    private function parseSimpleBlock(string $blockData): ?string
    {
        $len = strlen($blockData);
        if ($len < 4) return null;

        $pos = 0;

        // Read track number (VINT)
        $first = ord($blockData[0]);
        $trackWidth = 1;
        $trackMask = 0x80;
        while ($trackWidth <= 4 && ($first & $trackMask) === 0) {
            $trackWidth++;
            $trackMask >>= 1;
        }
        $pos = $trackWidth;

        if ($pos + 3 > $len) return null;

        // Skip timecode (2 bytes) and flags (1 byte)
        $flags = ord($blockData[$pos + 2]);
        $pos += 3;

        // Check for lacing (bits 1-2 of flags)
        $lacing = ($flags >> 1) & 0x03;

        if ($lacing === 0) {
            // No lacing — rest of block is one frame
            if ($pos >= $len) return null;
            return substr($blockData, $pos);
        }

        // Xiph or fixed-size lacing — read frame count
        if ($pos >= $len) return null;
        $frameCount = ord($blockData[$pos]) + 1;
        $pos++;

        if ($frameCount < 1) return null;

        if ($lacing === 0x01) {
            // Xiph lacing
            $sizes = [];
            $totalSized = 0;
            for ($i = 0; $i < $frameCount - 1; $i++) {
                $size = 0;
                while ($pos < $len) {
                    $byte = ord($blockData[$pos]);
                    $pos++;
                    $size += $byte;
                    if ($byte < 255) break;
                }
                $sizes[] = $size;
                $totalSized += $size;
            }
            // Last frame gets remaining bytes
            $sizes[] = $len - $pos - $totalSized;

            // Return the first frame (we could return all, but for simplicity)
            $result = [];
            foreach ($sizes as $s) {
                if ($pos + $s > $len) break;
                $result[] = substr($blockData, $pos, $s);
                $pos += $s;
            }
            return !empty($result) ? $result[0] : null;
        }

        if ($lacing === 0x02) {
            // Fixed-size lacing — all frames same size
            $remaining = $len - $pos;
            $frameSize = (int) ($remaining / $frameCount);
            if ($frameSize <= 0) return null;
            return substr($blockData, $pos, $frameSize);
        }

        // EBML lacing (0x03) — more complex, uncommon for MediaRecorder
        // Fall back to returning all remaining data as one frame
        return substr($blockData, $pos);
    }
}
