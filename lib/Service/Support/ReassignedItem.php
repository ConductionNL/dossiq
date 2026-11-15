<?php

/**
 * One stored object a reassignment writes: the object service that stores it, its schema, its id and its stored data.
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
 * One stored object a reassignment writes: the object service that stores it, its schema, its id and its stored data.
 *
 * @spec exclude Parameter object, no functional requirement.
 */
final class ReassignedItem {
	/**
	 * Constructor.
	 *
	 * @param object              $objectService OpenRegister's object service.
	 * @param string              $schema        The schema.
	 * @param string              $id            The object id.
	 * @param array<string,mixed> $item          The stored object.
	 */
	public function __construct(
		public readonly object $objectService,
		public readonly string $schema,
		public readonly string $id,
		public readonly array $item,
	) {
	}//end __construct()
}//end class
