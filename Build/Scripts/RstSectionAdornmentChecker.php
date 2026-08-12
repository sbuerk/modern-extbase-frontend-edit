<?php

declare(strict_types=1);

/**
 * Finds section adornments in reStructuredText whose length does not match the
 * title they belong to, and section adornment characters that do not match the
 * level the section occupies.
 *
 * reStructuredText only requires an adornment to be *at least* as long as its
 * title, so a too long one is valid markup and renders identically. A too short
 * one is a defect, but docutils reports it as a warning rather than an error and
 * the renderer used here does not look at the length at all. Both therefore
 * survive rendering, and both are invisible in the rendered page — see the gate
 * "checkRstSectionAdornments.php" for why that matters.
 *
 * The length of a title is counted in characters, not in bytes and not in
 * columns: docutils compares the adornment against the character count of the
 * source line, so inline markup counts as written and a title of accented
 * letters needs exactly as many adornment characters as it has letters. Titles
 * of double width glyphs would need more, which neither docutils nor this class
 * accounts for.
 *
 * This file carries the algorithm alone and **requires nothing**, neither the
 * composer autoloader nor an installed dependency set. Keep it that way:
 * "checkRstSectionAdornments.php", the quality gate, is what adds the file
 * traversal, the reporting and the composer dependencies those need on top of
 * it.
 *
 * See the documentation conventions in "docs/Index.md".
 */
final class RstSectionAdornmentChecker
{
    /**
     * The adornment characters of the TYPO3 documentation convention, indexed by
     * section level minus one. Level one is the document title, which is both
     * overlined and underlined; every level below it is underlined only. The
     * levels beyond the fourth come from the older TYPO3 coding guidelines and
     * are listed for completeness — a document that needs them is a document
     * that wants splitting.
     */
    private const HIERARCHY = ['=', '=', '-', '~', '"', '\'', '^', '#'];

    /**
     * A line consisting of one ASCII punctuation character repeated at least
     * twice, and nothing else. The character class is the set docutils accepts
     * as an adornment: 0x21-0x2f, 0x3a-0x40, 0x5b-0x60 and 0x7b-0x7e. Trailing
     * whitespace is captured rather than ignored so that fixing a length keeps
     * the line ending of a file that uses CRLF.
     */
    private const ADORNMENT = '/^(([\x21-\x2f\x3a-\x40\x5b-\x60\x7b-\x7e])\2+)([ \t\r]*)$/D';

    /**
     * @return array{
     *     lengths: list<array{line: int, title: string, adornment: string, expected: int, actual: int}>,
     *     characters: list<array{line: int, title: string, level: int, expected: string, actual: string}>,
     *     content: string,
     * } the two kinds of finding, and the content with every adornment length corrected
     */
    public function check(string $content): array
    {
        $lines = explode("\n", $content);
        $count = count($lines);
        $lengths = [];
        $characters = [];
        $styles = [];
        $index = 0;

        while ($index < $count) {
            $section = $this->matchSection($lines, $index);
            if ($section === null) {
                $index++;
                continue;
            }
            $index = $section['next'];

            $title = $section['title'];
            $expected = mb_strlen($title);
            foreach ($section['adornments'] as $adornment) {
                if ($adornment['length'] === $expected) {
                    continue;
                }
                $lengths[] = [
                    'line' => $adornment['line'] + 1,
                    'title' => $title,
                    'adornment' => $adornment['kind'],
                    'expected' => $expected,
                    'actual' => $adornment['length'],
                ];
                $lines[$adornment['line']] = str_repeat($section['character'], $expected) . $adornment['suffix'];
            }

            $overlined = count($section['adornments']) === 2;
            $level = $this->level($styles, $section['character'], $overlined);
            if ($level > count(self::HIERARCHY)) {
                continue;
            }
            if ($section['character'] === self::HIERARCHY[$level - 1] && $overlined === ($level === 1)) {
                continue;
            }
            $characters[] = [
                'line' => $section['titleLine'] + 1,
                'title' => $title,
                'level' => $level,
                'expected' => $this->describe(self::HIERARCHY[$level - 1], $level === 1),
                'actual' => $this->describe($section['character'], $overlined),
            ];
        }

        return [
            'lengths' => $lengths,
            'characters' => $characters,
            'content' => implode("\n", $lines),
        ];
    }

    /**
     * Decides whether a section starts at $index, and returns it if one does.
     *
     * A section is one of exactly two shapes, and nothing else is one:
     *
     * - overline, title, underline — three consecutive lines, the first and the
     *   third an adornment of the same character, the second a title;
     * - title, underline — a title immediately followed by an adornment.
     *
     * Everything else that looks like an adornment is a **transition**: a line
     * of repeated punctuation surrounded by blank lines, which carries no title
     * and whose length means nothing. Distinguishing the two is the whole
     * difficulty of this parser, and it is why the scan consumes a matched
     * section as a unit — an underline is then never reconsidered on its own,
     * and a transition is never mistaken for the underline of the paragraph
     * above it, because the line above a transition is blank.
     *
     * That blank line is the second half of the rule. docutils requires a
     * section title to be preceded by a blank line or by the start of the file,
     * so a punctuation line following a paragraph is not a section adornment
     * however much it resembles one. A title glued to the text above it is
     * malformed reStructuredText, which the renderer does report — unlike the
     * length, which is what this class is here for.
     *
     * Both the adornment and the title have to start in column one. Sections
     * exist only at the top level of a document, so this alone keeps the parser
     * out of directive bodies, literal blocks and comments, where a code example
     * may well contain a heading of its own.
     *
     * @param array<int, string> $lines
     * @return array{
     *     title: string,
     *     titleLine: int,
     *     character: string,
     *     adornments: list<array{kind: string, line: int, length: int, suffix: string}>,
     *     next: int,
     * }|null
     */
    private function matchSection(array $lines, int $index): ?array
    {
        if ($index > 0 && rtrim($lines[$index - 1]) !== '') {
            return null;
        }

        $overline = $this->adornment($lines[$index]);
        if ($overline !== null) {
            if (!isset($lines[$index + 2]) || !$this->isTitle($lines[$index + 1])) {
                return null;
            }
            $underline = $this->adornment($lines[$index + 2]);
            if ($underline === null || $underline['character'] !== $overline['character']) {
                return null;
            }

            return [
                'title' => rtrim($lines[$index + 1]),
                'titleLine' => $index + 1,
                'character' => $overline['character'],
                'adornments' => [
                    ['kind' => 'overline', 'line' => $index, 'length' => $overline['length'], 'suffix' => $overline['suffix']],
                    ['kind' => 'underline', 'line' => $index + 2, 'length' => $underline['length'], 'suffix' => $underline['suffix']],
                ],
                'next' => $index + 3,
            ];
        }

        if (!isset($lines[$index + 1]) || !$this->isTitle($lines[$index])) {
            return null;
        }
        $underline = $this->adornment($lines[$index + 1]);
        if ($underline === null) {
            return null;
        }

        return [
            'title' => rtrim($lines[$index]),
            'titleLine' => $index,
            'character' => $underline['character'],
            'adornments' => [
                ['kind' => 'underline', 'line' => $index + 1, 'length' => $underline['length'], 'suffix' => $underline['suffix']],
            ],
            'next' => $index + 2,
        ];
    }

    /**
     * @return array{character: string, length: int, suffix: string}|null
     */
    private function adornment(string $line): ?array
    {
        if (preg_match(self::ADORNMENT, $line, $matches) !== 1) {
            return null;
        }

        return [
            'character' => $matches[2],
            'length' => mb_strlen($matches[1]),
            'suffix' => $matches[3],
        ];
    }

    private function isTitle(string $line): bool
    {
        return rtrim($line) !== ''
            && !str_starts_with($line, ' ')
            && !str_starts_with($line, "\t")
            && $this->adornment($line) === null;
    }

    /**
     * Resolves the level of a section the way docutils does: a document has no
     * fixed hierarchy, the level of an adornment style is the order in which the
     * style first appears, and a style seen before returns to the level it had.
     * Whether the section is overlined is part of the style, so an overlined "="
     * and an underlined "=" are two levels rather than one.
     *
     * @param list<string> $styles the styles seen so far, outermost first, modified in place
     */
    private function level(array &$styles, string $character, bool $overlined): int
    {
        $style = $character . ($overlined ? '+' : '-');
        $position = array_search($style, $styles, true);
        if ($position === false) {
            $styles[] = $style;

            return count($styles);
        }
        $styles = array_slice($styles, 0, $position + 1);

        return $position + 1;
    }

    private function describe(string $character, bool $overlined): string
    {
        return $overlined
            ? sprintf('"%s" with an overline', $character)
            : sprintf('"%s"', $character);
    }
}
