<?php

/**
 * Contract of DsoObjectRepository
 *
 * The public surface of DsoObjectRepository that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Dossiq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/dossiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Kernel\Contracts;

/**
 * Contract of DsoObjectRepository.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface DsoObjectRepositoryInterface {

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
	): array;

	/**
	 * Load a samenwerkverzoek by ID from the ObjectService.
	 *
	 * @param string $samenwerkId The samenwerkverzoek UUID.
	 *
	 * @return array<string,mixed>|null The samenwerkverzoek, or null when unresolvable.
	 *
	 * @spec openspec/changes/archive/2026-06-13-dso-omgevingsloket/tasks.md#T07
	 */
	public function findSamenwerkverzoek(string $samenwerkId): ?array;

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
	public function findZaak(string $caseId): ?array;
}//end interface
