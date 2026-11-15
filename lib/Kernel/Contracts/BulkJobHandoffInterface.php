<?php

/**
 * Contract of BulkJobHandoff
 *
 * The public surface of BulkJobHandoff that other components call, so they depend
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

use InvalidArgumentException;
use OCA\Dossiq\Service\Bulk\MixedCaseTypeVersionsException;
use RuntimeException;

/**
 * Contract of BulkJobHandoff.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface BulkJobHandoffInterface {

	/**
	 * Create a previewed bulk job over cases.
	 *
	 * @param string               $actionId      The dossiq action to run.
	 * @param array<string, mixed> $parameters    The action's parameters.
	 * @param array<string, mixed> $selection     Either `{"ids": [...]}` or `{"query": {...}}`.
	 * @param string|null          $justification The reason the coordinator typed.
	 * @param string               $actorUid      Who is doing it.
	 *
	 * @return array<string, mixed> The previewed job, as the job serialises itself.
	 *
	 * @throws InvalidArgumentException When the action is not one of dossiq's.
	 * @throws MixedCaseTypeVersionsException When the selection spans case type versions.
	 * @throws RuntimeException When OpenRegister is not available.
	 *
	 * @spec openspec/specs/case-management/spec.md
	 */
	public function create(
		string $actionId,
		array $parameters,
		array $selection,
		?string $justification,
		string $actorUid,
	): array;
}//end interface
