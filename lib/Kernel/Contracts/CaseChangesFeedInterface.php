<?php

/**
 * Contract of CaseChangesFeed
 *
 * The public surface of CaseChangesFeed that other components call, so they depend
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

use DateTimeImmutable;

/**
 * Contract of CaseChangesFeed.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface CaseChangesFeedInterface {

	/**
	 * The cases others changed after `$since`, newest first, one entry per case.
	 *
	 * @param string               $readerId The reader; their own changes are left out.
	 * @param DateTimeImmutable    $since    The moment the board last asked.
	 * @param array<string, string> $scope   Optional `caseType` and `assignedGroup` the board shows.
	 *
	 * @return array{changes: list<array<string, string>>, now: string}
	 *
	 * @spec openspec/specs/realtime-updates-ui/spec.md
	 */
	public function since(string $readerId, DateTimeImmutable $since, array $scope): array;
}//end interface
