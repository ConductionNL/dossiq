<?php

/**
 * Dossiq case purge.
 *
 * Permanently deletes a destroyed case from its register and schema, once
 * its destruction is recorded. Split out of
 * {@see \OCA\Dossiq\Service\Recycle\CaseDestructionService}, which keeps
 * the guards, the scope and the record of the act.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Recycle
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/case-management/spec.md
 * @spec openspec/changes/method-decomposition/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Recycle;

use OCA\Dossiq\Service\SettingsService;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectDeletion;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget;
use RuntimeException;

/**
 * Permanently deletes a case from the configured register and case schema.
 *
 * @spec openspec/specs/case-management/spec.md
 * @spec openspec/changes/method-decomposition/tasks.md
 */
final class CasePurge {

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService The settings and OpenRegister access.
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
	) {
	}//end __construct()

	/**
	 * Permanently delete the case itself.
	 *
	 * The record above is already written, so a failure here leaves an
	 * over-recorded destruction rather than an unrecorded one.
	 *
	 * @param string $caseId The case UUID.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When OpenRegister is not available.
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function delete(string $caseId): void {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			throw new RuntimeException('openregister_unavailable');
		}

		$objectService->deleteObject(
			new ObjectDeletion(
				uuid: $caseId,
				target: new ObjectFindTarget(
						register: $this->settingsService->getConfigValue('register'),
						schema: $this->settingsService->getConfigValue('case_schema')
					),
				permanent: true
			)
		);
	}//end delete()
}//end class
