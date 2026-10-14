<?php

/**
 * Announces a case the system wrote, as OpenRegister announces any other.
 *
 * 🔴 OPENREGISTER HOLDS BACK THE CREATED EVENT OF A SYSTEM WRITE. A write
 * inside `ObjectService::runAsSystem()` runs in OpenRegister's system
 * operation context, and `MagicMapper::suppressLifecycleEvents()` withholds
 * `ObjectCreatedEvent` there, on purpose: a configuration import should not
 * wake every listening app for each seeded object. A case a resident files
 * through the portal is written that way too ({@see SystemCaseStore}), and it
 * is not seed data. Every listener that answers a new case missed it: no
 * acknowledgement of receipt was queued (Awb 4:3a), no term was bound, no
 * case number backfilled, no chain of custody started. Found on :8080 with
 * probe case 2026-0104 (14 October).
 *
 * So after the system write this class dispatches the event OpenRegister held
 * back, once, for that one case. The pre-save `ObjectCreatingEvent` is not
 * withheld by OpenRegister and is not sent again.
 *
 * The event goes out inside a system operation as well. The portal request is
 * anonymous, and a listener that reads or writes the new case would otherwise
 * be refused by the access rules the system write was elevated past. Its own
 * follow-up writes are then quiet too, as every write in a system operation is.
 *
 * 🔴 NOTHING HERE NAMES AN OPENREGISTER CLASS AS A SYMBOL, like
 * {@see ReceiptMoment}: an instance without OpenRegister still loads this app.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Intake
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/a-portal-filed-case-opens-like-a-staff-created-one/specs/woo-request-intake/spec.md#requirement-a-case-the-system-writes-opens-as-a-staff-created-case-does-req-wri-005
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Intake;

use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Dispatches the created event OpenRegister withheld for a system write.
 *
 * @spec openspec/changes/a-portal-filed-case-opens-like-a-staff-created-one/specs/woo-request-intake/spec.md#requirement-a-case-the-system-writes-opens-as-a-staff-created-case-does-req-wri-005
 */
class CaseCreationAnnouncer {

	/**
	 * OpenRegister's created event, by name.
	 */
	public const CREATED_EVENT = 'OCA\\OpenRegister\\Event\\ObjectCreatedEvent';

	/**
	 * The entity that event carries, by name.
	 */
	public const OBJECT_ENTITY = 'OCA\\OpenRegister\\Db\\ObjectEntity';

	/**
	 * Constructor.
	 *
	 * @param IEventDispatcher $dispatcher The Nextcloud event dispatcher.
	 * @param LoggerInterface  $logger     Logger.
	 */
	public function __construct(
		private readonly IEventDispatcher $dispatcher,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Announce one case written inside a system operation.
	 *
	 * A failing listener is logged and does not undo the case: it is saved,
	 * and the resident is told so. That is also how a staff-created case
	 * behaves when one of its listeners fails.
	 *
	 * @param object $objectService The OpenRegister ObjectService the case was written through.
	 * @param mixed  $saved         What the write returned.
	 *
	 * @return bool True when the event went out.
	 *
	 * @spec openspec/changes/a-portal-filed-case-opens-like-a-staff-created-one/specs/woo-request-intake/spec.md#requirement-a-case-the-system-writes-opens-as-a-staff-created-case-does-req-wri-005
	 */
	public function announce(object $objectService, mixed $saved): bool {
		$event = $this->createdEvent(saved: $saved);
		if ($event === null) {
			$this->logger->warning('CaseCreationAnnouncer: the written case is not an OpenRegister object, so it was not announced');

			return false;
		}

		$caseId = '';
		if (is_object($saved) === true && method_exists($saved, 'getUuid') === true) {
			$caseId = (string)$saved->getUuid();
		}

		$dispatch = function () use ($event): bool {
			$this->dispatcher->dispatchTyped($event);

			return true;
		};

		try {
			if (method_exists($objectService, 'runAsSystem') === true) {
				return (bool)$objectService->runAsSystem($dispatch);
			}

			return $dispatch();
		} catch (Throwable $e) {
			$this->logger->error(
				'CaseCreationAnnouncer: a listener failed on the new case',
				['case' => $caseId, 'error' => $e->getMessage()]
			);
		}

		return false;
	}//end announce()

	/**
	 * The created event for what the write returned, or null when it cannot be built.
	 *
	 * @param mixed $saved What the write returned.
	 *
	 * @return Event|null The event.
	 */
	private function createdEvent(mixed $saved): ?Event {
		$eventClass  = self::CREATED_EVENT;
		$entityClass = self::OBJECT_ENTITY;
		if (class_exists($eventClass) === false || ($saved instanceof $entityClass) === false) {
			return null;
		}

		$event = new $eventClass($saved);
		if (($event instanceof Event) === false) {
			return null;
		}

		return $event;
	}//end createdEvent()
}//end class
