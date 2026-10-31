<?php

/**
 * Dossiq publication readiness.
 *
 * Marks a case ready to publish once its decision is assembled: the case
 * reads `wooPublicationStatus: ready`, which is what shows the publish header
 * action. A case that was published before keeps its `published` state, and
 * a case that cannot be marked costs the header action, not the decision.
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
 * @spec openspec/specs/woo-publication-via-opencatalogi/spec.md#requirement-publication-status-surfaced-on-the-woo-assessment-view
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\DecisionDocument;

use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use Psr\Log\LoggerInterface;

/**
 * Marks a case ready to publish its decision.
 *
 * @spec openspec/specs/woo-publication-via-opencatalogi/spec.md#requirement-publication-status-surfaced-on-the-woo-assessment-view
 */
class PublicationReadiness {

	use SearchesObjects;

	/**
	 * Lists the recorded decision on its case.
	 *
	 * @var CaseDecisionLink
	 */
	private readonly CaseDecisionLink $decisionLink;

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService The case schema.
	 * @param LoggerInterface $logger          Logger.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
	) {
		$this->decisionLink = new CaseDecisionLink(settingsService: $settingsService, logger: $logger);
	}//end __construct()

	/**
	 * The case can now be published: it reads `wooPublicationStatus: ready`
	 * (woo-publish-decision-from-the-case design D-2), which is what shows the
	 * Publish (Woo) header action. A decision that was published before keeps
	 * the case's `published` state; re-assembling does not unpublish.
	 *
	 * @param object $objectService The OpenRegister ObjectService.
	 * @param string $register The dossiq register.
	 * @param string $caseId The case UUID.
	 * @param string $decisionId The recorded decision, listed on the case first; '' lists nothing.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/woo-publication-via-opencatalogi/spec.md#requirement-publication-status-surfaced-on-the-woo-assessment-view
	 * @spec openspec/specs/case-management/spec.md#requirement-a-recorded-decision-is-listed-on-its-case-req-cm-81
	 */
	public function markCaseReady(object $objectService, string $register, string $caseId, string $decisionId = ''): void {
		$this->decisionLink->link(objectService: $objectService, register: $register, caseId: $caseId, decisionId: $decisionId);

		try {
			$case = $this->findObjectAsArray(
				objectService: $objectService,
				register: $register,
				schema: $this->settingsService->getConfigValue('case_schema'),
				id: $caseId,
			);
			if ($case === null || ($case['wooPublicationStatus'] ?? '') === 'published') {
				return;
			}

			$this->patchObjectAsArray(
				objectService: $objectService,
				register: $register,
				schema: $this->settingsService->getConfigValue('case_schema'),
				id: $caseId,
				changes: ['wooPublicationStatus' => 'ready'],
			);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'PublicationReadiness: the case could not show that its decision is ready to publish',
				['app' => Application::APP_ID, 'caseId' => $caseId, 'error' => $e->getMessage()],
			);
		}
	}//end markCaseReady()
}//end class
