<?php

/**
 * Dossiq DsoObjectRepository.
 *
 * OpenRegister read collaborator for the DSO Omgevingsloket surface. Split out
 * of DsoController so that controller keeps only endpoint shape: resolving the
 * ObjectService, resolving register/schema config, loading a zaak or a
 * samenwerkverzoek by id, and running the dashboard query with its in-memory
 * filters all live here and nowhere else (ADR-022 — controllers stay thin and
 * defer to services).
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Dso
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
 * @spec openspec/changes/archive/2026-06-13-dso-omgevingsloket/tasks.md#T07
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Dso;

use OCA\Dossiq\Kernel\Contracts\DsoObjectRepositoryInterface;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use Psr\Log\LoggerInterface;

/**
 * Loads DSO zaken and samenwerkverzoeken from OpenRegister.
 *
 * @spec openspec/changes/archive/2026-06-13-dso-omgevingsloket/tasks.md#T07
 */
class DsoObjectRepository implements DsoObjectRepositoryInterface {
	use SearchesObjects;

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService The settings service (config + ObjectService bridge).
	 * @param LoggerInterface $logger The logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Load a zaak by ID from the ObjectService.
	 *
	 * Returns null when the zaak does not exist or the service is unavailable.
	 *
	 * @param string $caseId The zaak UUID.
	 *
	 * @return array<string,mixed>|null The zaak, or null when unresolvable.
	 *
	 * @spec openspec/changes/archive/2026-06-13-dso-omgevingsloket/tasks.md#T07
	 */
	public function findZaak(string $caseId): ?array {
		try {
			return $this->loadZaak(caseId: $caseId);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Dossiq DsoObjectRepository: could not load zaak ' . $caseId . ': ' . $e->getMessage()
			);
			return null;
		}//end try
	}//end findZaak()

	/**
	 * Read a zaak, when the store and the case schema are configured.
	 *
	 * @param string $caseId The zaak UUID.
	 *
	 * @return array<string,mixed>|null The zaak, or null when unresolvable.
	 */
	private function loadZaak(string $caseId): array|null {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			return null;
		}

		$register = $this->settingsService->getConfigValue(key: 'register');
		$caseSchema = $this->settingsService->getConfigValue(key: 'case_schema');

		if ($register === '' || $caseSchema === '') {
			return null;
		}

		return $this->findObjectAsArray(objectService: $objectService, register: $register, schema: $caseSchema, id: $caseId);
	}//end loadZaak()

	/**
	 * Load a samenwerkverzoek by ID from the ObjectService.
	 *
	 * @param string $samenwerkId The samenwerkverzoek UUID.
	 *
	 * @return array<string,mixed>|null The samenwerkverzoek, or null when unresolvable.
	 *
	 * @spec openspec/changes/archive/2026-06-13-dso-omgevingsloket/tasks.md#T07
	 */
	public function findSamenwerkverzoek(string $samenwerkId): ?array {
		try {
			return $this->loadSamenwerkverzoek(samenwerkId: $samenwerkId);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Dossiq DsoObjectRepository: could not load samenwerkverzoek ' . $samenwerkId . ': ' . $e->getMessage()
			);
			return null;
		}//end try
	}//end findSamenwerkverzoek()

	/**
	 * Read a samenwerkverzoek, from the configured schema or the default slug.
	 *
	 * @param string $samenwerkId The samenwerkverzoek UUID.
	 *
	 * @return array<string,mixed>|null The samenwerkverzoek, or null when unresolvable.
	 */
	private function loadSamenwerkverzoek(string $samenwerkId): array|null {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			return null;
		}

		$register = $this->settingsService->getConfigValue(key: 'register');
		$samenwerkSchema = $this->settingsService->getConfigValue(key: 'dso_samenwerkverzoek_schema');

		if ($register === '' || $samenwerkSchema === '') {
			$samenwerkSchema = 'samenwerkverzoek';
		}

		return $this->findObjectAsArray(objectService: $objectService, register: $register, schema: $samenwerkSchema, id: $samenwerkId);
	}//end loadSamenwerkverzoek()

	/**
	 * Run the dashboard query and apply the in-memory filters.
	 *
	 * Returns an `error` string when the backing register cannot be reached or
	 * is not configured; the caller maps that onto a 503. Any other failure is
	 * allowed to propagate so the caller can log and return a 500.
	 *
	 * @param array<string,mixed> $params Filters pushed to ObjectService.
	 * @param string $activiteitgroep Filter by activiteitgroep.
	 * @param string $regelkwalificatie Filter by regelkwalificatie.
	 * @param string $location Filter by locatie substring.
	 *
	 * @return array{error: string|null, results: array<int,array<string,mixed>>} The query outcome.
	 *
	 * @spec openspec/changes/archive/2026-06-13-dso-omgevingsloket/tasks.md#T07
	 */
	public function fetchDashboard(
		array $params,
		string $activiteitgroep,
		string $regelkwalificatie,
		string $location,
	): array {
		$cases = $this->dashboardCases(params: $params);
		if ($cases['error'] !== null) {
			return $cases;
		}

		return $this->filteredDashboard(
			cases: $cases['results'],
			filters: new DashboardFilters(activiteitgroep: $activiteitgroep, regelkwalificatie: $regelkwalificatie, location: $location),
		);
	}//end fetchDashboard()

	/**
	 * Run the dashboard query, or name why the register cannot answer it.
	 *
	 * @param array<string,mixed> $params Filters pushed to ObjectService.
	 *
	 * @return array{error: string|null, results: array<int,mixed>} The query outcome.
	 *
	 * @spec openspec/changes/archive/2026-06-13-dso-omgevingsloket/tasks.md#T07
	 */
	private function dashboardCases(array $params): array {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			return ['error' => 'OpenRegister not available', 'results' => []];
		}

		$register = $this->settingsService->getConfigValue(key: 'register');
		$caseSchema = $this->settingsService->getConfigValue(key: 'case_schema');

		if ($register === '' || $caseSchema === '') {
			return ['error' => 'Case register not configured', 'results' => []];
		}

		$casesList = $this->searchObjectsAsArrays(objectService: $objectService, register: $register, schema: $caseSchema, filters: $params);

		return ['error' => null, 'results' => $casesList];
	}//end dashboardCases()

	/**
	 * The dashboard outcome with the in-memory filters applied.
	 *
	 * @param array<int,mixed> $cases The zaken.
	 * @param DashboardFilters $filters The activiteitgroep, regelkwalificatie and locatie filters.
	 *
	 * @return array{error: null, results: array<int,array<string,mixed>>} The query outcome.
	 *
	 * @spec openspec/changes/archive/2026-06-13-dso-omgevingsloket/tasks.md#T07
	 */
	private function filteredDashboard(array $cases, DashboardFilters $filters): array {
		return [
			'error' => null,
			'results' => $this->applyInMemoryFilters(cases: $cases, filters: $filters),
		];
	}//end filteredDashboard()

	/**
	 * Apply in-memory filters that cannot be pushed to ObjectService params.
	 *
	 * @param array<int,mixed> $cases The zaken array (elements come from ObjectService and are not guaranteed to be arrays)
	 * @param DashboardFilters $filters The activiteitgroep, regelkwalificatie and locatie substring filters
	 *
	 * @return array<int,array<string,mixed>> The filtered zaken.
	 *
	 * @spec openspec/changes/archive/2026-06-13-dso-omgevingsloket/tasks.md#T07
	 */
	private function applyInMemoryFilters(array $cases, DashboardFilters $filters): array {
		$location = $filters->location;
		if ($filters->activiteitgroep === '' && $filters->regelkwalificatie === '' && $location === '') {
			return $cases;
		}

		$result = [];
		foreach ($cases as $case) {
			if (is_array($case) === false) {
				continue;
			}

			if ($location !== '' && str_contains((string)($case['location'] ?? ''), $location) === false) {
				continue;
			}

			$result[] = $case;
		}

		return $result;
	}//end applyInMemoryFilters()
}//end class
