<?php

/**
 * Dossiq Woo case ledger
 *
 * The two things publishing reads and writes on the case itself
 * (woo-publish-decision-from-the-case D-1 and D-2): which decision on the case
 * is its Woo decision, and the publication state the case shows
 * (`wooPublicationStatus`, `wooPublicationUrl`). Split from
 * WooPublicationService so the publication flow reads on its own.
 *
 * @category Woo
 * @package  OCA\Dossiq\Woo
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/woo-publish-decision-from-the-case/specs/woo-publication-via-opencatalogi/spec.md#requirement-the-publish-endpoints-find-the-cases-woo-decision-req-wpi-005
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Woo;

use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Reads the case's Woo decision and writes the case's publication state.
 *
 * @spec openspec/changes/woo-publish-decision-from-the-case/specs/woo-publication-via-opencatalogi/spec.md#requirement-the-publish-endpoints-find-the-cases-woo-decision-req-wpi-005
 */
class WooCaseLedger {

	use SearchesObjects;

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Register, schemas and OpenRegister.
	 * @param LoggerInterface $logger          Logger.
	 * @param IURLGenerator|null $urlGenerator Makes the publication link absolute.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
		private readonly ?IURLGenerator $urlGenerator = null,
	) {
	}//end __construct()

	/**
	 * An absolute link for the resident, or the path when no URL generator is wired.
	 *
	 * @param string $path The instance-local path.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/woo-publish-decision-from-the-case/specs/woo-publication-via-opencatalogi/spec.md#requirement-publication-status-surfaced-on-the-woo-assessment-view
	 */
	public function absolute(string $path): string {
		if ($this->urlGenerator === null) {
			return $path;
		}

		return $this->urlGenerator->getAbsoluteURL($path);
	}//end absolute()

	/**
	 * The case's one Woo decision: the decision on the case that carries a `wooSummary`.
	 *
	 * @param string $caseId The case UUID.
	 *
	 * @return array{decisionId: string, refusal: array<string, mixed>}
	 *
	 * @spec openspec/changes/woo-publish-decision-from-the-case/specs/woo-publication-via-opencatalogi/spec.md#requirement-the-publish-endpoints-find-the-cases-woo-decision-req-wpi-005
	 */
	public function resolveWooDecision(string $caseId): array {
		$objectService = $this->settingsService->getObjectService();
		$ids = [];
		if ($objectService !== null) {
			$rows = $this->searchObjectsAsArrays(
				objectService: $objectService,
				register: $this->settingsService->getConfigValue('register'),
				schema: $this->settingsService->getConfigValue('decision_schema'),
				filters: ['case' => $caseId, '_limit' => 50],
			);
			foreach ($rows as $row) {
				if ((string)($row['case'] ?? '') !== $caseId || empty($row['wooSummary']) === true) {
					continue;
				}

				$ids[] = (string)($row['@self']['id'] ?? $row['id'] ?? $row['uuid'] ?? '');
			}
		}

		$ids = array_values(array_filter(array_unique($ids)));
		if (count($ids) === 1) {
			return ['decisionId' => $ids[0], 'refusal' => []];
		}

		if ($ids === []) {
			return ['decisionId' => '', 'refusal' => ['available' => false, 'reason' => 'no_woo_decision']];
		}

		return ['decisionId' => '', 'refusal' => ['available' => false, 'reason' => 'several_woo_decisions', 'decisionIds' => $ids]];
	}//end resolveWooDecision()

	/**
	 * Mirror the publication state onto the case (design D-2), through the PATCH seam.
	 *
	 * @param string               $caseId  The case UUID.
	 * @param array<string, mixed> $changes The two fields.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/woo-publish-decision-from-the-case/specs/woo-publication-via-opencatalogi/spec.md#requirement-publication-status-surfaced-on-the-woo-assessment-view
	 */
	public function writeCaseState(string $caseId, array $changes): void {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null || $caseId === '') {
			return;
		}

		try {
			$this->patchObjectAsArray(
				objectService: $objectService,
				register: $this->settingsService->getConfigValue('register'),
				schema: $this->settingsService->getConfigValue('case_schema'),
				id: $caseId,
				changes: $changes,
			);
		} catch (Throwable $e) {
			// The decision holds the truth; the case mirror is corrected by the next write.
			$this->logger->warning(
				'WooPublicationService: the case could not show its publication state',
				['app' => Application::APP_ID, 'caseId' => $caseId, 'error' => $e->getMessage()],
			);
		}
	}//end writeCaseState()
}//end class
