<?php

/**
 * Contract of IntakeObjectDrain
 *
 * The public surface of IntakeObjectDrain that other components call, so they depend
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
 * Contract of IntakeObjectDrain.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface IntakeObjectDrainInterface {

	/**
	 * Drain every source object.
	 *
	 * @param array<int, array<string, mixed>>                          $sources   The source objects.
	 * @param array<string, mixed>                                      $plan      The plan.
	 * @param callable(array<string, mixed>): string                    $existing  The id of a case already holding this
	 *                                                                             reference, or the empty string.
	 * @param callable(array<string, mixed>): array<string, mixed>      $submit    Writes the case; answers `id` or `findings`.
	 * @param callable(array<string, mixed>, string): void              $markSource Records the case id on the source.
	 * @param bool                                                      $apply     False: report only, write nothing.
	 *
	 * @return array{delivered: array<int, array<string, string>>, already: array<int, string>, refused: array<int, array<string, mixed>>} The report.
	 *
	 * @spec openspec/changes/a-request-form-opens-the-case-at-once/specs/case-intake-destination/spec.md#requirement-a-portal-filing-must-not-be-held-in-an-intermediate-object
	 */
	public function drain(array $sources, array $plan, callable $existing, callable $submit, callable $markSource, bool $apply): array;
}//end interface
