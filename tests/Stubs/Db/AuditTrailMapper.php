<?php

/**
 * Test stub for OpenRegister's AuditTrailMapper.
 *
 * Minimal surface needed by dossiq unit tests: dossiq records audit entries
 * through recordObjectEvent(ObjectAuditEvent). The stub records the event so
 * the test can assert on it. The real OR implementation
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

use OCA\OpenRegister\Db\AuditTrail\ObjectAuditEvent;

/**
 * Stub of OpenRegister's AuditTrailMapper for unit tests.
 */
class AuditTrailMapper {
	/**
	 * Record one audit event on an object, as the real mapper does.
	 *
	 * The event's options name the actor in place of the session user and
	 * the client address, mirroring the real signature.
	 *
	 * @param ObjectAuditEvent $event The object, action, context and options.
	 *
	 * @return AuditTrail The entry, in the shape the real mapper returns
	 */
	public function recordObjectEvent(ObjectAuditEvent $event): AuditTrail {
		// The real mapper returns an AuditTrail entity, never a bare stdClass;
		// StubApiDriftTest compares declared return types against it.
		$entry = new AuditTrail();
		$entry->objectUuid = $event->object->getUuid();
		$entry->action = $event->action;
		$entry->changed = $event->context;
		$entry->actorId = $event->options->actor->id;
		$entry->actorName = $event->options->actor->name;

		return $entry;
	}//end recordObjectEvent()
}//end class
