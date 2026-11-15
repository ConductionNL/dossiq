<?php

/**
 * Contract of BezwaarArchiveTrigger
 *
 * The public surface of BezwaarArchiveTrigger that other components call, so they depend
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
 * Contract of BezwaarArchiveTrigger.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface BezwaarArchiveTriggerInterface {

	/**
	 * The config key naming the bezwaarTrigger schema.
	 *
	 * @var string
	 */
	public const SCHEMA_CONFIG_KEY = 'bezwaar_trigger_schema';

	/**
	 * Read one trigger fresh.
	 *
	 * @param string $triggerId The trigger id.
	 *
	 * @return array<string, mixed>|null The trigger, or null when it does not exist or nothing is configured.
	 *
	 * @spec openspec/changes/termijnbewaking-op-engine-timers/tasks.md
	 */
	public function find(string $triggerId): ?array;

	/**
	 * Act on a trigger whose objection term has ended.
	 *
	 * @param array<string, mixed> $trigger The trigger, as read fresh.
	 *
	 * @return string One of the outcome constants.
	 *
	 * @spec openspec/changes/termijnbewaking-op-engine-timers/tasks.md
	 */
	public function process(array $trigger): string;
}//end interface
