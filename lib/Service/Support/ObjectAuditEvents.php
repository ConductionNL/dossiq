<?php

/**
 * Dossiq: the audit event OpenRegister's audit trail records for one object
 *
 * OpenRegister's `AuditTrailMapper::recordObjectEvent()` takes one
 * `ObjectAuditEvent`: the object, the action, the context and, in its
 * options, the actor the entry names instead of the session user.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Support
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version GIT: <git-id>
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec exclude builds an OpenRegister value object; the behaviour belongs to each caller's spec
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Support;

use OCA\OpenRegister\Db\AuditTrail\NamedActor;
use OCA\OpenRegister\Db\AuditTrail\ObjectAuditEvent;
use OCA\OpenRegister\Db\AuditTrail\ObjectAuditEventOptions;
use OCA\OpenRegister\Db\ObjectEntity;

/**
 * Builds the `ObjectAuditEvent` for one audit entry.
 */
final class ObjectAuditEvents {

	/**
	 * The event for one entry on an object.
	 *
	 * @param ObjectEntity         $object    The object the entry is about.
	 * @param string               $action    The audit action.
	 * @param array<string, mixed> $context   What the entry records.
	 * @param string|null          $actorId   The actor the entry names; null records the session user.
	 * @param string|null          $actorName The actor's display name.
	 *
	 * @return ObjectAuditEvent The event.
	 *
	 * @spec exclude builds an OpenRegister value object; the behaviour belongs to each caller's spec
	 */
	public static function of(
		ObjectEntity $object,
		string $action,
		array $context = [],
		?string $actorId = null,
		?string $actorName = null,
	): ObjectAuditEvent {
		return new ObjectAuditEvent(
			object: $object,
			action: $action,
			context: $context,
			options: new ObjectAuditEventOptions(actor: new NamedActor(id: $actorId, name: $actorName))
		);
	}//end of()
}//end class
