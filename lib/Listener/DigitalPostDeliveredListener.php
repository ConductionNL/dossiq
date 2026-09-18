<?php

/**
 * What became of a letter, written on the case that sent it.
 *
 * Integriq dispatches `DigitalPostDeliveredEvent` on EVERY status change of a
 * tracked digital post message. The name says delivered because that is the
 * status anyone waits for, and integriq's own class docblock says plainly
 * that it fires on `failed` too. This listener writes whatever it is told, so
 * a letter that failed is on the case as failed rather than as the last good
 * news anyone heard about it.
 *
 * 🔴 IT IMPORTS NO INTEGRIQ CLASS AND TYPE HINTS NOTHING FROM IT, for the
 * reason {@see IntakeMessageRoutedListener} gives: integriq is an optional
 * runtime dependency, a type hint on a class the instance does not have is a
 * fatal when the container builds the listener, and the registration is
 * guarded on `class_exists` with an FQN string so an instance without integriq
 * boots exactly as it does today and this class is never constructed.
 *
 * 🔴 IT NEVER THROWS. An exception escaping a listener stops integriq's
 * dispatch mid-batch, and one app failing to file a status receipt should not
 * cost every other app on the instance theirs.
 *
 * 🔴 IT RECORDS A STATUS FOR A MESSAGE THIS APP SENT AND NO OTHER. integriq
 * tracks messages for every app on the instance, and the event carries no app
 * id, so the filter is the external message id: a status for an id no dossiq
 * message carries is not ours and is left alone rather than logged as a miss.
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
 * @spec openspec/changes/digital-post-reaches-integriq/specs/berichtenbox-integration/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Listener;

use OCA\Dossiq\Service\BerichtenboxService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Writes integriq's digital post status onto the case that sent the letter.
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/changes/digital-post-reaches-integriq/specs/berichtenbox-integration/spec.md
 */
class DigitalPostDeliveredListener implements IEventListener {

	/**
	 * The integriq event this listener is registered for.
	 *
	 * A plain string, never imported: see the class docblock.
	 *
	 * @var string
	 */
	public const EVENT = 'OCA\\Integriq\\Event\\DigitalPostDeliveredEvent';

	/**
	 * Constructor.
	 *
	 * @param BerichtenboxService $berichtenbox Owns the stored message and the timeline entry.
	 * @param LoggerInterface     $logger       Logger.
	 */
	public function __construct(
		private readonly BerichtenboxService $berichtenbox,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Record one status change.
	 *
	 * @param Event $event The integriq status event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/digital-post-reaches-integriq/specs/berichtenbox-integration/spec.md
	 */
	public function handle(Event $event): void {
		if (method_exists($event, 'getMessageId') === false || method_exists($event, 'getStatus') === false) {
			return;
		}

		try {
			$this->berichtenbox->recordDeliveryStatus(
				externalMessageId: $this->stringFrom(event: $event, method: 'getMessageId'),
				status: $this->stringFrom(event: $event, method: 'getStatus'),
				lastError: $this->stringFrom(event: $event, method: 'getLastError'),
				simulated: $this->boolFrom(event: $event, method: 'isSimulated'),
			);
		} catch (Throwable $e) {
			$this->logger->error(
				'DigitalPostDeliveredListener: a digital post status could not be recorded',
				['error' => $e->getMessage()]
			);
		}
	}//end handle()

	/**
	 * Read a string off the event, whatever it answers.
	 *
	 * @param Event  $event  The event.
	 * @param string $method The getter.
	 *
	 * @return string The value, or ''.
	 */
	private function stringFrom(Event $event, string $method): string {
		if (method_exists($event, $method) === false) {
			return '';
		}

		$value = $event->$method();

		if (is_scalar($value) === true) {
			return trim((string)$value);
		}

		return '';
	}//end stringFrom()

	/**
	 * Read a boolean off the event, whatever it answers.
	 *
	 * @param Event  $event  The event.
	 * @param string $method The getter.
	 *
	 * @return bool The value, or false.
	 */
	private function boolFrom(Event $event, string $method): bool {
		if (method_exists($event, $method) === false) {
			return false;
		}

		return ($event->$method() === true);
	}//end boolFrom()
}//end class
