<?php

/**
 * Dossiq triage conditions: what a triage rule asks of a document, checked and matched.
 *
 * @category Review
 * @package  OCA\Dossiq\Review
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/woo-review-triage/specs/document-review/spec.md#requirement-rules-mark-documents-without-opening-them-visibly-req-wrt-002
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Review;

use OCA\Dossiq\Exception\RefusedException;

/**
 * Checks a rule's conditions when it is saved and matches them against a document's facts.
 *
 * Every condition must hold. The facts are the ones TriageRules reads for a
 * document: `custodian` and `sourceSystem` from its provenance on the case,
 * `senderDomain` and the mail date from `provenance.mail`, the mime type and
 * creation date from its record, and its extracted text. A fact that is not
 * known never matches: a document without text matches no text condition,
 * and a mail whose headers were not read matches no sender domain.
 *
 * @phpstan-type MatchedFacts array{custodian: string, sourceSystem: string, mimeType: string, date: string, senderDomain: string, text: ?string}
 * @psalm-type   MatchedFacts = array{custodian: string, sourceSystem: string, mimeType: string, date: string, senderDomain: string, text: ?string}
 *
 * @spec openspec/changes/woo-review-triage/specs/document-review/spec.md#requirement-rules-mark-documents-without-opening-them-visibly-req-wrt-002
 */
class TriageConditions {

	/**
	 * The conditions a rule can hold.
	 */
	public const FIELDS = ['custodian', 'sourceSystem', 'mimeType', 'dateFrom', 'dateTo', 'senderDomain', 'textContains', 'textNotContains'];

	/**
	 * The conditions that read the document's text.
	 */
	public const TEXT_FIELDS = ['textContains', 'textNotContains'];

	/**
	 * The conditions that hold a date, as YYYY-MM-DD.
	 */
	private const DATE_FIELDS = ['dateFrom', 'dateTo'];

	/**
	 * The conditions of a rule as stored: known fields, trimmed, empty ones left out.
	 *
	 * @param array<string, mixed> $raw The conditions as given.
	 *
	 * @return array<string, string> The conditions.
	 *
	 * @throws RefusedException When a field is unknown, a date is no date, or no condition is left.
	 *
	 * @spec openspec/changes/woo-review-triage/specs/document-review/spec.md#requirement-rules-mark-documents-without-opening-them-visibly-req-wrt-002
	 */
	public function normalise(array $raw): array {
		if (array_diff(array_keys($raw), self::FIELDS) !== []) {
			throw new RefusedException(
				rule: 'triage-rule-condition-unknown',
				sentence: 'A rule can ask for custodian, source system, file type, a date range, sender domain, or text in the document.',
				status: RefusedException::STATUS_UNPROCESSABLE,
			);
		}

		$conditions = [];
		foreach (self::FIELDS as $field) {
			$value = $raw[$field] ?? '';
			if (is_scalar($value) === false || trim((string)$value) === '') {
				continue;
			}

			$conditions[$field] = trim((string)$value);
			if (in_array($field, self::DATE_FIELDS, true) === true && self::isDate(value: $conditions[$field]) === false) {
				throw new RefusedException(
					rule: 'triage-rule-date-invalid',
					sentence: 'Give the dates of a rule as a day, month and year.',
					status: RefusedException::STATUS_UNPROCESSABLE,
				);
			}
		}

		if ($conditions === []) {
			throw new RefusedException(
				rule: 'triage-rule-condition-required',
				sentence: 'Give the rule at least one condition, or it would mark every document.',
				status: RefusedException::STATUS_UNPROCESSABLE,
			);
		}

		return $conditions;
	}//end normalise()

	/**
	 * The rules that run, in order: active, with a known effect and conditions; by `order`, then name.
	 *
	 * @param list<array<string, mixed>> $rules   The rules as stored.
	 * @param list<string>               $effects The effects a rule can have.
	 *
	 * @return list<array<string, mixed>>
	 *
	 * @spec openspec/changes/woo-review-triage/specs/document-review/spec.md#requirement-rules-mark-documents-without-opening-them-visibly-req-wrt-002
	 */
	public function runnable(array $rules, array $effects): array {
		$runnable = array_values(
			array_filter(
				$rules,
				static fn (array $rule): bool => ($rule['active'] ?? true) !== false
					&& in_array($rule['effect'] ?? '', $effects, true) === true
					&& is_array($rule['conditions'] ?? null) === true
					&& $rule['conditions'] !== []
			)
		);
		usort(
			$runnable,
			static fn (array $one, array $two): int => [(int)($one['order'] ?? 0), (string)($one['name'] ?? '')]
				<=> [(int)($two['order'] ?? 0), (string)($two['name'] ?? '')]
		);

		return $runnable;
	}//end runnable()

	/**
	 * Whether any condition reads the document's text.
	 *
	 * @param array<string, string> $conditions The conditions.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/woo-review-triage/specs/document-review/spec.md#requirement-rules-mark-documents-without-opening-them-visibly-req-wrt-002
	 */
	public function needsText(array $conditions): bool {
		return array_intersect(array_keys($conditions), self::TEXT_FIELDS) !== [];
	}//end needsText()

	/**
	 * Whether a document's facts meet every condition.
	 *
	 * @param array<string, string> $conditions The conditions, as normalise() answers them.
	 * @param MatchedFacts $facts The document's facts.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/woo-review-triage/specs/document-review/spec.md#requirement-rules-mark-documents-without-opening-them-visibly-req-wrt-002
	 */
	public function matches(array $conditions, array $facts): bool {
		foreach ($conditions as $field => $wanted) {
			if ($this->holds(field: $field, wanted: $wanted, facts: $facts) === false) {
				return false;
			}
		}

		return $conditions !== [];
	}//end matches()

	/**
	 * Whether one condition holds for the facts.
	 *
	 * @param string $field  The condition.
	 * @param string $wanted Its value.
	 * @param MatchedFacts $facts The facts.
	 *
	 * @return bool
	 */
	private function holds(string $field, string $wanted, array $facts): bool {
		return match ($field) {
			'custodian', 'sourceSystem' => $facts[$field] !== '' && $facts[$field] === $wanted,
			'mimeType' => $this->mimeMatches(mime: $facts['mimeType'], wanted: $wanted),
			'dateFrom' => $facts['date'] !== '' && $facts['date'] >= $wanted,
			'dateTo' => $facts['date'] !== '' && $facts['date'] <= $wanted,
			'senderDomain' => $this->domainMatches(domain: $facts['senderDomain'], wanted: $wanted),
			'textContains' => $facts['text'] !== null && mb_stripos($facts['text'], $wanted) !== false,
			'textNotContains' => $facts['text'] !== null && mb_stripos($facts['text'], $wanted) === false,
			default => false,
		};
	}//end holds()

	/**
	 * A mime type matches exactly, or by its kind when the condition ends in `/*`.
	 *
	 * @param string $mime   The document's mime type.
	 * @param string $wanted The condition.
	 *
	 * @return bool
	 */
	private function mimeMatches(string $mime, string $wanted): bool {
		$mime = strtolower($mime);
		$wanted = strtolower($wanted);
		if ($mime === '') {
			return false;
		}

		if (str_ends_with($wanted, '/*') === true) {
			return str_starts_with($mime, substr($wanted, 0, -1));
		}

		return $mime === $wanted;
	}//end mimeMatches()

	/**
	 * A sender domain matches itself and its subdomains.
	 *
	 * @param string $domain The sender's domain, lower-cased.
	 * @param string $wanted The condition.
	 *
	 * @return bool
	 */
	private function domainMatches(string $domain, string $wanted): bool {
		$wanted = ltrim(strtolower($wanted), '@.');
		if ($domain === '' || $wanted === '') {
			return false;
		}

		return $domain === $wanted || str_ends_with($domain, '.'.$wanted);
	}//end domainMatches()

	/**
	 * Whether the value is a calendar day as YYYY-MM-DD.
	 *
	 * @param string $value The value.
	 *
	 * @return bool
	 */
	private static function isDate(string $value): bool {
		if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $parts) !== 1) {
			return false;
		}

		return checkdate((int)$parts[2], (int)$parts[3], (int)$parts[1]);
	}//end isDate()
}//end class
