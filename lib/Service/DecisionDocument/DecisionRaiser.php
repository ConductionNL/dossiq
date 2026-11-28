<?php

/**
 * Dossiq Decision Raiser
 *
 * Raises a case's decision in the decision app and answers its reference,
 * failing closed when the decision app is not wired or does not raise it.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\DecisionDocument
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/method-decomposition/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\DecisionDocument;

use OCA\Dossiq\Kernel\AppIdentity;
use OCA\Dossiq\Service\ContractDecisionDelegationService;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\WooDecisionSubject;
use OCA\Dossiq\Woo\WooRefusalGrounds;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Raises the decision on a case in the decision app.
 *
 * @spec openspec/changes/dossiq-decisions-to-decidiq/specs/dossiq-decisions-to-decidiq/spec.md#requirement-req-ddtd-001-no-local-decision-authoring
 * @spec openspec/changes/method-decomposition/tasks.md
 */
final class DecisionRaiser {

	/**
	 * Constructor.
	 *
	 * @param SettingsService                        $settingsService    Where the case schema is configured.
	 * @param LoggerInterface                        $logger             Logger.
	 * @param ContractDecisionDelegationService|null $decisionDelegation Raises the decision in decidiq;
	 *                                                                   without it nothing is raised (fail closed).
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
		private readonly ?ContractDecisionDelegationService $decisionDelegation = null,
	) {
	}//end __construct()

	/**
	 * Raise the decision on the request in decidiq and answer its reference.
	 *
	 * Fails closed: without the delegation seam, or when decidiq refuses or is
	 * unavailable, no decision is assembled and nothing is written locally.
	 *
	 * @param WooDecisionSubject $subject The case UUID, its register, the composed record and its label.
	 * @param string             $actorId The user the decision is raised for.
	 *
	 * @return string The decidiq decision reference.
	 *
	 * @throws RuntimeException When the decision cannot be raised.
	 *
	 * @spec openspec/changes/dossiq-decisions-to-decidiq/specs/dossiq-decisions-to-decidiq/spec.md#requirement-req-ddtd-001-no-local-decision-authoring
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function raise(WooDecisionSubject $subject, string $actorId): string {
		$caseId = $subject->caseId;
		if ($this->decisionDelegation === null) {
			throw new RuntimeException('Decision service unavailable: the decision seam is not wired');
		}

		try {
			return $this->decisionDelegation->raiseDecision(
				decisionType: ContractDecisionDelegationService::DECISION_TYPE_WOO_DECISION,
				externalReference: $caseId,
				subject: $this->decisionSubject(subject: $subject),
				context: self::decisionContext(actorId: $actorId, record: $subject->record),
			);
		} catch (RuntimeException $exception) {
			throw $this->notRaised(caseId: $caseId, exception: $exception);
		}
	}//end raise()

	/**
	 * The subject a decision is raised on: the case.
	 *
	 * @param WooDecisionSubject $subject The case UUID, its register, the composed record and its label.
	 *
	 * @return array<string, mixed> The subject.
	 *
	 * @spec openspec/changes/dossiq-decisions-to-decidiq/specs/dossiq-decisions-to-decidiq/spec.md#requirement-req-ddtd-001-no-local-decision-authoring
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	private function decisionSubject(WooDecisionSubject $subject): array {
		return [
			'subjectRegister' => $subject->register,
			'subjectSchema' => (string)$this->settingsService->getConfigValue('case_schema'),
			'subjectId' => $subject->caseId,
			'subjectLabel' => $subject->label,
		];
	}//end decisionSubject()

	/**
	 * The context a decision is raised with: who, and the assessment tally.
	 *
	 * @param string               $actorId The user the decision is raised for.
	 * @param array<string, mixed> $record  The composed case title and assessment tally.
	 *
	 * @return array<string, mixed> The context.
	 *
	 * @spec openspec/changes/dossiq-decisions-to-decidiq/specs/dossiq-decisions-to-decidiq/spec.md#requirement-req-ddtd-001-no-local-decision-authoring
	 */
	private static function decisionContext(string $actorId, array $record): array {
		return [
			'actorId' => $actorId,
			'summary' => $record['summary'],
			'weigeringsgronden' => $record['weigeringsgronden'],
			'groundsListVersion' => WooRefusalGrounds::LIST_VERSION,
			'assessmentCount' => $record['assessmentCount'],
		];
	}//end decisionContext()

	/**
	 * Log a decision decidiq did not raise, and the error that fails the decision closed.
	 *
	 * @param string           $caseId    The case UUID.
	 * @param RuntimeException $exception Why decidiq did not raise it.
	 *
	 * @return RuntimeException The error to throw.
	 *
	 * @spec openspec/changes/dossiq-decisions-to-decidiq/specs/dossiq-decisions-to-decidiq/spec.md#requirement-req-ddtd-001-no-local-decision-authoring
	 */
	private function notRaised(string $caseId, RuntimeException $exception): RuntimeException {
		$this->logger->error(
			'WOO besluit for case ' . $caseId . ' not raised in decidiq; failing closed: ' . $exception->getMessage(),
			['app' => AppIdentity::APP_ID],
		);
		return new RuntimeException('Decision service unavailable: ' . $exception->getMessage(), 0, $exception);
	}//end notRaised()
}//end class
