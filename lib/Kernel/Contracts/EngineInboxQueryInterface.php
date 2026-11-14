<?php

/**
 * Contract of EngineInboxQuery
 *
 * The public surface of EngineInboxQuery that other components call, so they depend
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
 * Contract of EngineInboxQuery.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface EngineInboxQueryInterface {

	/**
	 * The rows one read returns, still in the engine's own shape.
	 *
	 * @param array<string, mixed> $criteria Named arguments for the criteria.
	 * @param integer              $limit    How many rows at most.
	 * @param array{0: string, 1: array<string, mixed>} $failure Log message and context.
	 *
	 * @return array<int, mixed> The rows.
	 *
	 * @spec openspec/specs/add-work-queue/spec.md#requirement-three-surfaces-one-rule
	 */
	public function rows(array $criteria, int $limit, array $failure): array;

	/**
	 * The name of one of the criteria's scope constants.
	 *
	 * The constants belong to another app's class, so a caller that wants
	 * `SCOPE_ALL` would otherwise have to name that class itself and the
	 * constant would be declared in two places.
	 *
	 * @param string $name The constant, e.g. `SCOPE_ALL`.
	 *
	 * @return string Its value, or '' when the class is absent.
	 *
	 * @spec openspec/specs/add-work-queue/spec.md#requirement-three-surfaces-one-rule
	 */
	public function scope(string $name): string;
}//end interface
