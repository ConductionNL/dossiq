<?php

/**
 * Where one stored OpenRegister object lives: the object service, its register and schema, and its uuid.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Support;

/**
 * Where one stored OpenRegister object lives: the object service, its register and schema, and its uuid.
 *
 * @spec exclude Parameter object, no functional requirement.
 */
final class StoredObjectAddress {
	/**
	 * Constructor.
	 *
	 * @param object     $objectService The OpenRegister object service.
	 * @param int|string $register      Register id, UUID or slug.
	 * @param int|string $schema        Schema id, UUID or slug.
	 * @param string     $id            The stored object's uuid.
	 */
	public function __construct(
		public readonly object $objectService,
		public readonly int|string $register,
		public readonly int|string $schema,
		public readonly string $id,
	) {
	}//end __construct()
}//end class
