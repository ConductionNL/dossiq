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

namespace OCA\Dossiq\Listener;

use OCA\Dossiq\Service\People\CaseRoleProjection;
use OCA\OpenRegister\Event\PersonLinkedEvent;
use OCA\OpenRegister\Event\PersonLinkUpdatedEvent;
use OCA\OpenRegister\Event\PersonUnlinkedEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Projects a person linked to a case onto the case's role records.
 *
 * OpenRegister announces every person link on every object; this hears the
 * three events, hands the link to the projection and gets out of the way of
 * objects that are not cases. It never throws: a failed projection must not
 * fail the link the handler just made.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/people-on-the-case/specs/people-on-the-case/spec.md#requirement-req-poc-001-a-person-linked-to-a-case-shall-become-a-role-record-on-that-case
 */
class PersonLinkListener implements IEventListener {

	/**
	 * @param CaseRoleProjection $projection Turns a link into a role record.
	 * @param LoggerInterface $logger Says what could not be projected.
	 */
	public function __construct(
		private readonly CaseRoleProjection $projection,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Project a link that was made or changed, retire one that is gone.
	 *
	 * @param Event $event The person link event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/people-on-the-case/specs/people-on-the-case/spec.md#requirement-req-poc-001-a-person-linked-to-a-case-shall-become-a-role-record-on-that-case
	 */
	public function handle(Event $event): void {
		$link = $this->linkOf(event: $event);
		if ($link === []) {
			return;
		}

		try {
			if ($event instanceof PersonUnlinkedEvent) {
				$this->projection->retire(link: $link);
				return;
			}

			$this->projection->project(link: $link);
		} catch (Throwable $e) {
			$this->logger->error(
				'Dossiq people: the projection of a person link failed: ' . $e->getMessage(),
				['exception' => $e, 'object' => ($link['objectUuid'] ?? ''), 'person' => ($link['contactUid'] ?? '')]
			);
		}
	}//end handle()

	/**
	 * The link an event carries, as a plain row.
	 *
	 * @param Event $event The event.
	 *
	 * @return array<string, mixed> The link, [] when the event carries none.
	 */
	private function linkOf(Event $event): array {
		if ($event instanceof PersonLinkedEvent || $event instanceof PersonLinkUpdatedEvent || $event instanceof PersonUnlinkedEvent) {
			return $this->rowOf(link: $event->getLink());
		}

		return [];
	}//end linkOf()

	/**
	 * A link entity as a plain row.
	 *
	 * @param object $link OpenRegister's ContactLink.
	 *
	 * @return array<string, mixed> The row.
	 */
	private function rowOf(object $link): array {
		if (is_callable([$link, 'jsonSerialize']) === false) {
			return (array)$link;
		}

		$row = call_user_func([$link, 'jsonSerialize']);
		if (is_array($row) === false) {
			return [];
		}

		return $row;
	}//end rowOf()
}//end class
