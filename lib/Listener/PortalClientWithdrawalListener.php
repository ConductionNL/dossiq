<?php

/**
 * A resident's withdrawal of their own case, told to the case handler.
 *
 * Portaliq raises `OCA\Portaliq\Event\PortalClientWithdrawalEvent`
 * (`portal.withdraw.client`) when a resident ends their own request from the
 * portal. It is its own event, not a status change that happens to look like
 * one, so a handler setting the same status internally never travels this
 * path. Nothing in dossiq listened, so the status landed and neither a rule
 * nor the handler heard why (dossiq#3142). This listener hands the fact to
 * {@see ApplicantPortalActs}, which records it on the timeline with a
 * follow-up and notifies the assignee.
 *
 * 🔴 IT IMPORTS NO PORTALIQ CLASS, for the reason
 * {@see PortalClientWriteListener} gives, and IT NEVER THROWS.
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
 * Tells the handler about a resident's withdrawal in the portal.
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/specs/portal-contribution/spec.md
 */
class PortalClientWithdrawalListener implements IEventListener {

	/**
	 * The portaliq event this listener is registered for.
	 *
	 * A plain string, never imported: see the class docblock.
	 *
	 * @var string
	 */
	public const EVENT = 'OCA\\Portaliq\\Event\\PortalClientWithdrawalEvent';

	/**
	 * Constructor.
	 *
	 * @param ApplicantPortalActs $acts   Records the withdrawal and tells the assignee.
	 * @param LoggerInterface     $logger Logger.
	 */
	public function __construct(
		private readonly ApplicantPortalActs $acts,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Hand one withdrawal to the recorder.
	 *
	 * @param Event $event The portaliq withdrawal event.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/portal-contribution/spec.md
	 */
	public function handle(Event $event): void {
		if (method_exists($event, 'getCaseId') === false || method_exists($event, 'getStatus') === false) {
			return;
		}

		try {
			$this->acts->recordWithdrawal(
				(string)$event->getCaseId(),
				(string)$event->getStatus(),
				$this->optional(event: $event, method: 'getReason'),
				$this->optional(event: $event, method: 'getOccurredAt'),
			);
		} catch (Throwable $e) {
			$this->logger->error(
				'PortalClientWithdrawalListener: a resident withdrawal could not be told to the handler',
				['error' => $e->getMessage()]
			);
		}
	}//end handle()

	/**
	 * Read an optional string getter off the event.
	 *
	 * @param Event  $event  The event.
	 * @param string $method The getter.
	 *
	 * @return string The value, or ''.
	 */
	private function optional(Event $event, string $method): string {
		if (method_exists($event, $method) === false) {
			return '';
		}

		return (string)$event->$method();
	}//end optional()
}//end class
