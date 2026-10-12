<?php

/**
 * Dossiq decision record composer.
 *
 * What a decision record says about its case: the case's title, and the
 * case's assessments tallied by classification with the distinct grounds
 * they cite. A case whose title cannot be read costs the record its title,
 * not the decision.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\DecisionDocument
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
 * @spec openspec/changes/woo-case-type/tasks.md#task-7
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\DecisionDocument;

use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use Psr\Log\LoggerInterface;

/**
 * Composes the case-derived fields of a decision record.
 *
 * @spec openspec/changes/woo-case-type/tasks.md#task-7
 */
class DecisionRecordComposer {

	use SearchesObjects;

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService The case and assessment schemas.
	 * @param LoggerInterface $logger          Logger.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The case's title and its assessments, tallied.
	 *
	 * @param object $objectService The OpenRegister ObjectService.
	 * @param string $register      The dossiq register.
	 * @param string $caseId        The case UUID.
	 *
	 * @return array{caseTitle: string, assessmentCount: int, summary: array<string, int>, weigeringsgronden: array<int, mixed>}
	 *
	 * @spec openspec/changes/woo-case-type/tasks.md#task-7
	 */
	public function compose(object $objectService, string $register, string $caseId): array {
		$assessments = $this->collectAssessments(
			objectService: $objectService,
			register: $register,
			assessmentSchema: $this->settingsService->getConfigValue('woo_assessment_schema'),
			caseId: $caseId,
		);

		return [
			'caseTitle' => $this->readCaseTitle(objectService: $objectService, register: $register, caseId: $caseId),
			'assessmentCount' => count($assessments),
		] + $this->summariseAssessments(assessments: $assessments);
	}//end compose()

	/**
	 * The title of the case, or '' when it cannot be read.
	 *
	 * A case that cannot be read costs the summary its title, not the decision.
	 *
	 * @param object $objectService The OpenRegister ObjectService.
	 * @param string $register The dossiq register.
	 * @param string $caseId The case UUID.
	 *
	 * @return string The title.
	 *
	 * @spec openspec/specs/woo-publication-via-opencatalogi/spec.md
	 */
	private function readCaseTitle(object $objectService, string $register, string $caseId): string {
		$caseSchema = $this->settingsService->getConfigValue('case_schema');
		if ($caseSchema === '') {
			return '';
		}

		try {
			$case = $this->findObjectAsArray(
				objectService: $objectService,
				register: $register,
				schema: $caseSchema,
				id: $caseId,
			);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'DecisionRecordComposer: the case title could not be read for the decision summary',
				['app' => Application::APP_ID, 'caseId' => $caseId, 'error' => $e->getMessage()],
			);
			return '';
		}

		$title = $case['title'] ?? '';
		if (is_string($title) === false) {
			return '';
		}

		return $title;
	}//end readCaseTitle()

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
}//end class
