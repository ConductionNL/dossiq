<?php

/**
 * Procest AI PII redactor.
 *
 * The single definition of what counts as deterministically-detectable PII in
 * free text (BSN, IBAN, Dutch phone number, postcode) and the two things
 * procest does with it: scrub it out of a prompt before the prompt leaves the
 * app, and report its exact character spans so a human can review them.
 *
 * Split out of {@see \OCA\Procest\Service\AiService} so the pattern set and both
 * of its consumers sit in one small class — the two can never drift apart on
 * WHICH patterns count as PII.
 *
 * @category Service
 * @package  OCA\Procest\Service\Ai
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://procest.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/woo-llm-anonymisation/tasks.md#task-1-1
 */

declare(strict_types=1);

namespace OCA\Procest\Service\Ai;

/**
 * Detects and scrubs deterministically-detectable PII.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/changes/woo-llm-anonymisation/tasks.md#task-1-1
 */
class AiPiiRedactor
{
    /**
     * Regex patterns for PII detection and stripping.
     *
     * @var array<string, string>
     */
    private const PII_PATTERNS = [
        'bsn'      => '/\b\d{9}\b/',
        'iban'     => '/\b[A-Z]{2}\d{2}[A-Z0-9]{4}\d{7}([A-Z0-9]?){0,16}\b/',
        'phone'    => '/\b(0\d{9}|\+31\d{9})\b/',
        'postcode' => '/\b\d{4}\s?[A-Z]{2}\b/',
    ];

    /**
     * Deterministically detect PII spans in free text.
     *
     * Returns character offsets rather than scrubbing, so callers (e.g.
     * `WOOAnonymisationAssistService`) can present the exact matched ranges for
     * human review and treat them as an immutable "rules floor" that an
     * LLM-assisted proposal is layered on top of, never allowed to remove
     * (woo-llm-anonymisation design.md).
     *
     * Pure — no I/O, no config lookups.
     *
     * @param string $text The text to scan.
     *
     * @return array<int, array{start: int, end: int, category: string, text: string}>
     *         Spans sorted by `start`, ascending.
     *
     * @spec openspec/changes/woo-llm-anonymisation/tasks.md#task-1-1
     */
    public function detectSpans(string $text): array
    {
        $spans = [];

        foreach (self::PII_PATTERNS as $category => $pattern) {
            $matches = [];
            if (preg_match_all($pattern, $text, $matches, PREG_OFFSET_CAPTURE) === false) {
                continue;
            }

            foreach ($matches[0] as $match) {
                [$matchedText, $byteOffset] = $match;
                $spans[] = [
                    'start'    => $byteOffset,
                    'end'      => ($byteOffset + strlen($matchedText)),
                    'category' => $category,
                    'text'     => $matchedText,
                ];
            }
        }

        usort($spans, static fn (array $a, array $b): int => ($a['start'] <=> $b['start']));

        return $spans;
    }//end detectSpans()

    /**
     * Replace every PII occurrence in a prompt with a category placeholder.
     *
     * Reads the SAME pattern set {@see self::detectSpans()} reports on, so a
     * span that is reported for review is also a span that gets scrubbed.
     *
     * @param string $prompt The prompt text.
     *
     * @return string The prompt with PII replaced.
     *
     * @spec openspec/changes/woo-llm-anonymisation/tasks.md#task-1-1
     */
    public function strip(string $prompt): string
    {
        foreach (self::PII_PATTERNS as $type => $pattern) {
            $prompt = preg_replace($pattern, '['.strtoupper($type).'_REMOVED]', $prompt);
        }

        return $prompt;
    }//end strip()
}//end class
