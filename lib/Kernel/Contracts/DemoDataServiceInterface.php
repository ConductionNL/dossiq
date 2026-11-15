<?php

/**
 * Contract of DemoDataService
 *
 * The public surface of DemoDataService that other components call, so they depend
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

use RuntimeException;

/**
 * Contract of DemoDataService.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface DemoDataServiceInterface {

	/**
	 * App-config key recording the setup wizard's example data decision:
	 * `installed` when the operator loaded example data, `skipped` when they
	 * chose none. Anything that plants example rows reads this switch.
	 *
	 * @var string
	 */
	public const DECIDED_KEY = 'demo_data_decided';

	/**
	 * The decision that opts this instance into example data.
	 *
	 * @var string
	 */
	public const DECIDED_INSTALLED = 'installed';

	/**
	 * The answer that means "plant nothing".
	 *
	 * 🔴 NOT THE ABSENCE OF AN ANSWER. An operator who declines has FINISHED the
	 * step; a step that can never be marked done reopens the wizard over every
	 * page (nextcloud-vue#806).
	 *
	 * @var string
	 */
	public const NONE_DATASET = 'none';

	/**
	 * The id of the dataset this app ships.
	 *
	 * @var string
	 */
	public const DEMO_DATASET = 'demo';

	/**
	 * Import the demo dataset.
	 *''
	 * 🔴 THROWS RATHER THAN RETURNING A QUIET FAILURE. Every caller reports the
	 * outcome to an operator who just asked for this, so "nothing happened"
	 * must not be presentable as success.
	 *
	 * 🔴 AND THE COUNT IS WHAT LANDED, NOT WHAT WAS ASKED FOR. This method used
	 * to count `components.objects` in the shipped file and report that as the
	 * result, with a comment saying the number reported is "the number ASKED
	 * FOR". The ask is not an outcome: a descriptor of 456 objects reported
	 * "456 objects" whether the importer stored 456, three or none, so the ten
	 * demo keys no schema declared (#1782) were stripped on the way in under a
	 * green message that could not have said otherwise. `importFromJson()`
	 * answers with `objects` — the entities it created or updated — and
	 * `skipped.objects` — the ones it refused. Both are read here, and both are
	 * returned, so a caller can print the landing next to the ask.
	 *
	 * 🔴 `registers` AND `schemas` ARE EXPECTED TO BE ZERO, AND THAT IS THE
	 * FIX, NOT A REGRESSION. They count what the import DEFINED, and a demo
	 * set defines neither: it binds its objects to the register and schemas
	 * the app already owns. A non-zero count here means the payload carried a
	 * definitional block that {@see self::objectsOnly()} failed to strip.
	 *
	 * @return array{objects: integer, requested: integer, refused: integer, unchanged: integer,
	 *     registers: integer, schemas: integer} What was asked for and what landed.
	 *
	 * @throws RuntimeException When the descriptor is missing or unreadable, OpenRegister is
	 *     absent, or nothing was stored and nothing was already present.
	 *
	 * @spec openspec/specs/first-time-setup/spec.md
	 */
	public function install(): array;

	/**
	 * Every answer the wizard's choice step may offer, declining included.
	 *
	 * 🔴 THE SERVER OWNS THIS LIST, AND THAT IS THE POINT. The step declares
	 * `optionsSource: datasets` and no options of its own, so the label, the
	 * description and the object count come from the descriptor that will
	 * actually be imported. A manifest that restated them could disagree with
	 * what lands, and nothing would notice.
	 *
	 * @return array<int, array{id: string, label: string, description: string, objectCount: integer, icon: string}> The answers.
	 *
	 * @spec exclude Demo-data choice list; ADR-111 rule 1 has no per-app behavioural spec.
	 */
	public function listChoices(): array;
}//end interface
