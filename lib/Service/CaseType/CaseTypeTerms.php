<?php

/**
 * Dossiq case type terms.
 *
 * A case type's terms in words, as board DqZaaktype writes them on the Phases
 * and tasks tab ("8 weeks, extendable once by 6 weeks"). The terms are stored
 * as ISO 8601 durations and flags, which a declarative card shows as P56D; the
 * sentence needs the term, its extension and how often it may be extended
 * together, and plural rules in the reader's language.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\CaseType
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
 * @spec openspec/specs/case-types/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\CaseType;

use OCA\Dossiq\Service\CaseTypeResolver;
use OCP\IL10N;

/**
 * Writes a case type's terms as sentences.
 *
 * @spec openspec/specs/case-types/spec.md
 */
class CaseTypeTerms {

	private const DURATION = '/^P(?:(\d+)Y)?(?:(\d+)M)?(?:(\d+)W)?(?:(\d+)D)?$/';

	/**
	 * Constructor.
	 *
	 * @param CaseTypeResolver $resolver The effective case type, the parent's fields where it is silent.
	 * @param IL10N            $l10n     The reader's language.
	 *
	 * @spec openspec/specs/case-types/spec.md
	 */
	public function __construct(
		private readonly CaseTypeResolver $resolver,
		private readonly IL10N $l10n,
	) {
	}//end __construct()

	/**
	 * The terms of a case type as sentences; an empty string where a term is not set.
	 *
	 * @param string $caseTypeId The case type id.
	 *
	 * @return array{decisionTerm: string, suspension: string, serviceTarget: string, penalty: string, penaltyApplies: bool}|null
	 *         The sentences, whether a late decision costs a penalty, or null when the case type cannot be read.
	 *
	 * @spec openspec/specs/case-types/spec.md
	 * @spec openspec/changes/notice-of-default-from-the-case/specs/ingebrekestelling/spec.md#requirement-a-handler-registers-a-notice-of-default-on-the-case-req-ing-case-01
	 */
	public function termsFor(string $caseTypeId): ?array {
		$caseType = $this->resolver->effectiveCaseType(caseTypeId: $caseTypeId);
		if ($caseType === []) {
			return null;
		}

		return [
			'decisionTerm'  => $this->decisionTerm(caseType: $caseType),
			'suspension'    => $this->suspension(caseType: $caseType),
			'serviceTarget' => $this->durationOrText(value: $caseType['serviceTarget'] ?? null),
			'penalty'       => $this->penalty(caseType: $caseType),
			'penaltyApplies' => (($caseType['penaltyOnLateDecision'] ?? null) === true),
		];
	}//end termsFor()

	/**
	 * The processing term with the extension the case type allows.
	 *
	 * @param array<string, mixed> $caseType The effective case type.
	 *
	 * @return string The sentence.
	 */
	private function decisionTerm(array $caseType): string {
		$term = $this->durationOrText(value: $caseType['processingDeadline'] ?? null);
		if ($term === '' || ($caseType['extensionAllowed'] ?? null) === false) {
			return $term;
		}

		$extension = $this->words(iso: (string) ($caseType['extensionPeriod'] ?? ''));
		if ($extension === '') {
			return $term;
		}

		$times = (int) ($caseType['maxExtensions'] ?? 0);
		if ($times === 1) {
			return $this->l10n->t('%1$s, extendable once by %2$s', [$term, $extension]);
		}

		if ($times > 1) {
			return $this->l10n->t('%1$s, extendable %2$s times by %3$s', [$term, (string) $times, $extension]);
		}

		return $this->l10n->t('%1$s, extendable by %2$s', [$term, $extension]);
	}//end decisionTerm()

	/**
	 * Whether a late decision costs a penalty (Wet dwangsom bij niet tijdig
	 * beslissen), which runs only after a notice of default, and the most it
	 * can come to when the case type says.
	 *
	 * @param array<string, mixed> $caseType The effective case type.
	 *
	 * @return string The sentence, or '' when the case type does not say.
	 *
	 * @spec openspec/specs/case-types/spec.md#requirement-a-case-type-says-whether-a-late-decision-costs-a-penalty
	 */
	private function penalty(array $caseType): string {
		$applies = ($caseType['penaltyOnLateDecision'] ?? null);
		if ($applies === false) {
			return $this->l10n->t('No');
		}

		if ($applies !== true) {
			return '';
		}

		$amount = ($caseType['penaltyMaxAmount'] ?? null);
		if (is_numeric($amount) === true && (float) $amount > 0) {
			return $this->l10n->t('Yes, after a notice of default, at most %1$s', ['€ '.number_format((float) $amount, 0, '', '')]);
		}

		return $this->l10n->t('Yes, after a notice of default');
	}//end penalty()

	/**
	 * Whether a case may be suspended, and for how long at most.
	 *
	 * @param array<string, mixed> $caseType The effective case type.
	 *
	 * @return string The sentence, or '' when the case type does not say.
	 */
	private function suspension(array $caseType): string {
		$allowed = ($caseType['suspensionAllowed'] ?? null);
		if ($allowed === false) {
			return $this->l10n->t('Not allowed');
		}

		if ($allowed !== true) {
			return '';
		}

		$days = (int) ($caseType['maxSuspensionDays'] ?? 0);
		if ($days <= 0) {
			return $this->l10n->t('Allowed');
		}

		return $this->l10n->t('Allowed, at most %1$s', [$this->words(iso: 'P'.$days.'D')]);
	}//end suspension()

	/**
	 * A duration in words, or the value as written when it is not a duration.
	 *
	 * @param mixed $value The stored value.
	 *
	 * @return string The words.
	 */
	private function durationOrText(mixed $value): string {
		if (is_string($value) === false || trim($value) === '') {
			return '';
		}

		$words = $this->words(iso: $value);
		if ($words !== '') {
			return $words;
		}

		return trim($value);
	}//end durationOrText()

	/**
	 * An ISO 8601 duration in words, a whole number of weeks in days counted in weeks.
	 *
	 * @param string $iso The duration.
	 *
	 * @return string The words, or '' when it is not a duration.
	 */
	private function words(string $iso): string {
		if ($iso === 'P' || preg_match(self::DURATION, $iso, $match) !== 1) {
			return '';
		}

		$counts = [
			'year'  => (int) ($match[1] ?? 0),
			'month' => (int) ($match[2] ?? 0),
			'week'  => (int) ($match[3] ?? 0),
			'day'   => (int) ($match[4] ?? 0),
		];
		if (array_sum($counts) === $counts['day'] && $counts['day'] % 7 === 0) {
			$counts['week'] = intdiv($counts['day'], 7);
			$counts['day']  = 0;
		}

		$parts = [];
		foreach ($counts as $unit => $count) {
			if ($count > 0) {
				$parts[] = $this->unit(unit: $unit, count: $count);
			}
		}

		return implode(', ', $parts);
	}//end words()

	/**
	 * One unit of a duration in words ("8 weeks").
	 *
	 * @param string $unit  The unit: year, month, week or day.
	 * @param int    $count How many.
	 *
	 * @return string The words.
	 */
	private function unit(string $unit, int $count): string {
		return match ($unit) {
			'year'  => $this->l10n->n('%n year', '%n years', $count),
			'month' => $this->l10n->n('%n month', '%n months', $count),
			'week'  => $this->l10n->n('%n week', '%n weeks', $count),
			default => $this->l10n->n('%n day', '%n days', $count),
		};
	}//end unit()
}//end class
