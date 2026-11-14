<?php

/**
 * Contract of StoredDecisionTables
 *
 * The public surface of StoredDecisionTables that other components call, so they depend
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
 * Contract of StoredDecisionTables.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface StoredDecisionTablesInterface {

	/**
	 * Every table dossiq ships or moved carries this key prefix and owner.
	 *
	 * @var string
	 */
	public const KEY_PREFIX = 'dossiq.';

	/**
	 * The id of the stored row with this key, or null.
	 *
	 * @param string $storedKey The stored key.
	 *
	 * @return string|null The row's id.
	 *
	 * @throws RuntimeException When the register cannot be read (OpenRegister absent, or the register not imported yet).
	 *
	 * @spec openspec/changes/decision-tables-live-in-openregister/specs/decision-tables/spec.md
	 */
	public function idOf(string $storedKey): ?string;

	/**
	 * The stored key of a table dossiq knew by another key.
	 *
	 * OpenRegister keys read `^[a-z0-9][a-z0-9._-]*$`; dossiq's keys were free
	 * text. Lower case, every other character a hyphen, and the dossiq prefix,
	 * so `LHS Matrix 2` is stored as `dossiq.lhs-matrix-2`. A key that already
	 * carries the prefix is returned as is.
	 *
	 * @param string $key The table's dossiq key.
	 *
	 * @return string The stored key, or empty for an empty key.
	 *
	 * @spec openspec/changes/decision-tables-live-in-openregister/specs/decision-tables/spec.md
	 */
	public function keyFor(string $key): string;

	/**
	 * Write a table dossiq owns, by its stored key: create, or replace the row with that key.
	 *
	 * @param array<string, mixed> $table The table: key (stored), name, description, hitPolicy, inputs, outputs, rules.
	 *
	 * @return array<string, mixed> The row as written.
	 *
	 * @throws RuntimeException When OpenRegister refuses the table.
	 *
	 * @spec openspec/changes/decision-tables-live-in-openregister/specs/decision-tables/spec.md
	 */
	public function save(array $table): array;
}//end interface
