<?php

/**
 * Dossiq Local Decision Record
 *
 * The decision as the app records it on the case: the composed record the
 * decision is raised with, and the besluit object saved once the decision
 * app raised it, after which the case is marked ready to publish.
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
use OCA\Dossiq\Service\AssembledDecision;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\RequiresObjectService;
use OCA\Dossiq\Service\WooDecisionSubject;
use OCA\Dossiq\Woo\WooRefusalGrounds;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Composes and saves the decision record of a case.
 *
 * @spec openspec/changes/archive/2026-06-13-woo-case-type/tasks.md#task-7
 * @spec openspec/changes/method-decomposition/tasks.md
 */
final class LocalDecisionRecord {

	use RequiresObjectService;

	/**
	 * The seeded decision type the record is saved under (register.d/81-woo-verzoek.json).
	 *
	 * A uuid reference to a decisionType row, never its name: OpenRegister
	 * refuses the name on save.
	 *
	 * @var string
	 */
	public const DECISION_TYPE_ID = '3c0f5a00-0000-4000-a000-00000000d001';

	/**
	 * Composes the case's title and the assessment tally for the decision record.
	 *
	 * @var DecisionRecordComposer
	 */
	private readonly DecisionRecordComposer $composer;

	/**
	 * Marks the case ready to publish once the decision is saved.
	 *
	 * @var PublicationReadiness
	 */
	private readonly PublicationReadiness $readiness;

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Where OpenRegister, the register and the decision schema are configured.
	 * @param LoggerInterface $logger          Logger.
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
	) {
		$this->composer  = new DecisionRecordComposer(settingsService: $settingsService, logger: $logger);
		$this->readiness = new PublicationReadiness(settingsService: $settingsService, logger: $logger);
	}//end __construct()

	/**
	 * The register and the composed record of a case: its title and its assessments, tallied by classification.
	 *
	 * @param string $caseId The case UUID.
	 *
	 * @return array{register: string, record: array<string, mixed>} The register and the record.
	 *
	 * @throws RuntimeException If OpenRegister is unavailable or the decision schema is not configured.
	 *
	 * @spec openspec/changes/archive/2026-06-13-woo-case-type/tasks.md#task-7
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function compose(string $caseId): array {
		$objectService = $this->requireObjectService();
		[$register] = $this->requireDecisionSchema();

		return [
			'register' => $register,
			'record' => $this->composer->compose(objectService: $objectService, register: $register, caseId: $caseId),
		];
	}//end compose()

	/**
	 * Save the besluit on the case, mark the case ready to publish and log it.
	 *
	 * @param WooDecisionSubject   $subject      The case, its register, the composed record and its label.
	 * @param string               $userId       The deciding user.
	 * @param array<string, mixed> $decisionData Optional override data (besluitdatum, samenvatting).
	 * @param string               $decisionRef  The decidiq decision reference.
	 *
	 * @return string The saved decision's uuid.
	 *
	 * @throws RuntimeException If OpenRegister is unavailable or the decision schema is not configured.
	 *
	 * @spec openspec/changes/archive/2026-06-13-woo-case-type/tasks.md#task-7
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function record(WooDecisionSubject $subject, string $userId, array $decisionData, string $decisionRef): string {
		$objectService = $this->requireObjectService();
		[$register, $decisionSchema] = $this->requireDecisionSchema();
		$caseId = $subject->caseId;

		$decision = $objectService->saveObject(
			object: $this->besluitData(subject: $subject, userId: $userId, decisionData: $decisionData),
			options: new ObjectSaveOptions(target: new ObjectFindTarget(register: $register, schema: $decisionSchema))
		);

		$this->readiness->markCaseReady(objectService: $objectService, register: $register, caseId: $caseId, decisionId: (string)$decision->getUuid());

		$this->logAssembled(assembled: new AssembledDecision(caseId: $caseId, decisionRef: $decisionRef, decision: $decision));

		return (string)$decision->getUuid();
	}//end record()

	/**
	 * Log that a WOO besluit was assembled.
	 *
	 * @param AssembledDecision $assembled The case, the saved decision object and the decidiq reference
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-06-13-woo-case-type/tasks.md#task-7
	 */
	private function logAssembled(AssembledDecision $assembled): void {
		$this->logger->info(
			'WOO besluit assembled for case ' . $assembled->caseId . ': decision ' . $assembled->decision->getUuid(),
			['app' => AppIdentity::APP_ID, 'decisionRef' => $assembled->decisionRef],
		);
	}//end logAssembled()

	/**
	 * The configured register and decision schema.
	 *
	 * @return array{0: string, 1: string} The register and the decision schema
	 *
	 * @throws RuntimeException If either is not configured
	 *
	 * @spec openspec/changes/archive/2026-06-13-woo-case-type/tasks.md#task-7
	 */
	private function requireDecisionSchema(): array {
		$register = $this->settingsService->getConfigValue('register');
		$decisionSchema = $this->settingsService->getConfigValue('decision_schema');

		if (empty($register) === true || empty($decisionSchema) === true) {
			throw new RuntimeException('Decision schema not configured');
		}

		return [$register, $decisionSchema];
	}//end requireDecisionSchema()

	/**
	 * The besluit object to save, with the caller's override data merged over it.
	 *
	 * @param WooDecisionSubject   $subject      The case, the composed record and its label.
	 * @param string               $userId       The deciding user
	 * @param array<string, mixed> $decisionData Optional override data
	 *
	 * @return array<string, mixed> The besluit data
	 *
	 * @spec openspec/changes/archive/2026-06-13-woo-case-type/tasks.md#task-7
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	private function besluitData(WooDecisionSubject $subject, string $userId, array $decisionData): array {
		$record = $subject->record;

		return array_merge(
			[
				'case' => $subject->caseId,
				'decisionType' => self::DECISION_TYPE_ID,
				'decisionDate' => date('Y-m-d'),
				'description' => $subject->label,
				'wooSummary' => $record['summary'],
				'weigeringsgronden' => $record['weigeringsgronden'],
				'groundsListVersion' => WooRefusalGrounds::LIST_VERSION,
				'assessmentCount' => $record['assessmentCount'],
				'decidedBy' => $userId,
			],
			array_diff_key($decisionData, ['id' => true, 'uuid' => true, '@self' => true]),
		);
	}//end besluitData()
}//end class
