<?php

/**
 * Contract of CaseVoorbladService
 *
 * The public surface of CaseVoorbladService that other components call, so they depend
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
 * Contract of CaseVoorbladService.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface CaseVoorbladServiceInterface {

	/**
	 * Build the case-voorblad for an identified burger.
	 *
	 * @param string $burgerId The identified burger reference.
	 *
	 * @return array{burgerId: string, openZaken: array<int, mixed>, recenteContactmomenten: array<int, mixed>, suggestedTopic: string}
	 *
	 * @spec openspec/changes/archive/2026-06-13-kcc-werkplek-zaaksysteem-bridge/tasks.md#T10
	 */
	public function getCaseVoorblad(string $burgerId): array;
}//end interface
