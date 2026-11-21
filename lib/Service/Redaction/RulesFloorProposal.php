<?php

/**
 * Dossiq rules-floor proposal.
 *
 * Builds a redaction-span proposal from the spans the deterministic rules
 * found and, when the assistant answers, the spans it proposed. The two are
 * merged by UNION with the rules as a floor: every rule span is in the result
 * unchanged, and an assistant span can only be added. When the assistant is
 * absent or fails, the proposal is the rule spans alone.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Redaction
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/archive/2026-07-16-woo-llm-anonymisation/tasks.md#task-2-3
 * @spec openspec/changes/method-decomposition/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Redaction;

use OCA\Dossiq\Kernel\AppIdentity;
use OCA\Dossiq\Service\Assistant\HermiqAnonymisationClient;
use OCA\Dossiq\Service\Assistant\HermiqAssistantException;
use Psr\Log\LoggerInterface;

/**
 * The rule spans as a floor, and the assistant spans that may be added to it.
 *
 * @spec openspec/changes/method-decomposition/tasks.md
 */
final class RulesFloorProposal {

	/**
	 * Constructor.
	 *
	 * @param HermiqAnonymisationClient $hermiqClient Thin HTTP client to Hermiq's detect-pii surface.
	 * @param LoggerInterface           $logger       Structured logger.
	 */
	public function __construct(
		private readonly HermiqAnonymisationClient $hermiqClient,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Build the merged proposal: rules floor ALWAYS present, LLM spans
	 * layered on top when available, fail-closed to rules-only on any
	 * Hermiq failure.
	 *
	 * @param array<int, array<string, mixed>>                   $ruleSpans The deterministic rule-detected spans.
	 * @param array{text: string, context: array<string, mixed>} $ask       The document text (forwarded to Hermiq), and
	 *                                                                      `{app, objectType, objectRef}`.
	 *
	 * @return array<string, mixed> `{spans, source, llmAvailable, llmError?}`.
	 *
	 * @spec openspec/changes/archive/2026-07-16-woo-llm-anonymisation/tasks.md#task-2-3
	 */
	public function build(array $ruleSpans, array $ask): array {
		$taggedRuleSpans = $this->taggedAsRules(spans: $ruleSpans);

		if ($this->hermiqClient->isAvailable() === false) {
			return $this->rulesOnly(taggedRuleSpans: $taggedRuleSpans);
		}

		try {
			$llmResult = $this->hermiqClient->detectPii(text: $ask['text'], context: $ask['context']);
		} catch (HermiqAssistantException $e) {
			return $this->rulesOnlyFallback(taggedRuleSpans: $taggedRuleSpans, e: $e);
		}

		return $this->rulesPlusLlm(taggedRuleSpans: $taggedRuleSpans, llmSpans: $llmResult['spans']);
	}//end build()

	/**
	 * The proposal when no LLM is available: the rule spans alone.
	 *
	 * @param array<int, array<string, mixed>> $taggedRuleSpans The rule spans, tagged.
	 *
	 * @return array<string, mixed> `{spans, source, llmAvailable}`.
	 */
	private function rulesOnly(array $taggedRuleSpans): array {
		return [
			'spans' => $taggedRuleSpans,
			'source' => 'rules_only',
			'llmAvailable' => false,
		];
	}//end rulesOnly()

	/**
	 * The proposal with the LLM spans merged over the rule floor.
	 *
	 * @param array<int, array<string, mixed>> $taggedRuleSpans The rule spans, tagged.
	 * @param mixed                            $llmSpans        The spans the LLM detected.
	 *
	 * @return array<string, mixed> `{spans, source, llmAvailable}`.
	 */
	private function rulesPlusLlm(array $taggedRuleSpans, mixed $llmSpans): array {
		return [
			'spans' => $this->merged(ruleSpans: $taggedRuleSpans, llmSpans: $llmSpans),
			'source' => 'rules_plus_llm',
			'llmAvailable' => true,
		];
	}//end rulesPlusLlm()

	/**
	 * Log the failed LLM detection and answer with the rule spans alone.
	 *
	 * @param array<int, array<string, mixed>> $taggedRuleSpans The rule spans, tagged.
	 * @param HermiqAssistantException         $e               Why the LLM detection failed.
	 *
	 * @return array<string, mixed> `{spans, source, llmAvailable, llmError}`.
	 */
	private function rulesOnlyFallback(array $taggedRuleSpans, HermiqAssistantException $e): array {
		$this->logger->warning(
			'WOOAnonymisationAssistService: LLM-assisted detection failed, falling back to rules-only',
			['app' => AppIdentity::APP_ID, 'error' => $e->getMessage()]
		);

		return [
			'spans' => $taggedRuleSpans,
			'source' => 'rules_only_fallback',
			'llmAvailable' => true,
			'llmError' => $e->getMessage(),
		];
	}//end rulesOnlyFallback()

	/**
	 * The spans, each tagged as found by the rules.
	 *
	 * @param array<int, array<string, mixed>> $spans The rule spans.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function taggedAsRules(array $spans): array {
		return array_map(
			static function (array $span): array {
				$span['source'] = 'rule';
				return $span;
			},
			$spans
		);
	}//end taggedAsRules()

	/**
	 * Merge rule-detected spans with LLM-proposed spans by UNION.
	 *
	 * INVARIANT (asserted directly by a pinned unit test): every span in
	 * `$ruleSpans` is present, byte-for-byte unchanged, in the returned
	 * array — regardless of the contents of `$llmSpans`. The LLM layer can
	 * only ADD spans (skipping any that exactly duplicate a rule span's
	 * `[start, end, category)` triple); it can never remove, shrink, or
	 * override a rule-detected span. This is what makes the rules floor a
	 * FLOOR rather than a suggestion (woo-llm-anonymisation design.md).
	 *
	 * @param array<int, array<string, mixed>> $ruleSpans Rule-detected spans, already tagged `source: 'rule'`.
	 * @param array<int, array<string, mixed>> $llmSpans Raw spans from Hermiq's detect-pii response.
	 *
	 * @return array<int, array<string, mixed>> The merged, start-sorted span list.
	 *
	 * @spec openspec/changes/archive/2026-07-16-woo-llm-anonymisation/tasks.md#task-2-3
	 */
	private function merged(array $ruleSpans, array $llmSpans): array {
		$merged = $ruleSpans;
		foreach ($this->llmAdditions(llmSpans: $llmSpans, ruleKeys: $this->spanKeys(spans: $ruleSpans)) as $span) {
			$merged[] = $span;
		}

		usort($merged, static fn (array $a, array $b): int => ($a['start'] <=> $b['start']));

		return $merged;
	}//end merged()

	/**
	 * The dedup keys of the rule spans.
	 *
	 * @param array<int, array<string, mixed>> $spans The rule spans.
	 *
	 * @return array<string, true> Key to true.
	 */
	private function spanKeys(array $spans): array {
		$keys = [];
		foreach ($spans as $span) {
			$keys[$this->spanKey(span: $span)] = true;
		}

		return $keys;
	}//end spanKeys()

	/**
	 * The LLM spans that are well-formed and not already a rule span, tagged `source: 'llm'`.
	 *
	 * @param array<int, mixed>   $llmSpans Raw spans from Hermiq's detect-pii response.
	 * @param array<string, true> $ruleKeys The dedup keys of the rule spans.
	 *
	 * @return array<int, array<string, mixed>> The spans to add, in Hermiq's order.
	 */
	private function llmAdditions(array $llmSpans, array $ruleKeys): array {
		$added = [];
		foreach ($llmSpans as $span) {
			// Malformed/out-of-range span — never trusted, never merged in.
			// An exact duplicate of a rule span is already covered — skip the noise.
			if ($this->isValidLlmSpan(span: $span) === false || isset($ruleKeys[$this->spanKey(span: $span)]) === true) {
				continue;
			}

			$span['source'] = 'llm';
			$added[] = $span;
		}

		return $added;
	}//end llmAdditions()

	/**
	 * Whether a raw LLM-returned span has the minimum shape/range required
	 * to be trusted at all: an array with integer `start`/`end`, a string
	 * `category`, a non-negative `start`, and `end` strictly after `start`.
	 * Split out of `merged()` to keep that method's own
	 * cyclomatic complexity readable — this predicate carries no state.
	 *
	 * @param mixed $span The raw span value (untyped — comes from a decoded
	 *                    Hermiq JSON response this method does not trust).
	 *
	 * @return bool
	 */
	private function isValidLlmSpan(mixed $span): bool {
		if ($this->hasSpanShape(span: $span) === false) {
			return false;
		}

		return ($span['start'] >= 0 && $span['end'] > $span['start']);
	}//end isValidLlmSpan()

	/**
	 * Whether a raw value is an array with integer `start`/`end` and a string `category`.
	 *
	 * @param mixed $span The raw span value.
	 *
	 * @return bool
	 */
	private function hasSpanShape(mixed $span): bool {
		return (is_array($span) === true
			&& is_int($span['start'] ?? null) === true
			&& is_int($span['end'] ?? null) === true
			&& is_string($span['category'] ?? null) === true);
	}//end hasSpanShape()

	/**
	 * Build the dedup key for a span: `start:end:category`.
	 *
	 * @param array<string, mixed> $span The span.
	 *
	 * @return string
	 */
	private function spanKey(array $span): string {
		return $span['start'] . ':' . $span['end'] . ':' . $span['category'];
	}//end spanKey()

}//end class
