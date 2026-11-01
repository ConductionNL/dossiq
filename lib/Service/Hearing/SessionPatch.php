<?php

/**
 * Dossiq SessionPatch value object.
 *
 * A change to a stored session: the stored record, the changes, and the audit payloads recorded with them.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Hearing
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/bezwaar-hearing/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Hearing;

/**
 * A change to one stored session and the audit payloads recorded with it.
 *
 * @spec openspec/specs/bezwaar-hearing/spec.md
 */
class SessionPatch {
	/**
	 * Constructor.
	 *
	 * @param string                           $sessionId  UUID of the session.
	 * @param array<string, mixed>             $current    The stored session.
	 * @param array<string, mixed>             $update     The changes.
	 * @param array<int, array<string, mixed>> $entries    Audit payloads recorded with the change.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly string $sessionId,
		public readonly array $current,
		public readonly array $update,
		public readonly array $entries = [],
	) {
	}//end __construct()
}//end class
