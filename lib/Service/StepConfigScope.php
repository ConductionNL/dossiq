<?php

/**
 * One step's config being validated
 *
 * The config, the case type schema its field references resolve against,
 * the registered action keys, and the path errors are reported under: what
 * StepConfigValidator::validate() reads once and its entry checks share.
 *
 * @category Service
 * @package  OCA\Dossiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
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
 * @spec openspec/specs/process-step-configuration/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

/**
 * The state of one step config validation; it never leaves the validator that built it.
 *
 * @spec openspec/specs/process-step-configuration/spec.md
 */
final class StepConfigScope {

	/**
	 * The step's config object.
	 *
	 * @var array<string, mixed>
	 */
	public array $config = [];

	/**
	 * The case type schema (properties, roleTypes), or [] to skip reference checks.
	 *
	 * @var array<string, mixed>
	 */
	public array $caseTypeSchema = [];

	/**
	 * The registered action keys.
	 *
	 * @var array<int, string>
	 */
	public array $actionCatalog = [];

	/**
	 * The path errors are reported under, e.g. "steps[0].config".
	 *
	 * @var string
	 */
	public string $base = '';
}//end class
