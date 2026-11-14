<?php

/**
 * Contract of SociaalDomeinStore
 *
 * The public surface of SociaalDomeinStore that other components call, so they depend
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

/**
 * Contract of SociaalDomeinStore.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface SociaalDomeinStoreInterface {

	/**
	 * One row by its uuid, or null.
	 *
	 * @param string $schema The schema slug.
	 * @param string $id     The object uuid.
	 *
	 * @return array<string, mixed>|null The row.
	 *
	 * @throws RefusedException When the store cannot be asked.
	 *
	 * @spec openspec/specs/dossiq-sociaal-domein-jeugdwet/spec.md
	 */
	public function read(string $schema, string $id): ?array;

	/**
	 * Rows of one schema matching these filters.
	 *
	 * 🔴 AN UNREADABLE STORE IS NOT AN EMPTY LIST, AND HERE IT REFUSES. A plan
	 * that could not be read and a household with no plan look identical as an
	 * empty array, and only one of them is safe to act on: a consulent shown an
	 * empty plan writes a new one over the plan that is already there.
	 *
	 * @param string               $schema  The schema slug.
	 * @param array<string, mixed> $filters The bare filter keys.
	 *
	 * @return array<int, array<string, mixed>> The rows.
	 *
	 * @throws RefusedException When the store cannot be asked.
	 *
	 * @spec openspec/specs/dossiq-sociaal-domein-jeugdwet/spec.md
	 */
	public function rows(string $schema, array $filters = []): array;

	/**
	 * Write one row, and refuse rather than answer null when it fails.
	 *
	 * @param string               $schema The schema slug.
	 * @param array<string, mixed> $object The object.
	 *
	 * @return array<string, mixed> The stored object.
	 *
	 * @throws RefusedException When the write fails.
	 *
	 * @spec openspec/specs/dossiq-sociaal-domein-jeugdwet/spec.md
	 */
	public function write(string $schema, array $object): array;
}//end interface
