<?php

/**
 * Contract of SettingValueRules
 *
 * The public surface of SettingValueRules that other components call, so they depend
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
 * Contract of SettingValueRules.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface SettingValueRulesInterface {

	/**
	 * The settings that take one of a fixed set of values.
	 *
	 * @var array<string, list<string>>
	 */
	public const CHOICES = [
		'date_format' => ['DD-MM-YYYY', 'YYYY-MM-DD', 'D MMMM YYYY'],
		'feature_new_case_page' => ['true', 'false'],
		'publication_date_rule' => ['', 'first-wednesday', 'decision-date'],
	];
}//end interface
