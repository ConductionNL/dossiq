<?php

/**
 * Procest WOO Decision Service
 *
 * Service for assembling the formal WOO besluit. Aggregates all document
 * assessments and writes a decision object linked to the case. Guards besluit
 * creation until every document has a classification with (where needed)
 * weigeringsgrond per WOO Art. 5.1/5.2.
 *
 * @category Service
 * @package  OCA\Procest\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/woo-case-type/tasks.md#task-7
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Procest\Service;

use InvalidArgumentException;
use OCA\Procest\AppInfo\Application;
use OCA\Procest\Service\Support\SearchesObjects;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Service for assembling the formal WOO besluit.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/changes/woo-case-type/tasks.md#task-7
 */
class WOODecisionService {

	use SearchesObjects;

	/**
	 * Decision type name for WOO besluiten.
	 */
	private const DECISION_TYPE_TITLE = 'WOO-besluit';

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Settings service
	 * @param WOODocumentAssessmentService $assessmentService Document assessment service
	 * @param IUserSession $userSession Current user session
	 * @param LoggerInterface $logger Logger
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly WOODocumentAssessmentService $assessmentService,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Assemble the formal WOO besluit for a case.
	 *
	 * Validates that all documents are assessed, then writes a decision object
	 * linked to the case referencing all assessments and weigeringsgronden.
	 *
	 * @param string $caseId The case UUID
	 * @param array<string, mixed> $decisionData Optional override data (besluitdatum, samenvatting)
	 *
	 * @return array<string, mixed> Created decision with ID and assessment summary
	 *
	 * @throws \RuntimeException If OpenRegister unavailable or case not found
	 * @throws \InvalidArgumentException If any document has not been assessed
	 *
	 * @spec openspec/changes/woo-case-type/tasks.md#task-7
	 */
	public function assembleDecision(string $caseId, array $decisionData = []): array {
		// Guard: all documents must be assessed before a besluit can be created.
		$this->assertAllDocumentsAssessed(caseId: $caseId);

		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			throw new RuntimeException('OpenRegister is not available');
		}

		$register = $this->settingsService->getConfigValue('register');
		$decisionSchema = $this->settingsService->getConfigValue('decision_schema');
		$assessmentSchema = $this->settingsService->getConfigValue('woo_assessment_schema');

		if (empty($register) === true || empty($decisionSchema) === true) {
			throw new RuntimeException('Decision schema not configured');
		}

		// Collect all assessments for the case.
		$assessments = $this->collectAssessments(
			objectService: $objectService,
			register: $register,
			assessmentSchema: $assessmentSchema,
			caseId: $caseId,
		);

		// Summarise assessments by classification.
		$summarised = $this->summariseAssessments(assessments: $assessments);
		$summary = $summarised['summary'];
		$weigeringsgronden = $summarised['weigeringsgronden'];

		$userId = $this->resolveDecidedBy();

		$besluitData = array_merge(
			[
				'case' => $caseId,
				'decisionType' => self::DECISION_TYPE_TITLE,
				'decisionDate' => date('Y-m-d'),
				'description' => 'WOO besluit voor zaak ' . $caseId,
				'wooSummary' => $summary,
				'weigeringsgronden' => $weigeringsgronden,
				'assessmentCount' => count($assessments),
				'decidedBy' => $userId,
			],
			$decisionData,
		);

		$decision = $objectService->saveObject(object: $besluitData, register: $register, schema: $decisionSchema);

		$this->logger->info(
			'WOO besluit assembled for case ' . $caseId . ': decision ' . $decision->getUuid(),
			['app' => Application::APP_ID],
		);

		return [
			'decisionId' => $decision->getUuid(),
			'caseId' => $caseId,
			'summary' => $summary,
			'weigeringsgronden' => $weigeringsgronden,
			'assessmentCount' => count($assessments),
		];
	}//end assembleDecision()

	/**
	 * Guard that every document of a case carries an assessment.
	 *
	 * @param string $caseId The case UUID
	 *
	 * @return void
	 *
	 * @throws \InvalidArgumentException If any document has not been assessed
	 */
	private function assertAllDocumentsAssessed(string $caseId): void {
		$outstanding = $this->assessmentService->getOutstanding(caseId: $caseId);
		if ($outstanding['count'] > 0) {
			throw new InvalidArgumentException(
				'Cannot create besluit: ' . $outstanding['count'] . ' document(s) still need assessment. '
				. 'Document IDs: ' . implode(', ', $outstanding['documents'])
			);
		}
	}//end assertAllDocumentsAssessed()

	/**
	 * Fetch all assessment objects belonging to a case.
	 *
	 * @param object $objectService OpenRegister object service
	 * @param mixed $register Configured register identifier
	 * @param mixed $assessmentSchema Configured assessment schema identifier
	 * @param string $caseId The case UUID
	 *
	 * @return array<int, array<string, mixed>> Assessment rows, empty when the schema is not configured
	 */
	private function collectAssessments(object $objectService, mixed $register, mixed $assessmentSchema, string $caseId): array {
		if (empty($assessmentSchema) === true) {
			return [];
		}

		// The is_array() guard the inline version carried here is dead code:
		// searchObjectsAsArrays() is declared `: array`. Dropped rather than
		// inverted, because phpstan rejects it in either direction.
		return $this->searchObjectsAsArrays(
			objectService: $objectService,
			register: $register,
			schema: $assessmentSchema,
			filters: ['caseRef' => $caseId, '_limit' => 500],
		);
	}//end collectAssessments()

	/**
	 * Tally assessments by classification and collect the distinct weigeringsgronden.
	 *
	 * @param array<int, array<string, mixed>> $assessments Assessment rows
	 *
	 * @return array{summary: array<string, int>, weigeringsgronden: array<int, mixed>} Counts per classification plus distinct grounds
	 */
	private function summariseAssessments(array $assessments): array {
		$summary = [
			'openbaar' => 0,
			'deels_openbaar' => 0,
			'niet_openbaar' => 0,
		];

		$weigeringsgronden = [];
		foreach ($assessments as $assessment) {
			$classification = $assessment['classification'] ?? null;
			if ($classification !== null && isset($summary[$classification]) === true) {
				$summary[$classification]++;
			}

			foreach (($assessment['weigeringsgronden'] ?? []) as $code) {
				if (in_array($code, $weigeringsgronden, true) === false) {
					$weigeringsgronden[] = $code;
				}
			}
		}

		return [
			'summary' => $summary,
			'weigeringsgronden' => $weigeringsgronden,
		];
	}//end summariseAssessments()

	/**
	 * Resolve the user id credited with the besluit.
	 *
	 * @return string The current user id, or `system` when there is no session user
	 */
	private function resolveDecidedBy(): string {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return 'system';
		}

		return $user->getUID();
	}//end resolveDecidedBy()
}//end class
