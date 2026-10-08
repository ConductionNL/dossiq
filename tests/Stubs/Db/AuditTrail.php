<?php

/**
 * Test stub for OpenRegister's AuditTrail entity.
 *
 * It exists so `AuditTrailMapper::createAuditTrailEntry()` can hand back what the
 * real mapper hands back. Before this, the stub returned a bare `object`, which is
 * a shape the real class never produces: anything a test asserted about the return
 * value agreed with the stub and would have been fatal against a real
 * OpenRegister. `StubApiDriftTest` now compares declared return types, and that is
 * the check that found it.
 *
 * Only the two accessors OpenRegister declares itself are declared here.
 * Everything else on the real entity comes from `OCP\AppFramework\Db\Entity`'s
 * magic `__call`, so `getAction()` and friends resolve there rather than being
 * restated in a double that would then have to be kept in step.
 *
 * @category Stub
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec exclude A declaration-only double of another app's entity, not behaviour of this one.
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

/**
 * Stub of OpenRegister's AuditTrail entity for unit tests.
 */
class AuditTrail {

	/**
	 * The audited object's uuid.
	 *
	 * @var string|null
	 */
	public ?string $objectUuid = null;

	/**
	 * The audited action.
	 *
	 * @var string|null
	 */
	public ?string $action = null;

	/**
	 * The recorded payload.
	 *
	 * @var array<string, mixed>|null
	 */
	public ?array $changed = null;

	/**
	 * Who acted, when a caller named a non-human principal.
	 *
	 * @var string|null
	 */
	public ?string $actorId = null;

	/**
	 * That principal's display name.
	 *
	 * @var string|null
	 */
	public ?string $actorName = null;

	/**
	 * When the payload was removed under a retention policy.
	 *
	 * @var string|null
	 */
	public ?string $purgedAt = null;

	/**
	 * Whether this row is a purge tombstone rather than an intact record.
	 *
	 * @return bool True when the payload has been purged.
	 */
	public function isPurged(): bool {
		return $this->purgedAt !== null;
	}//end isPurged()

	/**
	 * The recorded payload, or an empty array.
	 *
	 * @return array<string, mixed> The changed data.
	 */
	public function getChanged(): array {
		return ($this->changed ?? []);
	}//end getChanged()
}//end class
