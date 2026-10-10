<?php

/**
 * A resident's write on their own case, told to the case handler.
 *
 * Portaliq raises `OCA\Portaliq\Event\PortalClientWriteEvent`
 * (`portal.write.client`) once per act a resident takes on their own case
 * through the portal: an amended answer, an added document, an answered task.
 * portaliq is the source and its own docblock says the case app listens.
 * Nothing in dossiq did, so the write landed on the case and the handler only
 * found out by opening it (dossiq#3142). This listener hands the fact to
 * {@see ApplicantPortalActs}, which records it on the timeline with a
 * follow-up and notifies the assignee.
 *
 * 🔴 IT IMPORTS NO PORTALIQ CLASS AND TYPE HINTS NOTHING FROM IT. portaliq is
 * an optional runtime dependency (ADR-046); a type hint on a class the
 * instance does not have is a fatal when the container builds the listener.
 * The registration is guarded on `class_exists` with the FQN string below, so
 * an instance without portaliq boots exactly as before.
 *
 * 🔴 IT NEVER THROWS. An exception escaping a listener stops portaliq's
 * request after the resident's write already landed, and the resident would
 * see an error for a change that was saved.
 *
 * @category Listener
 * @package  OCA\Dossiq\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/portal-contribution/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Listener;

use OCA\Dossiq\Portal\ApplicantPortalActs;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Tells the handler about a resident's write in the portal.
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/specs/portal-contribution/spec.md
 */
class PortalClientWriteListener implements IEventListener {

	/**
	 * The portaliq event this listener is registered for.
	 *
	 * A plain string, never imported: see the class docblock.
	 *
	 * @var string
	 */
	public const EVENT = 'OCA\\Portaliq\\Event\\PortalClientWriteEvent';

	/**
	 * Constructor.
	 *
	 * @param ApplicantPortalActs $acts   Records the act and tells the assignee.
	 * @param LoggerInterface     $logger Logger.
	 */
	public function __construct(
		private readonly ApplicantPortalActs $acts,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Hand one write to the recorder.
	 *
	 * @param Event $event The portaliq write event.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/portal-contribution/spec.md
	 */
	public function handle(Event $event): void {
		if (method_exists($event, 'getCaseId') === false || method_exists($event, 'getAct') === false) {
			return;
		}

		try {
			$fields = [];
			if (method_exists($event, 'getFields') === true) {
				$fields = array_values(array_filter((array)$event->getFields(), 'is_string'));
			}

			$occurredAt = '';
			if (method_exists($event, 'getOccurredAt') === true) {
				$occurredAt = (string)$event->getOccurredAt();
			}

			$mandate = [];
			if (method_exists($event, 'getMandate') === true) {
				$mandate = (array)$event->getMandate();
			}

			$this->acts->recordWrite(
				(string)$event->getCaseId(),
				(string)$event->getAct(),
				$fields,
				$occurredAt,
				$mandate,
			);
		} catch (Throwable $e) {
			$this->logger->error(
				'PortalClientWriteListener: a resident write could not be told to the handler',
				['error' => $e->getMessage()]
			);
		}
	}//end handle()
}//end class
