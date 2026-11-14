<?php

/**
 * Contract of FreezeRegistry
 *
 * The public surface of FreezeRegistry that other components call, so they depend
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

use OCA\Dossiq\Service\FrozenRecord\FreezeDefinition;

/**
 * Contract of FreezeRegistry.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface FreezeRegistryInterface {

	/**
	 * The entry covering a record, by the schema in its `@self`.
	 *
	 * @param array<string, mixed> $record The record payload (incl. `@self`).
	 *
	 * @return FreezeDefinition|null The entry, or null when none covers it.
	 *
	 * @spec openspec/specs/frozen-record/spec.md
	 */
	public function forRecord(array $record): ?FreezeDefinition;
}//end interface
