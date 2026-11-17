<?php

/**
 * One operation a connected system sent, with what its connection allows and the connection id.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Cases
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

namespace OCA\Dossiq\Service\Cases;

/**
 * One operation a connected system sent, with what its connection allows and the connection id.
 *
 * @spec exclude Parameter object, no functional requirement.
 */
final class ConnectedSystemWrite {
	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $operation  The generic operation: `action` and its fields
	 * @param array<string, mixed> $connection What the connection allows
	 * @param string               $system     The id of the connection the operation came in on
	 */
	public function __construct(
		public readonly array $operation,
		public readonly array $connection,
		public readonly string $system,
	) {
	}//end __construct()
}//end class
