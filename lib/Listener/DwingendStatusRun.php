<?php

/**
 * Dossiq dwingend status run.
 *
 * What re-deriving one source bezwaar's dwingendStatus shares between its steps.
 *
 * @category Listener
 * @package  OCA\Dossiq\Listener
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
 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Listener;

/**
 * One beroep whose source bezwaar is being re-derived, and where that bezwaar lives.
 *
 * Built by {@see BeroepEscalationListener} only; it never leaves it.
 *
 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
 */
final class DwingendStatusRun {

	/**
	 * The beroep payload, carrying a non-empty `sourceObjection`.
	 *
	 * @var array<string, mixed>
	 */
	public array $appeal = [];

	/**
	 * The OpenRegister object service.
	 *
	 * @var object
	 */
	public object $objectService;

	/**
	 * The configured register.
	 *
	 * @var string
	 */
	public string $register = '';

	/**
	 * The configured bezwaar schema.
	 *
	 * @var string
	 */
	public string $objectionSchema = '';
}//end class
