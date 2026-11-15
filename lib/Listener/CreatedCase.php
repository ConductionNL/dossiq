<?php

/**
 * Dossiq CreatedCase.
 *
 * A case just created: who it is, and the payload it was written with.
 *
 * @category Listener
 * @package  OCA\Dossiq\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git_id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/termijn-binding/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Listener;

/**
 * The case's identity and its created payload.
 *
 * @spec openspec/specs/termijn-binding/spec.md
 */
final class CreatedCase {

	/**
	 * Constructor.
	 *
	 * @param NewCaseIdentity      $identity The case id, case type reference and slug.
	 * @param array<string, mixed> $payload  The case payload.
	 */
	public function __construct(
		public readonly NewCaseIdentity $identity,
		public readonly array $payload,
	) {
	}//end __construct()
}//end class
