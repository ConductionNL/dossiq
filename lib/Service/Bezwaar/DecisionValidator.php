<?php

/**
 * Dossiq Bezwaar Decision Validator.
 *
 * The complete Awb validity matrix for a beslissing op bezwaar. Split out
 * of DecisionService so that service keeps only the draft/publish
 * orchestration and the hand-off to decidesk: the rules for what makes a
 * bezwaarDecision legally sound — the canonical art. 7:11 disposition
 * enum, the art. 7:12 motivering, the replacementDecision compatibility
 * matrix, the art. 7:13 lid 7 deviation rationale, the art. 6:23
 * rechtsmiddelenclausule completeness check, and the art. 7:15 lid 2
 * proceskostenvergoeding rules including the point-value total — live
 * here and nowhere else.
 *
 * Per REQ-PDRD-004 these rules stay in dossiq and run BEFORE any
 * decidesk Decision is raised, so no Decision can ever be raised on an
 * Awb-invalid payload. The class is deliberately dependency-free: it is
 * pure payload inspection.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Bezwaar
 *
 * @author    Conduction Development Team <dev@conduction.nl>
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
 * @spec openspec/specs/bezwaar-decision/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Bezwaar;

use RuntimeException;

/**
 * Validates a bezwaarDecision payload against the Awb validity matrix.
 *
 * @spec openspec/specs/bezwaar-decision/spec.md
 */
class DecisionValidator {

	/**
	 * Canonical Awb art. 7:11 disposition values (REQ-BD-2).
	 *
	 * @var array<int, string>
	 */
	public const VALID_DISPOSITIONS = [
		'inadmissible',
		'dismissed',
		'upheld_maintain',
		'upheld_revoke',
		'upheld_amend',
	];

	/**
	 * Dispositions for which a replacementDecision is allowed/required.
	 *
	 * @var array<int, string>
	 */
	private const REPLACEMENT_ALLOWED = [
		'upheld_revoke',
		'upheld_amend',
	];

	/**
	 * Dispositions for which proceskostenvergoeding may be awarded
	 * (Awb art. 7:15 lid 2).
	 *
	 * @var array<int, string>
	 */
	private const PROCESKOSTEN_ELIGIBLE = [
		'upheld_revoke',
		'upheld_amend',
	];

	/**
	 * Required appealNotice sub-fields (REQ-BD-6) regardless of
	 * filingMethod. filingUrl/filingAddress requirements are
	 * conditional on filingMethod and handled separately.
	 *
	 * @var array<int, string>
	 */
	private const APPEAL_NOTICE_BASE_REQUIRED = [
		'competentCourt',
		'appealTerm',
		'effectiveDate',
		'filingMethod',
	];

	/**
	 * Conditional appealNotice sub-fields (REQ-BD-6), in the order they are
	 * checked, each with the filing methods that require it.
	 *
	 * @var array<string, array<int, string>>
	 */
	private const APPEAL_NOTICE_FILING_FIELDS = [
		'filingUrl' => ['digitaal', 'both'],
		'filingAddress' => ['schriftelijk', 'both'],
	];

	/**
	 * Assert the draft-time Awb guards on a bezwaarDecision payload.
	 *
	 * @param array<string, mixed> $payload Decision properties.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When the payload is invalid at draft time.
	 *
	 * @spec openspec/specs/bezwaar-decision/spec.md
	 */
	public function assertDraftable(array $payload): void {
		$disposition = $this->draftableDisposition(payload: $payload);
		$this->assertReasoned(payload: $payload);

		$replacement = (string)($payload['replacementDecision'] ?? '');
		if ($replacement !== '' && $this->allowsReplacement(disposition: $disposition) === false) {
			throw new RuntimeException(
				'replacementDecision MUST NOT be set when disposition is not '
				. 'gegrond_herroepen or gegrond_wijzigen'
			);
		}
	}//end assertDraftable()

	/**
	 * Whether a disposition allows a replacementDecision.
	 *
	 * @param string $disposition The disposition.
	 *
	 * @return bool
	 */
	private function allowsReplacement(string $disposition): bool {
		return in_array($disposition, self::REPLACEMENT_ALLOWED, true);
	}//end allowsReplacement()

	/**
	 * The disposition of a draft, refused when not one of the canonical values.
	 *
	 * @param array<string, mixed> $payload Decision properties.
	 *
	 * @return string The disposition.
	 *
	 * @throws RuntimeException When the disposition is invalid.
	 */
	private function draftableDisposition(array $payload): string {
		$disposition = (string)($payload['dispositionType'] ?? '');
		if (in_array($disposition, self::VALID_DISPOSITIONS, true) === false) {
			throw new RuntimeException(
				'Invalid disposition — must be one of the five canonical Awb '
				. '7:11 values'
			);
		}

		return $disposition;
	}//end draftableDisposition()

	/**
	 * Assert a draft carries both its reasoning and its legal basis (Awb art. 7:12).
	 *
	 * @param array<string, mixed> $payload Decision properties.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When either is missing.
	 */
	private function assertReasoned(array $payload): void {
		$reasoning = (string)($payload['reasoning'] ?? '');
		$legalBasis = (string)($payload['legalBasis'] ?? '');
		if ($reasoning === '' || $legalBasis === '') {
			throw new RuntimeException(
				'reasoning and legalBasis are required (Awb art. 7:12)'
			);
		}
	}//end assertReasoned()

	/**
	 * Run every publication-time guard against a draft decision.
	 *
	 * @param array<string, mixed> $decision The decision payload.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When any guard rejects.
	 *
	 * @spec openspec/specs/bezwaar-decision/spec.md
	 */
	public function assertPublishable(array $decision): void {
		$disposition = $this->publishableDisposition(decision: $decision);

		// REQ-BD-3: the replacementDecision matches the disposition.
		$this->assertReplacementFits(decision: $decision, disposition: $disposition);

		// REQ-BD-5: deviationRationale required when advisoryOpinion is
		// set and the decision deviates.
		$this->assertDeviationReasoned(decision: $decision);

		// REQ-BD-6: appealNotice completeness.
		$this->assertAppealNoticeComplete(decision: $decision);

		// REQ-BD-7: proceskostenvergoeding rules.
		$this->assertProceskostenRules(decision: $decision);
	}//end assertPublishable()

	/**
	 * The disposition of a decision about to be published, refused when not canonical.
	 *
	 * @param array<string, mixed> $decision The decision payload.
	 *
	 * @return string The disposition.
	 *
	 * @throws RuntimeException When the disposition is invalid.
	 */
	private function publishableDisposition(array $decision): string {
		$disposition = (string)($decision['dispositionType'] ?? '');
		if (in_array($disposition, self::VALID_DISPOSITIONS, true) === false) {
			throw new RuntimeException(
				'dispositionType is invalid — refusing to publish'
			);
		}

		return $disposition;
	}//end publishableDisposition()

	/**
	 * Validate the replacementDecision against the disposition (REQ-BD-3).
	 *
	 * Gegrond_wijzigen requires one; ongegrond and gegrond_handhaven MUST NOT carry one.
	 *
	 * @param array<string, mixed> $decision    The decision payload.
	 * @param string               $disposition Its disposition.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When the replacementDecision does not fit.
	 */
	private function assertReplacementFits(array $decision, string $disposition): void {
		$replacement = (string)($decision['replacementDecision'] ?? '');
		if ($disposition === 'upheld_amend' && $replacement === '') {
			throw new RuntimeException(
				'replacementDecision is required when disposition is '
				. 'upheld_amend'
			);
		}

		if ($replacement !== '' && $this->allowsReplacement(disposition: $disposition) === false) {
			throw new RuntimeException(
				'replacementDecision MUST NOT be set when disposition is '
				. $disposition
			);
		}
	}//end assertReplacementFits()

	/**
	 * Validate that a decision deviating from the advisory opinion says why (REQ-BD-5).
	 *
	 * @param array<string, mixed> $decision The decision payload.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When the deviationRationale is missing.
	 */
	private function assertDeviationReasoned(array $decision): void {
		$advisory = (string)($decision['advisoryOpinion'] ?? '');
		if ($advisory === '') {
			return;
		}

		$follows = (bool)($decision['followsAdvice'] ?? true);
		$reason = (string)($decision['deviationRationale'] ?? '');
		if ($follows === false && $reason === '') {
			throw new RuntimeException(
				'deviationRationale is required when followsAdvice is '
				. 'false (Awb art. 7:13 lid 7)'
			);
		}
	}//end assertDeviationReasoned()

	/**
	 * Validate the rechtsmiddelenclausule (REQ-BD-6).
	 *
	 * @param array<string, mixed> $decision Decision payload.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When the appealNotice is incomplete.
	 *
	 * @spec openspec/specs/bezwaar-decision/spec.md
	 */
	private function assertAppealNoticeComplete(array $decision): void {
		$appealNotice = (array)($decision['appealNotice'] ?? []);
		$this->assertAppealNoticeBaseFields(appealNotice: $appealNotice);
		$this->assertAppealNoticeFilingFields(appealNotice: $appealNotice);
	}//end assertAppealNoticeComplete()

	/**
	 * Validate the appealNotice sub-fields every filing method requires.
	 *
	 * @param array<string, mixed> $appealNotice The appealNotice.
	 *
	 * @return void
	 *
	 * @throws RuntimeException Naming the first missing field.
	 */
	private function assertAppealNoticeBaseFields(array $appealNotice): void {
		foreach (self::APPEAL_NOTICE_BASE_REQUIRED as $field) {
			$value = (string)($appealNotice[$field] ?? '');
			if ($value === '') {
				throw new RuntimeException(
					'Rechtsmiddelenclausule onvolledig: ' . $field . ' ontbreekt'
				);
			}
		}
	}//end assertAppealNoticeBaseFields()

	/**
	 * Validate the filingUrl and filingAddress the filing method requires.
	 *
	 * @param array<string, mixed> $appealNotice The appealNotice, its base fields present.
	 *
	 * @return void
	 *
	 * @throws RuntimeException Naming the first missing field and the filing method.
	 */
	private function assertAppealNoticeFilingFields(array $appealNotice): void {
		$method = (string)$appealNotice['filingMethod'];
		foreach (self::APPEAL_NOTICE_FILING_FIELDS as $field => $methods) {
			if (in_array($method, $methods, true) === true
				&& (string)($appealNotice[$field] ?? '') === ''
			) {
				throw new RuntimeException(
					$field . ' is required when filingMethod is ' . $method
				);
			}
		}
	}//end assertAppealNoticeFilingFields()

	/**
	 * Validate the proceskostenvergoeding decision (REQ-BD-7).
	 *
	 * @param array<string, mixed> $decision Decision payload.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When proceskosten rules are violated.
	 *
	 * @spec openspec/specs/bezwaar-decision/spec.md
	 */
	private function assertProceskostenRules(array $decision): void {
		$disposition = (string)($decision['dispositionType'] ?? '');
		$proceskosten = (array)($decision['legalCostsCompensation'] ?? []);
		$awarded = (bool)($proceskosten['awarded'] ?? false);
		$eligible = $this->allowsCostsCompensation(disposition: $disposition);

		if ($awarded === true && $eligible === false) {
			throw new RuntimeException(
				'Proceskostenvergoeding niet mogelijk: primair besluit niet '
				. 'herroepen (Awb art. 7:15 lid 2)'
			);
		}

		if ($eligible === true) {
			$this->assertAwardDecided(proceskosten: $proceskosten);
		}
	}//end assertProceskostenRules()

	/**
	 * Whether a disposition allows a proceskostenvergoeding (Awb art. 7:15 lid 2).
	 *
	 * @param string $disposition The disposition.
	 *
	 * @return bool
	 */
	private function allowsCostsCompensation(string $disposition): bool {
		return in_array(
			$disposition,
			self::PROCESKOSTEN_ELIGIBLE,
			true
		);
	}//end allowsCostsCompensation()

	/**
	 * Assert an eligible, requested legalCostsCompensation states whether it is awarded.
	 *
	 * @param array<string, mixed> $proceskosten The legalCostsCompensation.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When it was requested and `awarded` is not set.
	 */
	private function assertAwardDecided(array $proceskosten): void {
		$requested = (bool)($proceskosten['requested'] ?? false);
		if ($requested === true && array_key_exists('awarded', $proceskosten) === false) {
			throw new RuntimeException(
				'proceskosten.awarded MUST be explicitly set (true or false '
				. 'with reasoning) when the bezwaarmaker requested '
				. 'legalCostsCompensation'
			);
		}
	}//end assertAwardDecided()

	/**
	 * Compute proceskosten.totalAmount = awardedPoints * pointValue.
	 *
	 * @param array<string, mixed> $decision Decision payload.
	 *
	 * @return float|null The recomputed total, or null when no recalculation is needed.
	 *
	 * @spec openspec/specs/bezwaar-decision/spec.md
	 */
	public function computeProceskostenTotal(array $decision): ?float {
		$proceskosten = (array)($decision['legalCostsCompensation'] ?? []);
		if (($proceskosten['awarded'] ?? false) !== true) {
			return null;
		}

		$points = (float)($proceskosten['awardedPoints'] ?? 0);
		$value = (float)($proceskosten['pointValue'] ?? 0);
		if ($points <= 0.0 || $value <= 0.0) {
			return null;
		}

		return ($points * $value);
	}//end computeProceskostenTotal()
}//end class
