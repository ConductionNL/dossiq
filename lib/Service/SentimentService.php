<?php

/**
 * Procest KCC Sentiment Service.
 *
 * Detects trigger words and a coarse sentiment score for a contactmoment
 * transcription using a hardcoded Dutch word-weight dictionary, and derives an
 * escalation recommendation. Pure logic — no external calls — so it is fully
 * unit-testable and safe to run in a background job.
 *
 * @category Service
 * @package  OCA\Procest\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://procest.nl
 *
 * @spec openspec/changes/kcc-werkplek-zaaksysteem-bridge/tasks.md#T09
 */

declare(strict_types=1);

namespace OCA\Procest\Service;

/**
 * Trigger-word detection and sentiment scoring for KCC contactmomenten.
 *
 * @spec openspec/changes/kcc-werkplek-zaaksysteem-bridge/tasks.md#T09
 */
class SentimentService
{
    /**
     * Trigger words that always warrant escalation when present.
     */
    private const SERIOUS_TRIGGERS = ['klacht', 'advocaat', 'media', 'rechtszaak'];

    /**
     * Hardcoded Dutch word-weight dictionary (lowercased, word-boundary matched).
     *
     * @var array<string, float>
     */
    private const WORD_WEIGHTS = [
        'ongelooflijk'  => -0.4,
        'klacht'        => -0.6,
        'wethouder'     => -0.3,
        'advocaat'      => -0.7,
        'media'         => -0.6,
        'rechtszaak'    => -0.8,
        'schandalig'    => -0.6,
        'belachelijk'   => -0.5,
        'woedend'       => -0.7,
        'boos'          => -0.5,
        'teleurgesteld' => -0.3,
        'dank'          => 0.4,
        'bedankt'       => 0.4,
        'fijn'          => 0.3,
        'prima'         => 0.3,
        'tevreden'      => 0.5,
        'top'           => 0.4,
    ];

    /**
     * Analyse a piece of text for sentiment and trigger words.
     *
     * @param string             $text         The transcription / message text.
     * @param array<int, string> $triggerWords Configured trigger words to detect.
     *
     * @return array{score: float, label: string, triggers: array<int, string>, escalatieAanbevolen: bool, escalatieLevel: string, snippet: string}
     *
     * @spec openspec/changes/kcc-werkplek-zaaksysteem-bridge/tasks.md#T09
     */
    public function analyzeSentiment(string $text, array $triggerWords): array
    {
        $haystack = ' '.mb_strtolower(trim($text)).' ';

        $foundTriggers = [];
        foreach ($triggerWords as $word) {
            $needle = mb_strtolower(trim((string) $word));
            if ($needle === '') {
                continue;
            }

            if ($this->containsWord(paddedHaystack: $haystack, needle: $needle) === true) {
                $foundTriggers[] = $needle;
            }
        }

        $foundTriggers = array_values(array_unique($foundTriggers));

        $score   = 0.0;
        $matches = 0;
        foreach (self::WORD_WEIGHTS as $word => $weight) {
            if ($this->containsWord(paddedHaystack: $haystack, needle: $word) === true) {
                $score += $weight;
                $matches++;
            }
        }

        if ($matches > 0) {
            // Average and clamp to [-1, 1].
            $score = max(-1.0, min(1.0, ($score / max(1, (int) ceil($matches / 2)))));
        }

        $escalate = $this->shouldEscalate(score: $score, triggers: $foundTriggers);

        return [
            'score'               => round($score, 2),
            'label'               => $this->labelFor(score: $score, triggers: $foundTriggers),
            'triggers'            => $foundTriggers,
            'escalatieAanbevolen' => $escalate,
            'escalatieLevel'      => $this->getEscalationLevel(score: $score, triggers: $foundTriggers),
            'snippet'             => $this->extractSnippet(text: $text, triggers: $foundTriggers),
        ];
    }//end analyzeSentiment()

    /**
     * Decide whether a contact should be escalated.
     *
     * @param float              $score    The sentiment score.
     * @param array<int, string> $triggers Detected trigger words.
     *
     * @return bool True when escalation is recommended.
     *
     * @spec openspec/changes/kcc-werkplek-zaaksysteem-bridge/tasks.md#T09
     */
    public function shouldEscalate(float $score, array $triggers): bool
    {
        if ($score <= -0.5) {
            return true;
        }

        foreach ($triggers as $trigger) {
            if (in_array(mb_strtolower($trigger), self::SERIOUS_TRIGGERS, true) === true) {
                return true;
            }
        }

        return false;
    }//end shouldEscalate()

    /**
     * Derive the recommended escalation level.
     *
     * @param float              $score    The sentiment score.
     * @param array<int, string> $triggers Detected trigger words.
     *
     * @return string One of geen|geel|oranje|rood.
     *
     * @spec openspec/changes/kcc-werkplek-zaaksysteem-bridge/tasks.md#T09
     */
    public function getEscalationLevel(float $score, array $triggers): string
    {
        foreach ($triggers as $trigger) {
            if (in_array(mb_strtolower($trigger), self::SERIOUS_TRIGGERS, true) === true) {
                return 'rood';
            }
        }

        if ($score < -0.6) {
            return 'rood';
        }

        if ($score <= -0.3) {
            return 'oranje';
        }

        if ($score < 0.0) {
            return 'geel';
        }

        return 'geen';
    }//end getEscalationLevel()

    /**
     * Map a numeric score (and triggers) onto a sentiment label.
     *
     * @param float              $score    The sentiment score.
     * @param array<int, string> $triggers Detected trigger words.
     *
     * @return string One of positief|neutraal|negatief|boos.
     */
    private function labelFor(float $score, array $triggers): string
    {
        foreach ($triggers as $trigger) {
            if (in_array(mb_strtolower($trigger), self::SERIOUS_TRIGGERS, true) === true) {
                return 'boos';
            }
        }

        if ($score <= -0.6) {
            return 'boos';
        }

        if ($score < -0.1) {
            return 'negatief';
        }

        if ($score > 0.2) {
            return 'positief';
        }

        return 'neutraal';
    }//end labelFor()

    /**
     * Extract a short snippet of text around the first detected trigger word.
     *
     * @param string             $text     The original text.
     * @param array<int, string> $triggers Detected trigger words.
     *
     * @return string A snippet, or the leading 160 chars when no trigger found.
     */
    private function extractSnippet(string $text, array $triggers): string
    {
        $trimmed = trim($text);
        if ($trimmed === '') {
            return '';
        }

        if (empty($triggers) === false) {
            $pos = mb_stripos($trimmed, $triggers[0]);
            if ($pos !== false) {
                $start = max(0, ($pos - 40));
                return trim(mb_substr($trimmed, $start, 120));
            }
        }

        return mb_substr($trimmed, 0, 160);
    }//end extractSnippet()

    /**
     * Test whether a word occurs in the (already space-padded, lowercased) text
     * with word boundaries, so "klacht" does not match inside "klachtenfunctie".
     *
     * @param string $paddedHaystack Lowercased text padded with leading/trailing spaces.
     * @param string $needle         The lowercased word to find.
     *
     * @return bool True when the word is present as a whole word.
     */
    private function containsWord(string $paddedHaystack, string $needle): bool
    {
        $pattern = '/(?<![\p{L}\p{N}])'.preg_quote($needle, '/').'(?![\p{L}\p{N}])/u';
        return (preg_match($pattern, $paddedHaystack) === 1);
    }//end containsWord()
}//end class
