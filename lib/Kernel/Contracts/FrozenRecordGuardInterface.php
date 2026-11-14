<?php

/**
 * Contract of FrozenRecordGuard
 *
 * The public surface of FrozenRecordGuard that other components call, so they depend
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

use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\FrozenRecord\FreezeDefinition;

/**
 * Contract of FrozenRecordGuard.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface FrozenRecordGuardInterface {

	/**
	 * Refuse the delete of a frozen record.
	 *
	 * @param FreezeDefinition $definition The freeze entry.
	 * @param array<string, mixed> $stored The record as stored.
	 *
	 * @return void
	 *
	 * @throws RefusedException 409 when the record is frozen.
	 *
	 * @spec openspec/specs/frozen-record/spec.md
	 */
	public function assertDeletable(FreezeDefinition $definition, array $stored): void;

	/**
	 * Refuse an update the freeze does not allow.
	 *
	 * @param FreezeDefinition $definition The freeze entry.
	 * @param array<string, mixed> $stored The record as stored.
	 * @param array<string, mixed> $incoming The record as it would be written.
	 *
	 * @return void
	 *
	 * @throws RefusedException 409 when the write touches what the freeze holds.
	 *
	 * @spec openspec/specs/frozen-record/spec.md
	 */
	public function assertUpdatable(FreezeDefinition $definition, array $stored, array $incoming): void;

	/**
	 * Whether this write is the one that freezes the record.
	 *
	 * @param FreezeDefinition $definition The freeze entry.
	 * @param array<string, mixed> $stored The record as stored.
	 * @param array<string, mixed> $incoming The record as it would be written.
	 *
	 * @return bool True when the stored record is not frozen and the written one is.
	 *
	 * @spec openspec/specs/frozen-record/spec.md
	 */
	public function isFreezing(FreezeDefinition $definition, array $stored, array $incoming): bool;

	/**
	 * Whether the stored record is frozen.
	 *
	 * @param FreezeDefinition $definition The freeze entry.
	 * @param array<string, mixed> $stored The record as stored.
	 *
	 * @return bool True when frozen.
	 *
	 * @spec openspec/specs/frozen-record/spec.md
	 */
	public function isFrozen(FreezeDefinition $definition, array $stored): bool;

	/**
	 * The refusal for a write the freeze does not allow.
	 *
	 * @param FreezeDefinition $definition The freeze entry.
	 *
	 * @return RefusedException A 409-carrying refusal.
	 *
	 * @spec openspec/specs/frozen-record/spec.md
	 */
	public function refusal(FreezeDefinition $definition): RefusedException;
}//end interface
