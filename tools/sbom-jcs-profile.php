<?php
/**
 * The profile of JSON the SBOM must stay inside — and the guard that keeps it there.
 *
 * Why this exists (private-repo issue on the CycloneDX in-document signature):
 * JSON Signature Format signs the document serialised per RFC 8785 (JCS). The
 * three things that make a JCS canonicaliser hard — ES6 number-to-string for
 * floats, escaping of control characters, and UTF-16 code-unit ordering of
 * object keys — are all ABSENT from this document today: it has no floats, no
 * control characters, and every object key is plain ASCII (for which UTF-16
 * code-unit order and byte order are the same thing). That is what makes a
 * small, checkable canonicaliser possible at all.
 *
 * It is true by luck. Nothing stops a future dependency's metadata introducing
 * a float, and if one arrives AFTER a canonicaliser has been written and
 * proven, the failure is the worst one available: a signature that verifies
 * with our own tool and fails with everyone else's, so a recipient's tooling
 * reports the SBOM as tampered with rather than merely unsigned — arriving
 * later and harder to trace than the change that caused it.
 *
 * So the assumption is made a gate. It has no dependency on any verifier, on
 * JSF, or on a canonicaliser, which is why it can ship first: it protects the
 * premise the eventual work rests on, and stays true whether or not that work
 * is ever done.
 *
 * tools/generate-sbom.php calls it on the assembled document BEFORE anything is
 * written or checked, so `--check` (a CI and pre-commit gate) fails the moment
 * the document leaves the profile.
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

/** 2^53 - 1: the largest integer an IEEE-754 double (and so a JCS number) holds exactly. */
const SBOM_JCS_MAX_SAFE_INT = 9007199254740991;

/**
 * Everything in $node that falls outside the profile, as readable messages.
 *
 * Checks, deliberately the minimum that defines the profile:
 *   - no floats (PHP's json_encode writes 1.0 where JCS writes 1, and 0.1+0.2
 *     style values need the ES6 algorithm to serialise identically);
 *   - integers must be exactly representable as a double;
 *   - no control characters (U+0000..U+001F) in any string or object key — the
 *     escaping rules for them are where independent implementations diverge;
 *   - every object key is ASCII, so ordering needs no UTF-16 reasoning;
 *   - every string is valid UTF-8.
 *
 * @param mixed    $node   The PHP structure about to be json_encode()d.
 * @param string   $path   JSON-path-ish prefix, for the message.
 * @param int      $limit  Stop collecting after this many (a runaway document must not flood a log).
 * @return string[]
 */
function sbom_jcs_profile_violations($node, string $path = '$', int $limit = 25): array
{
    $out = [];
    _sbom_jcs_walk($node, $path, $limit, $out);
    return $out;
}

function _sbom_jcs_walk($node, string $path, int $limit, array &$out): void
{
    if (count($out) >= $limit) {
        return;
    }
    if (is_array($node)) {
        $isList = function_exists('array_is_list') ? array_is_list($node) : (array_keys($node) === range(0, count($node) - 1));
        foreach ($node as $k => $v) {
            if (!$isList) {
                $key = (string) $k;
                if (preg_match('/[\x00-\x1f]/', $key) === 1) {
                    $out[] = $path . ': object key ' . json_encode($key) . ' contains a control character';
                } elseif (preg_match('/[^\x00-\x7f]/', $key) === 1) {
                    $out[] = $path . ': object key ' . json_encode($key, JSON_UNESCAPED_UNICODE)
                           . ' is not ASCII (UTF-16 code-unit order would have to be reasoned about)';
                }
                _sbom_jcs_walk($v, $path . '.' . $key, $limit, $out);
            } else {
                _sbom_jcs_walk($v, $path . '[' . $k . ']', $limit, $out);
            }
            if (count($out) >= $limit) {
                return;
            }
        }
        return;
    }
    if (is_object($node)) {
        _sbom_jcs_walk((array) $node, $path, $limit, $out);
        return;
    }
    if (is_float($node)) {
        $out[] = $path . ': float ' . var_export($node, true)
               . ' (RFC 8785 number serialisation is not part of the proven profile)';
        return;
    }
    if (is_int($node)) {
        if ($node > SBOM_JCS_MAX_SAFE_INT || $node < -SBOM_JCS_MAX_SAFE_INT) {
            $out[] = $path . ': integer ' . $node . ' is not exactly representable as an IEEE-754 double';
        }
        return;
    }
    if (is_string($node)) {
        if (preg_match('/[\x00-\x1f]/', $node) === 1) {
            $out[] = $path . ': string contains a control character';
        } elseif (function_exists('mb_check_encoding') && !mb_check_encoding($node, 'UTF-8')) {
            $out[] = $path . ': string is not valid UTF-8';
        }
    }
    // bool and null are always inside the profile.
}
