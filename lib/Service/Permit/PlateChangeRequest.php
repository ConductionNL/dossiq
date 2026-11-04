<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Permit;

/**
 * What a resident asked for: a new plate on a permit, for the resident the assertion names.
 *
 * @spec openspec/specs/portal-contribution/spec.md
 */
final class PlateChangeRequest {
	/**
	 * A Dutch licence plate without dashes: six letters and digits.
	 */
	private const PLATE = '/^[A-Z0-9]{6}$/';

	/**
	 * Constructor.
	 *
	 * @param string $subjectRef The resident, from the verified assertion.
	 * @param string $permitId   The permit the resident named.
	 * @param string $plate      The new plate, normalised.
	 */
	public function __construct(
		public readonly string $subjectRef,
		public readonly string $permitId,
		public readonly string $plate,
	) {
	}//end __construct()

	/**
	 * Whether the request names a resident, a permit and a plate of six letters and digits.
	 *
	 * @return bool True when it can be used.
	 *
	 * @spec openspec/specs/portal-contribution/spec.md
	 */
	public function isUsable(): bool {
		return trim($this->subjectRef) !== '' && trim($this->permitId) !== '' && preg_match(self::PLATE, $this->plate) === 1;
	}//end isUsable()

	/**
	 * Whether this permit is held by the resident who asks.
	 *
	 * @param array<string, mixed>|null $permit The permit as read, or null.
	 *
	 * @return bool True when the permit's subject is the asking resident.
	 *
	 * @spec openspec/specs/portal-contribution/spec.md
	 */
	public function isHeldBySubject(?array $permit): bool {
		return $permit !== null && (string)($permit['portalSubject'] ?? '') === $this->subjectRef;
	}//end isHeldBySubject()
}//end class
