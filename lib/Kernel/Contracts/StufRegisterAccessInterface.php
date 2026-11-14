<?php

/**
 * Contract of StufRegisterAccess
 *
 * The public surface of StufRegisterAccess that other components call, so they depend
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
 * Contract of StufRegisterAccess.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface StufRegisterAccessInterface {

	public const SCHEMA_ENDPOINT = 'stufEndpoint';

	public const SCHEMA_MESSAGE = 'stufMessage';

	/**
	 * Find many objects matching the filter.
	 *
	 * @param string $schema The schema slug.
	 * @param array<string,scalar> $filters The filter map.
	 * @param int $limit The page size.
	 *
	 * @return array<int,array<string,mixed>>
	 *
	 * @spec openspec/specs/stuf-zkn-outbound/spec.md
	 */
	public function findAll(string $schema, array $filters = [], int $limit = 100): array;

	/**
	 * Find one object by its UUID; returns null when it does not exist.
	 *
	 * The dedicated get-by-id path. A `['id' => $uuid]` entry in the
	 * `findOne()`/`findAll()` filter map does NOT resolve: OpenRegister
	 * treats top-level filter keys as schema properties, none of the StUF
	 * schemas declare an `id` property, and the search silently returns
	 * zero rows. `ObjectService::find()` resolves ids and UUIDs directly.
	 *
	 * @param string $schema The schema slug (one of the SCHEMA_* constants).
	 * @param string $id The object UUID.
	 *
	 * @return array|null The object as plain array, or null.
	 *
	 * @spec openspec/specs/stuf-zkn-outbound/spec.md
	 */
	public function findById(string $schema, string $id): ?array;

	/**
	 * Find one object by filter; returns null when there is no match.
	 *
	 * @param string $schema The schema slug.
	 * @param array<string,scalar> $filters The filter map merged into OR `findAll`.
	 *
	 * @return array|null The object as plain array, or null.
	 *
	 * @spec openspec/specs/stuf-zkn-outbound/spec.md
	 */
	public function findOne(string $schema, array $filters): ?array;
}//end interface
