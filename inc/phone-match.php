<?php
/**
 * phone-match.php — ONE definition of "does this stored phone number match
 * the number that just called?" (Phase 155, GH#108 slice S3).
 *
 * Two places used to carry their own copy of a three-REPLACE LIKE:
 *   - api/constituents.php?phone=  (the dispatcher's manual lookup)
 *   - _p153_resolve_constituent()  (the Constituent a ringing call resolves to)
 * Both stripped only '-', ' ' and '(' and matched `%digits%`. A real carrier
 * hands over "+16125551234"; the stored number is "(612) 555-1234". The ')'
 * survived on the stored side (it became "612)5551234"), the '+' survived on
 * the incoming side, and nothing matched: a duplicate bare Constituent was
 * created for every call and a repeat caller never found their own history.
 *
 * The rule now, for the digits of the incoming number (every non-digit is
 * dropped from BOTH sides):
 *   - ten or more digits: the rightmost TEN digits must equal the rightmost
 *     ten of a stored number that itself has at least ten ("+1 612 555 1234",
 *     "612-555-1234" and "(612) 555-1234" are the same person);
 *   - fewer than ten: the whole digit string must equal the stored digits
 *     (a PBX-internal caller such as 1001, or a local seven-digit number);
 *   - fewer than $minDigits (default 4): no match at all -- the manual
 *     lookup's long-standing guard against matching on three keystrokes.
 *
 * Mode 'resolve' is STRICT (used when a ringing call is linked to a record:
 * a wrong match puts the wrong person's history in front of a dispatcher).
 * Mode 'search' is the dispatcher's manual lookup and is a SUPERSET of
 * 'resolve': it additionally accepts a stored number that CONTAINS the typed
 * digits, so typing the seven digits of a local number still finds
 * "(612) 555-1234" -- the behaviour the old `%digits%` LIKE gave.
 *
 * The digit-stripping is nested REPLACE() rather than REGEXP_REPLACE on
 * purpose: this runs on whatever MySQL/MariaDB a self-hosted install has, and
 * MySQL before 8.0 has no REGEXP_REPLACE. The separators handled are the ones
 * people actually type: space, tab, - ( ) . + /. A stored value with LETTERS
 * in it (an "x55" extension) keeps them and so only matches in 'search' mode.
 */

if (!function_exists('phone_digits')) {

    /** Every non-digit removed. */
    function phone_digits($value): string
    {
        return (string) preg_replace('/\D+/', '', (string) $value);
    }

    /** The `constituents` columns that hold phone numbers. */
    function phone_constituent_columns(): array
    {
        return ['`phone`', '`phone_2`', '`phone_3`', '`phone_4`'];
    }

    /**
     * SQL expression reducing $column to digits. $column must be a trusted
     * identifier (a backticked column name, optionally alias-qualified) --
     * never user input; phone_digits_match_sql() enforces the shape.
     */
    function phone_digits_sql(string $column): string
    {
        $expr = $column;
        foreach ([' ', '-', '(', ')', '.', '+', '/'] as $sep) {
            $expr = "REPLACE($expr, '$sep', '')";
        }
        return "REPLACE($expr, CHAR(9), '')";
    }

    /**
     * Build the WHERE fragment and bound parameters, or null when the number
     * has too few digits to match on.
     *
     * @param string[] $columns   trusted column identifiers (see above)
     * @param string   $dialed    the raw number as received/typed
     * @param string   $mode      'resolve' (strict) | 'search' (lenient superset)
     * @param int      $minDigits fewer digits than this -> null (default 4)
     * @return array{sql:string, params:array}|null
     */
    function phone_digits_match_sql(array $columns, $dialed, string $mode = 'resolve', int $minDigits = 4): ?array
    {
        $digits = phone_digits($dialed);
        $len = strlen($digits);
        if ($len < max(1, $minDigits) || $len > 20) {
            return null;
        }
        $key10 = $len >= 10 ? substr($digits, -10) : null;

        $parts = [];
        $params = [];
        foreach ($columns as $column) {
            if (!preg_match('/^`?[A-Za-z0-9_]+`?(\.`?[A-Za-z0-9_]+`?)?$/', (string) $column)) {
                throw new InvalidArgumentException('phone_digits_match_sql: untrusted column identifier');
            }
            $e = phone_digits_sql((string) $column);
            if ($key10 !== null) {
                $parts[]  = "(CHAR_LENGTH($e) >= 10 AND RIGHT($e, 10) = ?)";
                $params[] = $key10;
            } else {
                $parts[]  = "$e = ?";
                $params[] = $digits;
            }
            if ($mode === 'search') {
                $parts[]  = "$e LIKE ?";
                $params[] = '%' . ($key10 !== null ? $key10 : $digits) . '%';
            }
        }
        if (!$parts) {
            return null;
        }
        return ['sql' => '(' . implode(' OR ', $parts) . ')', 'params' => $params];
    }
}
