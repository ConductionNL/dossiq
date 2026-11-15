<?php

/**
 * Contract of ReadableObjects
 *
 * The public surface of ReadableObjects that other components call, so they depend
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
 * Contract of ReadableObjects.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ReadableObjectsInterface {

	/**
	 * Whether the caller may read the object with this id in this schema.
	 *
	 * @param string $schema The schema slug.
	 * @param string $id     The object's uuid.
	 *
	 * @return bool True when the caller's own read finds it.
	 *
	 * @throws \Throwable When the register cannot be read.
	 *
	 * @spec openspec/specs/authz-bypass-fixes/spec.md
	 */
	public function findsAsCaller(string $schema, string $id): bool;

	/**
	 * Whether a caller is signed in; without one nothing is readable.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/authz-bypass-fixes/spec.md
	 */
	public function hasCaller(): bool;

	/**
	 * The master entities the caller may read: those a person or company the caller may read carries.
	 *
	 * @param array<int, string> $refs The master entity references.
	 *
	 * @return array<int, string> The readable references, in the order asked.
	 *
	 * @throws \Throwable When the register cannot be read.
	 *
	 * @spec openspec/specs/authz-bypass-fixes/spec.md
	 */
	public function readableMasterEntities(array $refs): array;
}//end interface
