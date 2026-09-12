<?php

/**
 * Test stub for OpenRegister's AuditTrailMapper.
 *
 * Minimal surface needed by dossiq unit tests: the parafering audit listener
 * calls createAuditTrailEntry(ObjectEntity, string, array). The stub mirrors
 * the real method's full argument list and records
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
	 * `$actorId` and `$actorName` are mirrored from the real method even though
	 * no dossiq caller passes them. OpenRegister grew them so a caller with no
	 * user session can name who acted; a stub that omits them accepts calls the
	 * real class accepts and would also accept a caller written against three
	 * arguments when the fourth is the one that matters live. StubApiDriftTest
	 * caught the omission, which is the whole reason it exists.
	 *
	 * @param ObjectEntity $object The object the entry relates to
	 * @param string $action The action string
	 * @param array<string, mixed> $context Additional context data
	 * @param string|null $actorId Acting user id, when there is no session to read it from
	 * @param string|null $actorName Acting user's display name
	 *
	 * @return object A lightweight audit-trail-like object
	 */
	public function createAuditTrailEntry(
		ObjectEntity $object,
		string $action,
		array $context = [],
		?string $actorId = null,
		?string $actorName = null,
	): object {
		return (object)[
			'objectUuid' => $object->getUuid(),
			'action' => $action,
			'changed' => $context,
			'actorId' => $actorId,
			'actorName' => $actorName,
		];
	}//end createAuditTrailEntry()
}//end class
