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
