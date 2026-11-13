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

use OCA\Dossiq\Kernel\AppIdentity;
use OCA\Dossiq\Kernel\Contracts\SettingsServiceInterface;
use OCA\Dossiq\Service\CaseType\CaseTypeHandling;
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
	 * @param SettingsServiceInterface  $settingsService The settings service.
	 * @param CaseTypeHandling $handling        The one reader of a case type's handling block.
	 * @param LoggerInterface  $logger          The logger.
	 */
	public function __construct(
		private readonly SettingsServiceInterface $settingsService,
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
		$run = new CaseTeamFillRun(objectService: $objectService, register: $register);
		$run->caseTypeSchema = $caseTypeSchema;
		$run->caseSchema = $caseSchema;

		$caseTypes = $this->caseTypes(run: $run);
		$assigned = 0;
		foreach ($caseTypes as $caseType) {
			$assigned += $this->assignCaseType(run: $run, caseType: $caseType);
		}

		return $assigned;
	}//end assignAll()

	/**
	 * Every case type, or none when they cannot be listed.
	 *
	 * @param CaseTeamFillRun $run The pass.
	 *
	 * @return array<int, array<string, mixed>> The case types.
	 */
	private function caseTypes(CaseTeamFillRun $run): array {
		try {
			return $this->allRows(
				objectService: $run->objectService,
				register: $run->register,
				schema: $run->caseTypeSchema,
				filters: [],
			);
		} catch (Throwable $e) {
			$this->logger->error(
				'Dossiq: the case team fill could not list case types',
				['app' => AppIdentity::APP_ID, 'exception' => $e->getMessage()]
			);
			return [];
		}
	}//end caseTypes()

	/**
	 * Fill the team on the open cases of one case type that have none.
	 *
	 * A case type without an id or without a handling group writes nothing.
	 *
	 * @param CaseTeamFillRun      $run      The pass.
	 * @param array<string, mixed> $caseType The case type.
	 *
	 * @return int The cases written.
	 */
	private function assignCaseType(CaseTeamFillRun $run, array $caseType): int {
		$caseTypeId = (string)($caseType['id'] ?? ($caseType['@self']['id'] ?? ''));
		$group = $this->handling->defaultGroup(caseType: $caseType);
		if ($caseTypeId === '' || $group === '') {
			return 0;
		}

		$typeRun = $run->forCaseType(caseTypeId: $caseTypeId, group: $group);
		$assigned = 0;
		foreach ($this->casesOf(run: $typeRun) as $case) {
			$assigned += $this->assignCase(run: $typeRun, case: $case);
		}

		return $assigned;
	}//end assignCaseType()

	/**
	 * Every case of the run's case type, or none when they cannot be listed.
	 *
	 * @param CaseTeamFillRun $run The pass, narrowed to one case type.
	 *
	 * @return array<int, array<string, mixed>> The cases.
	 */
	private function casesOf(CaseTeamFillRun $run): array {
		try {
			return $this->allRows(
				objectService: $run->objectService,
				register: $run->register,
				schema: $run->caseSchema,
				filters: ['caseType' => $run->caseTypeId],
			);
		} catch (Throwable $e) {
			$this->logger->error(
				'Dossiq: the case team fill could not list cases',
				['app' => AppIdentity::APP_ID, 'caseType' => $run->caseTypeId, 'exception' => $e->getMessage()]
			);
			return [];
		}
	}//end casesOf()

	/**
	 * Give one case the run's handling group when it is open and has no team.
	 *
	 * @param CaseTeamFillRun      $run  The pass, narrowed to one case type.
	 * @param array<string, mixed> $case The case.
	 *
	 * @return int 1 when the case was written, else 0.
	 */
	private function assignCase(CaseTeamFillRun $run, array $case): int {
		$caseId = (string)($case['id'] ?? ($case['@self']['id'] ?? ''));
		if ($caseId === '' || $this->needsTeam(case: $case) === false) {
			return 0;
		}

		if ($this->writeTeam(run: $run, caseId: $caseId) === false) {
			return 0;
		}

		return 1;
	}//end assignCase()

	/**
	 * Write the run's handling group onto one case, logging a failure.
	 *
	 * @param CaseTeamFillRun $run    The pass, narrowed to one case type.
	 * @param string          $caseId The case.
	 *
	 * @return bool Whether the write went through.
	 */
	private function writeTeam(CaseTeamFillRun $run, string $caseId): bool {
		try {
			$this->patchObjectAsArray(
				objectService: $run->objectService,
				register: $run->register,
				schema: $run->caseSchema,
				id: $caseId,
				changes: ['assignedGroup' => $run->group],
			);
		} catch (Throwable $e) {
			$this->logger->error(
				'Dossiq: the case team fill could not write a case',
				['app' => AppIdentity::APP_ID, 'caseId' => $caseId, 'exception' => $e->getMessage()]
			);
			return false;
		}

		return true;
	}//end writeTeam()

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
