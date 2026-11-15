<?php

/**
 * Dossiq BesluitMigrationRun.
 *
 * One run of moving the local decision rows onto decidiq's decision schema:
 * the object service and target schema it writes through, the rows already
 * moved, and whether it writes at all.
 *
 * @category Service
 * @package  OCA\Dossiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/zgw-brc/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

/**
 * The state of one migration run, built and read by BesluitMigrationService only.
 *
 * @spec openspec/specs/zgw-brc/spec.md
 */
final class BesluitMigrationRun {
	/**
	 * OpenRegister's object service.
	 *
	 * @var object
	 */
	public readonly object $objectService;

	/**
	 * Decidiq's decision schema id.
	 *
	 * @var string
	 */
	public readonly string $targetSchema;

	/**
	 * The source ids already migrated, as keys.
	 *
	 * @var array<string, mixed>
	 */
	public array $alreadyThere = [];

	/**
	 * Whether the run writes, or only counts.
	 *
	 * @var boolean
	 */
	public bool $commit = false;

	/**
	 * Start a run writing through an object service into a schema.
	 *
	 * @param object $objectService OpenRegister's object service.
	 * @param string $targetSchema  Decidiq's decision schema id.
	 */
	public function __construct(object $objectService, string $targetSchema) {
		$this->objectService = $objectService;
		$this->targetSchema = $targetSchema;
	}//end __construct()
}//end class
