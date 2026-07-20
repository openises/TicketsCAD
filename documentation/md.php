<?php
/**
 * Focused markdown→HTML renderer for the TicketsCAD documentation viewer.
 *
 * Handles the subset of CommonMark + GitHub-flavoured markdown actually used
 * in the docs/ tree:
 *
 *   - ATX headings (# through ######) with auto-generated ids for anchor links
 *   - Paragraphs
 *   - Bold (**...**), italic (*...*, _..._)
 *   - Inline code (`...`)
 *   - Fenced code blocks (```lang ... ```)
 *   - Indented code blocks (4-space)
 *   - Links [text](href) — with internal-doc-link rewriting hook
 *   - Images ![alt](src)
 *   - Bullet lists (-, *) and ordered lists (1.) — nested via 2-space indent
 *   - GFM pipe tables with header row + separator
 *   - Blockquotes (>)
 *   - Horizontal rules (---)
 *   - HTML escaping for all content
 *   - Auto-link bare URLs (https://...)
 *
 * Not handled (intentionally — none of our docs use them):
 *   - Reference-style links
 *   - Footnotes
 *   - Task lists ([x])
 *   - Raw HTML in markdown (we escape it)
 *
 * Single function: md_to_html($markdown, $link_rewriter = null).
 * Pass a $link_rewriter callable to transform link hrefs (e.g. rewrite
 * `GLOSSARY.md` → `?doc=GLOSSARY` for the viewer).
 */

if (!function_exists('md_to_html')) {

    function md_to_html(string $md, ?callable $link_rewriter = null): string
    {
        // Normalise line endings.
        $md = str_replace(["\r\n", "\r"], "\n", $md);

        $lines = explode("\n", $md);
        $out = [];
        $i = 0;
        $n = count($lines);

        while ($i < $n) {
            $line = $lines[$i];

            // Blank line → paragraph break (skip; the inner blocks handle it)
            if (trim($line) === '') {
                $i++;
                continue;
            }

            // Fenced code block: ```lang ... ```
            if (preg_match('/^```(\w*)\s*$/', $line, $m)) {
                $lang = $m[1];
                $code = [];
                $i++;
                while ($i < $n && !preg_match('/^```\s*$/', $lines[$i])) {
                    $code[] = $lines[$i];
                    $i++;
                }
                $i++;  // skip closing fence
                $escaped = htmlspecialchars(implode("\n", $code), ENT_QUOTES, 'UTF-8');
                $cls = $lang ? ' class="language-' . htmlspecialchars($lang, ENT_QUOTES) . '"' : '';
                $out[] = '<pre><code' . $cls . '>' . $escaped . '</code></pre>';
                continue;
            }

            // ATX headings.
            if (preg_match('/^(#{1,6})\s+(.+?)\s*#*\s*$/', $line, $m)) {
                $level = strlen($m[1]);
                $text = $m[2];
                // Pull anchor if explicit {#id}
                $explicitId = null;
                if (preg_match('/\s*\{#([\w\-]+)\}\s*$/', $text, $idm)) {
                    $explicitId = $idm[1];
                    $text = trim(substr($text, 0, -strlen($idm[0])));
                }
                $id = $explicitId ?? _md_slug($text);
                $inner = _md_inline($text, $link_rewriter);
                $out[] = '<h' . $level . ' id="' . htmlspecialchars($id, ENT_QUOTES) . '">'
                       . $inner . '</h' . $level . '>';
                $i++;
                continue;
            }

            // Setext headings (=== and ---) — only check the NEXT line to disambiguate from HR
            if ($i + 1 < $n && trim($line) !== '' && preg_match('/^(=+|-+)\s*$/', $lines[$i + 1])) {
                $level = $lines[$i + 1][0] === '=' ? 1 : 2;
                $text = trim($line);
                $id = _md_slug($text);
                $inner = _md_inline($text, $link_rewriter);
                $out[] = '<h' . $level . ' id="' . htmlspecialchars($id, ENT_QUOTES) . '">'
                       . $inner . '</h' . $level . '>';
                $i += 2;
                continue;
            }

            // Horizontal rule (--- or *** or ___, alone on a line)
            if (preg_match('/^(\*{3,}|-{3,}|_{3,})\s*$/', $line)) {
                $out[] = '<hr>';
                $i++;
                continue;
            }

            // Tables (pipe-delimited GFM). Require a header row + separator row.
            if (strpos($line, '|') !== false
                && $i + 1 < $n
                && preg_match('/^\s*\|?[\s:|-]+\|?[\s:|-]*$/', $lines[$i + 1])
                && substr_count($lines[$i + 1], '|') > 0
            ) {
                [$tableHtml, $consumed] = _md_table($lines, $i, $link_rewriter);
                if ($tableHtml !== null) {
                    $out[] = $tableHtml;
                    $i += $consumed;
                    continue;
                }
            }

            // Blockquote (lines starting with >)
            if (preg_match('/^>\s?/', $line)) {
                $block = [];
                while ($i < $n && preg_match('/^>\s?(.*)$/', $lines[$i], $bm)) {
                    $block[] = $bm[1];
                    $i++;
                }
                // Recursively render the inner content.
                $inner = md_to_html(implode("\n", $block), $link_rewriter);
                $out[] = '<blockquote>' . $inner . '</blockquote>';
                continue;
            }

            // Lists (- / * for unordered, 1. for ordered).
            if (preg_match('/^(\s*)([\-\*\+]|\d+\.)\s+/', $line)) {
                [$listHtml, $consumed] = _md_list($lines, $i, $link_rewriter);
                $out[] = $listHtml;
                $i += $consumed;
                continue;
            }

            // Default: paragraph. Accumulate consecutive non-empty, non-special lines.
            $para = [$line];
            $i++;
            while ($i < $n
                && trim($lines[$i]) !== ''
                && !preg_match('/^(#{1,6}\s|```|>|[\-\*\+]\s|\d+\.\s|\|)/', $lines[$i])
                && !preg_match('/^(\*{3,}|-{3,}|_{3,})\s*$/', $lines[$i])
            ) {
                $para[] = $lines[$i];
                $i++;
            }
            $text = implode(' ', array_map('trim', $para));
            $out[] = '<p>' . _md_inline($text, $link_rewriter) . '</p>';
        }

        return implode("\n", $out);
    }

    /**
     * Render an inline fragment: bold, italic, code, links, autolinks.
     * Input is the raw markdown text of a single block; output is HTML-safe.
     */
    function _md_inline(string $s, ?callable $link_rewriter = null): string
    {
        // Extract inline code first to protect from other replacements.
        $codeRefs = [];
        $s = preg_replace_callback('/`([^`\n]+)`/', function ($m) use (&$codeRefs) {
            $placeholder = "\x01CODE" . count($codeRefs) . "\x02";
            $codeRefs[] = '<code>' . htmlspecialchars($m[1], ENT_QUOTES, 'UTF-8') . '</code>';
            return $placeholder;
        }, $s);

        // Now HTML-escape the rest (links will be handled with non-escaped output).
        $s = htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

        // Images ![alt](src) — before links since both use [
        $s = preg_replace_callback(
            '/!\[([^\]]*)\]\(([^)]+)\)/',
            function ($m) {
                $alt = $m[1];
                $src = htmlspecialchars($m[2], ENT_QUOTES, 'UTF-8');
                return '<img src="' . $src . '" alt="' . $alt . '">';
            },
            $s
        );

        // Links [text](href) — rewriter callable optional
        $s = preg_replace_callback(
            '/\[([^\]]+)\]\(([^)]+)\)/',
            function ($m) use ($link_rewriter) {
                $text = $m[1];
                // href is double-escaped already (htmlspecialchars above); decode for rewriter
                $href = html_entity_decode($m[2], ENT_QUOTES, 'UTF-8');
                if ($link_rewriter) {
                    $href = $link_rewriter($href);
                }
                $href = htmlspecialchars($href, ENT_QUOTES, 'UTF-8');
                // Mark external links so styling can target them
                $isExternal = preg_match('#^(https?:|mailto:|tel:)#', $href);
                $extAttr = $isExternal ? ' rel="noopener nofollow" target="_blank"' : '';
                return '<a href="' . $href . '"' . $extAttr . '>' . $text . '</a>';
            },
            $s
        );

        // Bold **...** (must come before italic with single * to avoid ambiguity)
        $s = preg_replace('/\*\*((?:[^*]|\*(?!\*))+?)\*\*/', '<strong>$1</strong>', $s);

        // Italic *...* (avoid matching inside words like a*b*c we want; but skip ** already replaced)
        $s = preg_replace('/(?<!\*)\*([^*\s][^*]*?)\*(?!\*)/', '<em>$1</em>', $s);

        // Italic _..._  (word-boundary)
        $s = preg_replace('/(?<![A-Za-z0-9_])_([^_]+?)_(?![A-Za-z0-9_])/', '<em>$1</em>', $s);

        // Auto-link bare URLs (only those NOT already inside an href attr; rough heuristic)
        $s = preg_replace_callback(
            '#(?<!["\'>])(https?://[^\s<>"\'\)]+)#',
            function ($m) {
                $url = $m[1];
                return '<a href="' . $url . '" rel="noopener nofollow" target="_blank">' . $url . '</a>';
            },
            $s
        );

        // Restore inline code.
        foreach ($codeRefs as $idx => $html) {
            $s = str_replace("\x01CODE{$idx}\x02", $html, $s);
        }

        return $s;
    }

    /**
     * Slugify a heading for anchor links — lowercase, words joined by `-`,
     * non-alphanumeric stripped. Matches GitHub's default slug style closely
     * enough that hand-authored anchor links in our docs will work.
     */
    function _md_slug(string $s): string
    {
        // Strip markdown formatting first (** _ ` etc.)
        $s = preg_replace('/[`*_]/', '', $s);
        $s = strtolower(trim($s));
        // Replace non-alphanumeric runs with dash
        $s = preg_replace('/[^a-z0-9]+/', '-', $s);
        $s = trim($s, '-');
        return $s !== '' ? $s : 'section';
    }

    /**
     * Render a pipe-table starting at $lines[$start]. Returns
     * [html, consumed_line_count] or [null, 0] if the table is malformed.
     */
    function _md_table(array $lines, int $start, ?callable $link_rewriter): array
    {
        $header = trim($lines[$start]);
        $sep    = trim($lines[$start + 1]);

        // Split with regex that respects pipes inside inline code? We don't,
        // but our docs avoid that combo.
        $headers = _md_split_row($header);
        $aligns  = array_map('_md_align', _md_split_row($sep));
        if (empty($headers) || count($headers) !== count($aligns)) {
            return [null, 0];
        }

        $rows = [];
        $i = $start + 2;
        while ($i < count($lines)) {
            $r = trim($lines[$i]);
            if ($r === '' || strpos($r, '|') === false) break;
            $rows[] = _md_split_row($r);
            $i++;
        }

        $html = '<div class="table-wrap"><table class="table table-sm">';
        $html .= '<thead><tr>';
        foreach ($headers as $idx => $h) {
            $a = $aligns[$idx] ?? '';
            $style = $a ? ' style="text-align:' . $a . '"' : '';
            $html .= '<th' . $style . '>' . _md_inline($h, $link_rewriter) . '</th>';
        }
        $html .= '</tr></thead><tbody>';

        foreach ($rows as $row) {
            $html .= '<tr>';
            foreach ($headers as $idx => $_) {
                $cell = $row[$idx] ?? '';
                $a = $aligns[$idx] ?? '';
                $style = $a ? ' style="text-align:' . $a . '"' : '';
                $html .= '<td' . $style . '>' . _md_inline($cell, $link_rewriter) . '</td>';
            }
            $html .= '</tr>';
        }

        $html .= '</tbody></table></div>';
        return [$html, $i - $start];
    }

    function _md_split_row(string $row): array
    {
        // Strip leading + trailing pipe, then split on |
        // Sonar php:S5850 (2026-07-03): parenthesize each alternative
        // so the operator precedence is explicit — reads as
        // "(leading whitespace + pipe)  OR  (trailing pipe + whitespace)"
        // instead of the ambiguous "\s*\||\|\s*" that could be misread
        // as "\s* then (\| OR \|) then \s*".
        $row = preg_replace('/(^\s*\|)|(\|\s*$)/', '', $row);
        $parts = explode('|', $row);
        return array_map('trim', $parts);
    }

    function _md_align(string $sepCell): string
    {
        $sepCell = trim($sepCell);
        $left  = (substr($sepCell, 0, 1) === ':');
        $right = (substr($sepCell, -1) === ':');
        if ($left && $right) return 'center';
        if ($right)          return 'right';
        if ($left)           return 'left';
        return '';
    }

    /**
     * Render a list block (ordered or unordered) starting at $lines[$start].
     * Returns [html, consumed_line_count].
     *
     * Nesting is by indentation in 2-space units (matches our docs).
     */
    function _md_list(array $lines, int $start, ?callable $link_rewriter): array
    {
        $n = count($lines);
        $i = $start;

        // Determine base indent + ordering from the first line.
        preg_match('/^(\s*)([\-\*\+]|\d+\.)\s+(.*)$/', $lines[$i], $m);
        $baseIndent = strlen($m[1]);
        $ordered = ctype_digit(substr($m[2], 0, 1));
        $tag = $ordered ? 'ol' : 'ul';

        $html = '<' . $tag . '>';

        while ($i < $n) {
            $ln = $lines[$i];
            if (trim($ln) === '') { $i++; continue; }

            if (!preg_match('/^(\s*)([\-\*\+]|\d+\.)\s+(.*)$/', $ln, $m)) {
                break;
            }
            $indent = strlen($m[1]);
            if ($indent < $baseIndent) break;
            if ($indent > $baseIndent) {
                // Nested list — recurse
                [$nested, $cons] = _md_list($lines, $i, $link_rewriter);
                // Append nested to previous li (remove the closing </li> first)
                $html = preg_replace('#</li>$#', $nested . '</li>', $html, 1);
                $i += $cons;
                continue;
            }

            // Same-level item.
            $itemText = $m[3];
            $i++;

            // Continuation lines (indented further) belong to this item.
            // BUT — a deeper-indented line that is itself a list marker
            // starts a nested list, which we hand off to a recursive call
            // AFTER emitting this li.
            $cont = [];
            $nestedHtml = '';
            while ($i < $n && trim($lines[$i]) !== '') {
                if (preg_match('/^(\s*)([\-\*\+]|\d+\.)\s+/', $lines[$i], $mm)) {
                    if (strlen($mm[1]) <= $baseIndent) {
                        // Same-or-shallower marker — break to outer loop
                        break;
                    }
                    // Deeper-indented marker — recurse for the nested list
                    [$nested, $cons] = _md_list($lines, $i, $link_rewriter);
                    $nestedHtml .= $nested;
                    $i += $cons;
                    continue;
                }
                $cont[] = ltrim($lines[$i]);
                $i++;
            }
            if (!empty($cont)) {
                $itemText .= ' ' . implode(' ', $cont);
            }

            $html .= '<li>' . _md_inline(trim($itemText), $link_rewriter) . $nestedHtml . '</li>';
        }

        $html .= '</' . $tag . '>';
        return [$html, $i - $start];
    }
}
