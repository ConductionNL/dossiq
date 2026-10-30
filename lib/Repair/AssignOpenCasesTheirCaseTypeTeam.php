<?php

/**
 * Dossiq repair step: give open cases without a team their case type's handling team.
 *
 * @category Repair
 * @package  OCA\Dossiq\Repair
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
 * @spec openspec/specs/case-types/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Repair;

use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Service\CaseType\CaseTypeHandling;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Fills `assignedGroup` on open cases that have none, from their case type's handling block.
 *
 * A case takes its team from `handling.defaultGroup` when it is filed
 * ({@see \OCA\Dossiq\Service\Intake\CaseOpeningDefaults}). A case filed while
 * its case type had no handling block was written without a team, and keeps
 * none after the case type gains one, so it never reaches that team's queue.
 *
 * For every case type that now names a handling group, every open case of that
 * type with an empty `assignedGroup` gets it. Nothing else moves:
 *
 * - a case that already has a team keeps it,
 * - a closed case keeps its history as it was,
 * - a case type without a handling group writes nothing.
 *
 * Only `assignedGroup` is written. Running it twice changes nothing.
 *
 * @spec openspec/specs/case-types/spec.md
 */
class AssignOpenCasesTheirCaseTypeTeam implements IRepairStep {
	use SearchesObjects;

	/**
	 * The most rows read per listing.
	 */
	private const PAGE = 500;

	/**
	 * Constructor.
	 *
	 * @param SettingsService  $settingsService The settings service.
	 * @param CaseTypeHandling $handling        The one reader of a case type's handling block.
	 * @param LoggerInterface  $logger          The logger.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly CaseTypeHandling $handling,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Get the name of this repair step.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/case-types/spec.md
	 */
	public function getName(): string {
		return 'Dossiq: give open cases without a team the handling team of their case type';
	}//end getName()

	/**
	 * Run the step.
	 *
	 * @param IOutput $output The repair output.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/case-types/spec.md
	 */
	public function run(IOutput $output): void {
		$objectService = $this->settingsService->getObjectService();
		$register = (string)$this->settingsService->getConfigValue('register');
		$caseTypeSchema = (string)$this->settingsService->getConfigValue('case_type_schema');
		$caseSchema = (string)$this->settingsService->getConfigValue('case_schema');
		if ($objectService === null || in_array('', [$register, $caseTypeSchema, $caseSchema], true) === true) {
			$output->info('Case team fill: OpenRegister or the schema configuration is not available, nothing to do.');
			return;
		}

		$assigned = $this->runAsSystemIfAvailable(
			objectService: $objectService,
			operation: fn (): int => $this->assignAll(
				objectService: $objectService,
				register: $register,
				caseTypeSchema: $caseTypeSchema,
				caseSchema: $caseSchema,
			),
		);

		$output->info('Case team fill: ' . (int)$assigned . ' open case(s) given the handling team of their case type.');
	}//end run()

	/**
	 * Fill the team across every case type that names a handling group.
	 *
	 * @param object $objectService  OpenRegister's ObjectService.
	 * @param string $register       The register.
	 * @param string $caseTypeSchema The case type schema.
	 * @param string $caseSchema     The case schema.
	 *
	 * @return int The cases written.
	 *
	 * @spec openspec/specs/case-types/spec.md
	 */
	public function assignAll(object $objectService, string $register, string $caseTypeSchema, string $caseSchema): int {
		try {
			$caseTypes = $this->allRows(objectService: $objectService, register: $register, schema: $caseTypeSchema, filters: []);
		} catch (Throwable $e) {
			$this->logger->error(
				'Dossiq: the case team fill could not list case types',
				['app' => Application::APP_ID, 'exception' => $e->getMessage()]
			);
			return 0;
		}

		$assigned = 0;
		foreach ($caseTypes as $caseType) {
			$caseTypeId = (string)($caseType['id'] ?? ($caseType['@self']['id'] ?? ''));
			$group = $this->handling->defaultGroup(caseType: $caseType);
			if ($caseTypeId === '' || $group === '') {
				continue;
			}

			$assigned += $this->assignCaseType(
				objectService: $objectService,
				register: $register,
				caseSchema: $caseSchema,
				caseTypeId: $caseTypeId,
				group: $group,
			);
		}

		return $assigned;
	}//end assignAll()

	/**
	 * Fill the team on the open cases of one case type that have none.
	 *
	 * @param object $objectService OpenRegister's ObjectService.
	 * @param string $register      The register.
	 * @param string $caseSchema    The case schema.
	 * @param string $caseTypeId    The case type.
	 * @param string $group         The case type's handling group.
	 *
	 * @return int The cases written.
	 */
	private function assignCaseType(
		object $objectService,
		string $register,
		string $caseSchema,
		string $caseTypeId,
		string $group,
	): int {
		try {
			$cases = $this->allRows(
				objectService: $objectService,
				register: $register,
				schema: $caseSchema,
				filters: ['caseType' => $caseTypeId],
			);
		} catch (Throwable $e) {
			$this->logger->error(
				'Dossiq: the case team fill could not list cases',
				['app' => Application::APP_ID, 'caseType' => $caseTypeId, 'exception' => $e->getMessage()]
			);
			return 0;
		}

		$assigned = 0;
		foreach ($cases as $case) {
			$caseId = (string)($case['id'] ?? ($case['@self']['id'] ?? ''));
			if ($caseId === '' || $this->needsTeam(case: $case) === false) {
				continue;
			}

			try {
				$this->patchObjectAsArray(
					objectService: $objectService,
					register: $register,
					schema: $caseSchema,
					id: $caseId,
					changes: ['assignedGroup' => $group],
				);
			} catch (Throwable $e) {
				$this->logger->error(
					'Dossiq: the case team fill could not write a case',
					['app' => Application::APP_ID, 'caseId' => $caseId, 'exception' => $e->getMessage()]
				);
				continue;
			}

			$assigned++;
		}//end foreach

		return $assigned;
	}//end assignCaseType()

	/**
	 * Whether a case is open and has no team.
	 *
	 * @param array<string, mixed> $case The case.
	 *
	 * @return bool
	 */
	private function needsTeam(array $case): bool {
		if (($case['isFinalStatus'] ?? false) === true) {
			return false;
		}

		$team = ($case['assignedGroup'] ?? null);

		return ($team === null || (is_string($team) === true && trim($team) === ''));
	}//end needsTeam()

	/**
	 * Every row of a schema matching the filters, page by page.
	 *
	 * @param object               $objectService OpenRegister's ObjectService.
	 * @param string               $register      The register.
	 * @param string               $schema        The schema.
	 * @param array<string, mixed> $filters       Object-field filters.
	 *
	 * @return array<int, array<string, mixed>> The rows.
	 */
	private function allRows(object $objectService, string $register, string $schema, array $filters): array {
		$rows = [];
		$offset = 0;
		$full = true;
		while ($full === true) {
			$page = $this->searchObjectsAsArrays(
				objectService: $objectService,
				register: $register,
				schema: $schema,
				filters: $filters + ['_limit' => self::PAGE, '_offset' => $offset],
			);
			array_push($rows, ...$page);
			$offset += self::PAGE;
			$full = (count($page) === self::PAGE);
		}

		return $rows;
	}//end allRows()
}//end class
