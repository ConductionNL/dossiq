<?php

/**
 * Drops a user's kept tenant memberships when a tenantUser row changes.
 *
 * {@see \OCA\Dossiq\Service\Tenant\TenantMembershipCache} keeps the tenant ids
 * of a user for a minute. A create, update or delete of a tenantUser row (the
 * one schema that carries both `tenantRef` and `userRef`) drops the entries of
 * the users the row names before and after the change, so joining or leaving a
 * tenant applies at once.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Listener
 * @package  OCA\Dossiq\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/multi-tenancy/spec.md#req-005-tenant-membership-and-status-helpers-for-middleware
 */

declare(strict_types=1);

namespace OCA\Dossiq\Listener;

use OCA\Dossiq\Service\Tenant\TenantMembershipCache;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/**
 * Forgets the kept memberships of the users a changed tenantUser row names.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/specs/multi-tenancy/spec.md#req-005-tenant-membership-and-status-helpers-for-middleware
 */
class TenantMembershipChangedListener implements IEventListener {
	/**
	 * The object events, by name: OpenRegister may be absent.
	 */
	public const EVENTS = [
		'OCA\\OpenRegister\\Event\\ObjectCreatedEvent',
		'OCA\\OpenRegister\\Event\\ObjectUpdatedEvent',
		'OCA\\OpenRegister\\Event\\ObjectDeletedEvent',
	];

	/**
	 * Constructor.
	 *
	 * @param TenantMembershipCache $memberships The kept memberships.
	 */
	public function __construct(
		private readonly TenantMembershipCache $memberships,
	) {
	}//end __construct()

	/**
	 * Forget the memberships of the users a changed tenantUser row names.
	 *
	 * @param Event $event The object event.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/multi-tenancy/spec.md#req-005-tenant-membership-and-status-helpers-for-middleware
	 */
	public function handle(Event $event): void {
		foreach (self::EVENTS as $name) {
			if (is_a($event, $name) === true) {
				foreach ($this->usersNamedBy(event: $event) as $userId) {
					$this->memberships->forget(userId: $userId);
				}

				return;
			}
		}
	}//end handle()

	/**
	 * The users the event's tenantUser rows name, before and after an update.
	 *
	 * @param Event $event The object event.
	 *
	 * @return array<int, string>
	 */
	private function usersNamedBy(Event $event): array {
		$users = [];
		foreach (['getObject', 'getNewObject', 'getOldObject'] as $getter) {
			if (method_exists($event, $getter) === false) {
				continue;
			}

			$entity = $event->$getter();
			if (is_object($entity) === false || method_exists($entity, 'getObject') === false) {
				continue;
			}

			$data = $entity->getObject();
			if (is_array($data) === true && array_key_exists('tenantRef', $data) === true && trim((string)($data['userRef'] ?? '')) !== '') {
				$users[] = trim((string)$data['userRef']);
			}
		}

		return array_values(array_unique($users));
	}//end usersNamedBy()
}//end class
