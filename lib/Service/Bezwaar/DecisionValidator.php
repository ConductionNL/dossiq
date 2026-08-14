<?php

/**
 * Procest Bezwaar Decision Validator.
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
 * Per REQ-PDRD-004 these rules stay in procest and run BEFORE any
 * decidesk Decision is raised, so no Decision can ever be raised on an
 * Awb-invalid payload. The class is deliberately dependency-free: it is
 * pure payload inspection.
 *
 * @category Service
 * @package  OCA\Procest\Service\Bezwaar
 *
 * @author    Conduction Development Team <dev@conduction.nl>
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
 * @spec openspec/specs/bezwaar-decision/spec.md
 */

declare(strict_types=1);

namespace OCA\Procest\Service\Bezwaar;

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
		'niet_ontvankelijk',
		'ongegrond',
		'gegrond_handhaven',
		'gegrond_herroepen',
		'gegrond_wijzigen',
	];

	/**
	 * Dispositions for which a replacementDecision is allowed/required.
	 *
	 * @var array<int, string>
	 */
	private const REPLACEMENT_ALLOWED = [
		'gegrond_herroepen',
		'gegrond_wijzigen',
	];

	/**
	 * Dispositions for which proceskostenvergoeding may be awarded
	 * (Awb art. 7:15 lid 2).
	 *
	 * @var array<int, string>
	 */
	private const PROCESKOSTEN_ELIGIBLE = [
		'gegrond_herroepen',
		'gegrond_wijzigen',
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
		$disposition = (string)($payload['dispositionType'] ?? '');
		if (in_array($disposition, self::VALID_DISPOSITIONS, true) === false) {
			throw new RuntimeException(
				'Invalid disposition — must be one of the five canonical Awb '
				. '7:11 values'
			);
		}

		$reasoning = (string)($payload['reasoning'] ?? '');
		$legalBasis = (string)($payload['legalBasis'] ?? '');
		if ($reasoning === '' || $legalBasis === '') {
			throw new RuntimeException(
				'reasoning and legalBasis are required (Awb art. 7:12)'
			);
		}

		$replacement = (string)($payload['replacementDecision'] ?? '');
		if ($replacement !== ''
			&& in_array($disposition, self::REPLACEMENT_ALLOWED, true) === false
		) {
			throw new RuntimeException(
				'replacementDecision MUST NOT be set when disposition is not '
				. 'gegrond_herroepen or gegrond_wijzigen'
			);
		}
	}//end assertDraftable()

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
		$disposition = (string)($decision['dispositionType'] ?? '');
		if (in_array($disposition, self::VALID_DISPOSITIONS, true) === false) {
			throw new RuntimeException(
				'dispositionType is invalid — refusing to publish'
			);
		}

		// REQ-BD-3: gegrond_wijzigen requires replacementDecision.
		$replacement = (string)($decision['replacementDecision'] ?? '');
		if ($disposition === 'gegrond_wijzigen' && $replacement === '') {
			throw new RuntimeException(
				'replacementDecision is required when disposition is '
				. 'gegrond_wijzigen'
			);
		}

		// REQ-BD-3: ongegrond and gegrond_handhaven MUST NOT carry one.
		if ($replacement !== ''
			&& in_array($disposition, self::REPLACEMENT_ALLOWED, true) === false
		) {
			throw new RuntimeException(
				'replacementDecision MUST NOT be set when disposition is '
				. $disposition
			);
		}

		// REQ-BD-5: deviationRationale required when advisoryOpinion is
		// set and the decision deviates.
		$advisory = (string)($decision['advisoryOpinion'] ?? '');
		if ($advisory !== '') {
			$follows = (bool)($decision['followsAdvice'] ?? true);
			$reason = (string)($decision['deviationRationale'] ?? '');
			if ($follows === false && $reason === '') {
				throw new RuntimeException(
					'deviationRationale is required when followsAdvice is '
					. 'false (Awb art. 7:13 lid 7)'
				);
			}
		}

		// REQ-BD-6: appealNotice completeness.
		$this->assertAppealNoticeComplete(decision: $decision);

		// REQ-BD-7: proceskostenvergoeding rules.
		$this->assertProceskostenRules(decision: $decision);
	}//end assertPublishable()

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
		foreach (self::APPEAL_NOTICE_BASE_REQUIRED as $field) {
			$value = (string)($appealNotice[$field] ?? '');
			if ($value === '') {
				throw new RuntimeException(
					'Rechtsmiddelenclausule onvolledig: ' . $field . ' ontbreekt'
				);
			}
		}

		$method = (string)$appealNotice['filingMethod'];
		if (in_array($method, ['digitaal', 'beide'], true) === true) {
			$url = (string)($appealNotice['filingUrl'] ?? '');
			if ($url === '') {
				throw new RuntimeException(
					'filingUrl is required when filingMethod is ' . $method
				);
			}
		}

		if (in_array($method, ['schriftelijk', 'beide'], true) === true) {
			$address = (string)($appealNotice['filingAddress'] ?? '');
			if ($address === '') {
				throw new RuntimeException(
					'filingAddress is required when filingMethod is ' . $method
				);
			}
		}
	}//end assertAppealNoticeComplete()

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
		$requested = (bool)($proceskosten['requested'] ?? false);
		$awardedSet = array_key_exists('awarded', $proceskosten);
		$awarded = (bool)($proceskosten['awarded'] ?? false);

		$eligible = in_array(
			$disposition,
			self::PROCESKOSTEN_ELIGIBLE,
			true
		);

		if ($awarded === true && $eligible === false) {
			throw new RuntimeException(
				'Proceskostenvergoeding niet mogelijk: primair besluit niet '
				. 'herroepen (Awb art. 7:15 lid 2)'
			);
		}

		if ($requested === true && $eligible === true && $awardedSet === false) {
			throw new RuntimeException(
				'proceskosten.awarded MUST be explicitly set (true or false '
				. 'with reasoning) when the bezwaarmaker requested '
				. 'legalCostsCompensation'
			);
		}
	}//end assertProceskostenRules()

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
