<?php

/**
 * Contract of ReportPeriod
 *
 * The public surface of ReportPeriod that other components call, so they depend
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
 * Contract of ReportPeriod.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ReportPeriodInterface {

	/**
	 * The presets a report accepts. Anything else reads as the default.
	 *
	 * @var string[]
	 */
	public const PRESETS = ['1m', '3m', '6m', '12m', 'year', 'all'];

	/**
	 * The preset a report reads when none (or an unknown one) is given.
	 *
	 * @var string
	 */
	public const DEFAULT_PRESET = '12m';

	/**
	 * Whether a value names a known preset.
	 *
	 * @param mixed $preset The value as it arrived.
	 *
	 * @return bool True for a known preset.
	 *
	 * @spec openspec/changes/page-topology-cleanup/specs/analytics-dashboard-surface/spec.md
	 */
	public function isPreset(mixed $preset): bool;
}//end interface
