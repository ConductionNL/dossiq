<?php

/**
 * Contract of ObjectTypeCatalogue
 *
 * The public surface of ObjectTypeCatalogue that other components call, so they depend
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
 * Contract of ObjectTypeCatalogue.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ObjectTypeCatalogueInterface {

	/**
	 * The object types cases link, most used first, then by name.
	 *
	 * Returns an empty list when the register or the case-object schema is not
	 * configured, or when no case links an object. A read OpenRegister refuses
	 * throws, so the caller can tell a failed read from an empty catalogue.
	 *
	 * @return array<int, array{
	 *     id: string, title: string, cases: int, schema: string, schemaLine: string,
	 *     properties: int|null, objects: int|null, views: array<int, string>
	 * }> The rows.
	 *
	 * @throws RuntimeException When OpenRegister is not available.
	 *
	 * @spec openspec/changes/catalogue-object-types/specs/catalogue/spec.md
	 */
	public function list(): array;
}//end interface
