<?php

/**
 * Dossiq case decision link.
 *
 * Adds a decision to the case's own `decisions` list once it is recorded.
 * The decision row already names its case; the case's list is what the case
 * page's next-step checklist ("Record the decision") and its decision count
 * read. A decision recorded without it left that item on "to do".
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
 * @spec openspec/specs/case-management/spec.md#requirement-a-recorded-decision-is-listed-on-its-case-req-cm-81
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\DecisionDocument;

use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use Psr\Log\LoggerInterface;

/**
 * Lists a recorded decision on its case.
 *
 * @spec openspec/specs/case-management/spec.md#requirement-a-recorded-decision-is-listed-on-its-case-req-cm-81
 */
class CaseDecisionLink {

	use SearchesObjects;

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
	}//end __construct()

	/**
	 * Add the decision to the case's `decisions`, once.
	 *
	 * A case that cannot be updated costs the checklist tick, not the
	 * decision: the decision is recorded and names its case either way.
	 *
	 * @param object $objectService The OpenRegister ObjectService.
	 * @param string $register      The dossiq register.
	 * @param string $caseId        The case UUID.
	 * @param string $decisionId    The recorded decision's UUID.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/case-management/spec.md#requirement-a-recorded-decision-is-listed-on-its-case-req-cm-81
	 */
	public function link(object $objectService, string $register, string $caseId, string $decisionId): void {
		if ($decisionId === '') {
			return;
		}

		$schema = $this->settingsService->getConfigValue('case_schema');
		try {
			$case = $this->findObjectAsArray(objectService: $objectService, register: $register, schema: $schema, id: $caseId);
			if ($case === null) {
				return;
			}

			$listed = array_values(array_filter((array)($case['decisions'] ?? []), 'is_string'));
			if (in_array($decisionId, $listed, true) === true) {
				return;
			}

			$listed[] = $decisionId;
			$this->patchObjectAsArray(
				objectService: $objectService,
				register: $register,
				schema: $schema,
				id: $caseId,
				changes: ['decisions' => $listed],
			);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'CaseDecisionLink: the case could not list its recorded decision',
				['app' => Application::APP_ID, 'caseId' => $caseId, 'error' => $e->getMessage()],
			);
		}//end try
	}//end link()
}//end class
