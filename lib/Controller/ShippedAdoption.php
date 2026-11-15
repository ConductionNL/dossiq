<?php

/**
 * Dossiq shipped adoption.
 *
 * What adopting one shipped configuration object shares between its steps.
 *
 * @category Controller
 * @package  OCA\Dossiq\Controller
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

namespace OCA\Dossiq\Controller;

/**
 * One adoption: which object, from which set, as what, and whether a local change may be lost.
 *
 * Built by {@see StarterContentController} only; it never leaves it.
 *
 * @spec openspec/changes/starter-content-and-templates/specs/case-type-seed-data/spec.md
 */
final class ShippedAdoption {

	/**
	 * The schema the object is of.
	 *
	 * @var string
	 */
	public string $schema = '';

	/**
	 * The setting that names that schema.
	 *
	 * @var string
	 */
	public string $objectsKey = '';

	/**
	 * The object to adopt onto.
	 *
	 * @var string
	 */
	public string $id = '';

	/**
	 * The shipped set.
	 *
	 * @var string
	 */
	public string $set = '';

	/**
	 * The newly shipped object.
	 *
	 * @var array<string, mixed>
	 */
	public array $newShipped = [];

	/**
	 * Whether the administrator accepted losing a local change.
	 *
	 * @var boolean
	 */
	public bool $overLocalChange = false;
}//end class
