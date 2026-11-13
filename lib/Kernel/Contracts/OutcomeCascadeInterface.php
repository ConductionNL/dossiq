<?php

/**
 * Contract of OutcomeCascade
 *
 * The public surface of OutcomeCascade that other components call, so they depend
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
use RuntimeException;

/**
 * Contract of OutcomeCascade.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface OutcomeCascadeInterface {

	/**
	 * The case a cascade's target belongs to, for the caller's access check.
	 *
	 * @param string $name     The cascade.
	 * @param string $targetId The target uuid.
	 *
	 * @return string The case uuid, or '' when the target names none.
	 *
	 * @throws InvalidArgumentException When the cascade is unknown or the target is not found.
	 * @throws RuntimeException When the register cannot be reached.
	 *
	 * @spec openspec/changes/linked-case-with-a-role/specs/linked-case-with-a-role/spec.md#requirement-an-outcome-cascade-runs-its-configured-steps-req-lcr-5
	 */
	public function caseOf(string $name, string $targetId): string;

	/**
	 * Run a cascade on a target.
	 *
	 * @param string               $name     The cascade.
	 * @param string               $targetId The target uuid.
	 * @param array<string, mixed> $inputs   Values the cascade's steps ask for by name.
	 *
	 * @return array<string, mixed> The target as it stands after the cascade.
	 *
	 * @throws InvalidArgumentException When the cascade is unknown, malformed, or an input is missing.
	 * @throws RuntimeException When the register cannot be reached or a step fails.
	 *
	 * @spec openspec/changes/linked-case-with-a-role/specs/linked-case-with-a-role/spec.md#requirement-an-outcome-cascade-runs-its-configured-steps-req-lcr-5
	 */
	public function run(string $name, string $targetId, array $inputs = []): array;
}//end interface
