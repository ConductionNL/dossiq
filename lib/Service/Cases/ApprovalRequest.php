<?php

/**
 * Asking decidiq to walk the approval a gated act waits for.
 *
 * {@see ApprovalGate} refuses a gated act until decidiq reports the approval
 * granted, and refuses it too while no approval has been asked for. This is the
 * other half: the handler asks, decidiq starts the walk, and the case records
 * which decidiq decision the act now waits on.
 *
 * 🔴 IT STARTS THE WALK AND KEEPS THE LINK, NOTHING ELSE. decidiq chooses the
 * approvers, holds the route and decides when it is done. What dossiq stores
 * is the act and the decidiq id (ADR-011), so there is never a second record of
 * who signed that could disagree with the first.
 *
 * 🔴 A DECIDIQ THAT CANNOT BE REACHED IS A REFUSAL WITH A STATUS. The raise
 * already fails closed and throws; this turns that into a 503 the handler can
 * read and retry, rather than a 500 that reads as a broken case (ADR-102).
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Cases
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
 * @link https://conduction.nl
 *
 * @spec openspec/changes/decision-outcomes-on-the-case/specs/besluitvorming-leaf/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Cases;

use DateTimeImmutable;
use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\CaseType\ApprovalGateDeclaration;
use OCA\Dossiq\Service\CaseTypeResolver;
use OCA\Dossiq\Service\ContractDecisionDelegationService;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Transitions\CaseStatusStore;
use RuntimeException;

/**
 * Raises the decidiq decision a gated act waits on, and links it to the case.
 *
 * @spec openspec/changes/decision-outcomes-on-the-case/specs/besluitvorming-leaf/spec.md
 *
 * @SuppressWarnings(PHPMD.StaticAccess) {@see ApprovalGateDeclaration} is a
 * reader over two arrays with no state and nothing to inject.
 */
class ApprovalRequest {

	/**
	 * Constructor.
	 *
	 * @param CaseStatusStore                   $store     Reads and writes the case.
	 * @param CaseTypeResolver                  $caseTypes The effective case type.
	 * @param ContractDecisionDelegationService $decisions The one seam that raises a decidiq decision.
	 * @param SettingsService                   $settings  The register and schema the case lives in.
	 */
	public function __construct(
		private readonly CaseStatusStore $store,
		private readonly CaseTypeResolver $caseTypes,
		private readonly ContractDecisionDelegationService $decisions,
		private readonly SettingsService $settings,
	) {
	}//end __construct()

	/**
	 * Ask decidiq for the approval one act of one case waits on.
	 *
	 * @param string $caseId The case UUID.
	 * @param string $act    The gated act.
	 * @param string $userId Who is asking.
	 *
	 * @return array{act: string, decisionRef: string, raisedAt: string} The link that was recorded.
	 *
	 * @throws RefusedException When the case is gone, the act is not gated, or decidiq cannot be reached.
	 *
	 * @spec openspec/changes/decision-outcomes-on-the-case/specs/besluitvorming-leaf/spec.md#requirement-a-case-is-gated-by-the-approval-outcome-decidiq-walks-req-dec-01
	 */
	public function raise(string $caseId, string $act, string $userId): array {
		$case = $this->store->loadCase(caseId: $caseId);
		if ($case === null) {
			throw new RefusedException(
				rule: 'case-not-found',
				sentence: 'This case could not be found.',
				status: RefusedException::STATUS_UNPROCESSABLE,
			);
		}

		$caseType = $this->caseTypes->effectiveCaseType(caseTypeId: trim((string)($case['caseType'] ?? '')));
		$gate = ApprovalGateDeclaration::gateFor(caseType: $caseType, act: $act);
		if ($gate === []) {
			throw new RefusedException(
				rule: 'approval-not-declared',
				sentence: 'This act does not wait for an approval, so there is nothing to ask for.',
				status: RefusedException::STATUS_REFUSED,
			);
		}

		try {
			$decisionRef = $this->decisions->raiseDecision(
				decisionType: $gate['decisionType'],
				externalReference: $caseId,
				subject: [
					'subjectRegister' => $this->settings->getConfigValue('register'),
					'subjectSchema' => $this->settings->getConfigValue('case_schema'),
					'subjectId' => $caseId,
					'subjectLabel' => $gate['label'] . ': ' . trim((string)($case['title'] ?? $caseId)),
				],
				context: ['actorId' => $userId, 'act' => $gate['act']],
			);
		} catch (RuntimeException $e) {
			throw RefusedException::indeterminate(
				rule: ApprovalGate::RULE_UNREADABLE,
				sentence: 'We could not reach the approval service, so ' . $gate['label']
					. ' was not asked for. Try again shortly.',
				previous: $e,
			);
		}

		$raisedAt = (new DateTimeImmutable())->format('c');
		$case[ApprovalGateDeclaration::REFERENCES] = ApprovalGateDeclaration::withReference(
			case: $case,
			act: $gate['act'],
			decisionRef: $decisionRef,
			raisedAt: $raisedAt,
		);
		$this->store->saveCase(case: $case);

		return ['act' => $gate['act'], 'decisionRef' => $decisionRef, 'raisedAt' => $raisedAt];
	}//end raise()
}//end class
