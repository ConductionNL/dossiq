<?php

/**
 * Contract of BelplanRoutingService
 *
 * The public surface of BelplanRoutingService that other components call, so they depend
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

use RuntimeException;

/**
 * Contract of BelplanRoutingService.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface BelplanRoutingServiceInterface {

	/**
	 * Fetch specialist availability records for a vaardigheid.
	 *
	 * @param string $vaardigheid The vaardigheid / expertise code, empty for all.
	 *
	 * @return array<int, array<string, mixed>> The availability records.
	 *
	 * @spec openspec/changes/archive/2026-06-13-kcc-werkplek-zaaksysteem-bridge/tasks.md#T06
	 */
	public function getSpecialistBeschikbaarheid(string $vaardigheid = ''): array;

	/**
	 * Route a call to the best specialist, applying overflow rules.
	 *
	 * @param string $phoneNumber The dialed number.
	 * @param string $menuSelection The keuzemenu selection (e.g. "Omgevingsvergunningen").
	 *
	 * @return array{destinationSpecialistId: ?string, vaardigheid: string, escalatieFlag: bool, estimatedWaitTime: int, fallbackRol: ?string}
	 *
	 * @throws RuntimeException When no belplan matches the dialed number.
	 *
	 * @spec openspec/changes/archive/2026-06-13-kcc-werkplek-zaaksysteem-bridge/tasks.md#T06
	 */
	public function routeCall(string $phoneNumber, string $menuSelection): array;
}//end interface
