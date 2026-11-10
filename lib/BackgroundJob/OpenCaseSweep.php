<?php

/**
 * The open cases a background sweep walks, and where they live.
 *
 * The silence sweep and the payment projection both read the open, non-draft
 * cases of the configured register, capped per run, and the projection
 * writes back into the same place. Where that is, and the read itself, are
 * worked out here once.
 *
 * @category BackgroundJob
 * @package  OCA\Dossiq\BackgroundJob
 *
 * @author    Conduction Development Team <dev@conduction.nl>
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
 * @spec openspec/specs/case-status-machinery/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\BackgroundJob;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;

/**
 * The configured case register, read and patched by a sweep.
 *
 * Built by the sweeping jobs with {@see self::ofSettings()}; it carries no
 * state beyond where the cases live.
 *
 * @spec openspec/specs/case-status-machinery/spec.md
 */
final class OpenCaseSweep {

	use SearchesObjects;

	/**
	 * The OpenRegister object service.
	 *
	 * @var object
	 */
	public object $objectService;

	/**
	 * The configured register.
	 *
	 * @var string
	 */
	public string $register = '';

	/**
	 * The configured case schema.
	 *
	 * @var string
	 */
	public string $schema = '';

	/**
	 * The sweep over the configured case register, or null without an object service, a register or a case schema.
	 *
	 * @param SettingsService $settingsService Bridge to OpenRegister plus config.
	 *
	 * @return self|null
	 *
	 * @spec openspec/specs/case-status-machinery/spec.md
	 */
	public static function ofSettings(SettingsService $settingsService): ?self {
		$objectService = $settingsService->getObjectService();
		if ($objectService === null) {
			return null;
		}

		$sweep = new self();
		$sweep->objectService = $objectService;
		$sweep->register = $settingsService->getConfigValue('register');
		$sweep->schema = $settingsService->getConfigValue('case_schema');
		if ($sweep->register === '' || $sweep->schema === '') {
			return null;
		}

		return $sweep;
	}//end ofSettings()

	/**
	 * The open, non-draft cases, at most `$limit` of them.
	 *
	 * @param int $limit How many cases one sweep looks at.
	 *
	 * @return array<int, array<string, mixed>> The rows.
	 *
	 * @spec openspec/specs/case-status-machinery/spec.md
	 */
	public function openCases(int $limit): array {
		return $this->searchObjectsAsArrays(
			objectService: $this->objectService,
			register: $this->register,
			schema: $this->schema,
			filters: [
				'isFinalStatus' => 0,
				'isDraft' => 0,
				'_limit' => $limit,
			],
		);
	}//end openCases()

	/**
	 * Patch one case inside a system operation when the store offers one.
	 *
	 * @param string               $caseId  The case.
	 * @param array<string, mixed> $changes The fields to write.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/case-status-machinery/spec.md
	 */
	public function patchAsSystem(string $caseId, array $changes): void {
		$this->runAsSystemIfAvailable(
			objectService: $this->objectService,
			operation: fn () => $this->patchObjectAsArray(
				objectService: $this->objectService,
				register: $this->register,
				schema: $this->schema,
				id: $caseId,
				changes: $changes,
			),
		);
	}//end patchAsSystem()
}//end class
