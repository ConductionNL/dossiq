<?php

/**
 * One ledger stamp of an object dossiq seeded from a set
 *
 * The set and its version, the seeded object with its schema and id, and
 * the row as the set ships it: the state ShippedConfigurationService::stamp()
 * builds and its steps read.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Starter
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
 * @spec openspec/changes/starter-content-and-templates/specs/case-type-seed-data/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Starter;

/**
 * The state of one stamp; it never leaves the service that built it.
 *
 * @spec openspec/changes/starter-content-and-templates/specs/case-type-seed-data/spec.md
 */
final class LedgerStamp {

	/**
	 * The set name.
	 *
	 * @var string
	 */
	public string $set = '';

	/**
	 * The set's version.
	 *
	 * @var string
	 */
	public string $version = '';

	/**
	 * The seeded object's schema slug.
	 *
	 * @var string
	 */
	public string $targetSchema = '';

	/**
	 * The seeded object's id.
	 *
	 * @var string
	 */
	public string $targetObject = '';

	/**
	 * The object as it is stamped.
	 *
	 * @var array<string, mixed>
	 */
	public array $object = [];

	/**
	 * The row as the set ships it, or null to hash the object.
	 *
	 * @var array<string, mixed>|null
	 */
	public ?array $shipped = null;
}//end class
