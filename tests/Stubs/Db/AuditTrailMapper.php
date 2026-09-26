<?php

/**
 * Test stub for OpenRegister's AuditTrailMapper.
 *
 * Minimal surface needed by dossiq unit tests: the parafering audit listener
 * calls createAuditTrailEntry(ObjectEntity, string, array). The stub records
 * the arguments so the test can assert on them. The real OR implementation
 * persists a hash-chained, append-only audit-trail row.
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
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

/**
 * Stub of OpenRegister's AuditTrailMapper for unit tests.
 */
class AuditTrailMapper {
	/**
	 * Create a custom audit trail entry.
	 *
	 * `$actorId`/`$actorName` let a caller name a non-human principal instead
	 * of the session user. Dossiq does not pass them today, but the stub
	 * carries them so a test that starts to would get a real answer here
	 * rather than a silently dropped argument.
	 *
	 * @param ObjectEntity $object The object the entry relates to
	 * @param string $action The action string
	 * @param array<string, mixed> $context Additional context data
	 * @param string|null $actorId Explicit actor id, bypassing the session user
	 * @param string|null $actorName Explicit actor display name, paired with $actorId
	 * @param string|null $ipAddress Explicit client address, mirroring the real signature
	 *
	 * @return AuditTrail The entry, in the shape the real mapper returns
	 */
	public function createAuditTrailEntry(
		ObjectEntity $object,
		string $action,
		array $context = [],
		?string $actorId = null,
		?string $actorName = null,
		?string $ipAddress = null,
	): AuditTrail {
		// The real mapper returns an AuditTrail entity. This used to hand back a
		// bare stdClass, which is a shape the real class never produces, so
		// anything a test asserted about the return value was green here and
		// wrong live. StubApiDriftTest compares declared return types now, and
		// that is the check that caught it.
		$entry = new AuditTrail();
		$entry->objectUuid = $object->getUuid();
		$entry->action = $action;
		$entry->changed = $context;
		$entry->actorId = $actorId;
		$entry->actorName = $actorName;

		return $entry;
	}//end createAuditTrailEntry()
}//end class
