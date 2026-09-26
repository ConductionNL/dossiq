<?php

/**
 * A message integriq routed at a case, answered.
 *
 * Integriq's `IntakeRoutingService::route()` dispatches
 * `IntakeMessageRoutedEvent` and then reads its result slot. An empty slot
 * holds the message with "No app opened a case for this message". Until this
 * listener existed that sentence was true; now it would be a lie, so this
 * class answers the slot when it opens a case and {@see ChannelIntake} writes
 * the reason to the intake log when it does not.
 *
 * 🔴 IT DECIDES NOTHING. Whether a message becomes a case, whether it is a
 * duplicate and what the refusal says are {@see ChannelIntake}'s. This class
 * reads the event and hands the values over, which is the same division
 * {@see FormSubmittedListener} keeps with {@see \OCA\Dossiq\Service\FormsIntakeService}.
 *
 * 🔴 IT IMPORTS NO INTEGRIQ CLASS AND TYPE HINTS NOTHING FROM IT. integriq is
 * an optional runtime dependency; a type hint on a class the instance does
 * not have is a fatal when the container builds the listener rather than a
 * feature that is quietly missing. The registration is guarded on
 * `class_exists` with an FQN string, so an instance without integriq boots
 * exactly as it does today and this listener is never constructed. Reads are
 * duck-typed for the same reason.
 *
 * 🔴 IT NEVER THROWS. An exception escaping a listener stops integriq's
 * dispatch mid-batch, and a message that cannot be handled should be held
 * with its reason, not lost with a stack trace. Every failure is caught and
 * turned into an empty slot.
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
 * @spec openspec/changes/an-intake-message-opens-a-case/specs/intake-from-a-channel/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Listener;

use OCA\Dossiq\Service\Intake\ChannelIntake;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Hands a routed channel message to the intake, and answers the result slot.
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/changes/an-intake-message-opens-a-case/specs/intake-from-a-channel/spec.md
 */
class IntakeMessageRoutedListener implements IEventListener {

	/**
	 * The integriq event this listener is registered for.
	 *
	 * A plain string, never imported: see the class docblock.
	 *
	 * @var string
	 */
	public const EVENT = 'OCA\\Integriq\\Event\\IntakeMessageRoutedEvent';

	/**
	 * Constructor.
	 *
	 * @param ChannelIntake   $intake Decides whether a message opens a case.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly ChannelIntake $intake,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Offer one routed message to the intake.
	 *
	 * @param Event $event The integriq routing event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/an-intake-message-opens-a-case/specs/intake-from-a-channel/spec.md
	 */
	public function handle(Event $event): void {
		if (method_exists($event, 'getTargetSchema') === false) {
			return;
		}

		try {
			$targetSchema = (string)$event->getTargetSchema();
			if ($this->intake->isForACase(targetSchema: $targetSchema) === false) {
				// Another app's rule. Answering it would take the message away
				// from whoever it was addressed to.
				return;
			}

			$caseId = $this->intake->caseFor(
				message: $this->arrayFrom(event: $event, method: 'getMessage'),
				targetPayload: $this->arrayFrom(event: $event, method: 'getTargetPayload'),
				ruleName: $this->stringFrom(event: $event, method: 'getRuleName'),
			);

			if ($caseId === '' || method_exists($event, 'setCreatedRef') === false) {
				return;
			}

			$event->setCreatedRef($caseId);
		} catch (Throwable $e) {
			// The slot stays empty, so integriq holds the message. That is the
			// same outcome as before this listener existed, and it is the right
			// one: a message we could not handle is still a message somebody
			// sent us.
			$this->logger->error(
				'IntakeMessageRoutedListener: a routed message could not be handled, so it stays held',
				['error' => $e->getMessage()]
			);
		}
	}//end handle()

	/**
	 * Read an array off the event, whatever it answers.
	 *
	 * @param Event  $event  The event.
	 * @param string $method The getter.
	 *
	 * @return array<string, mixed> The value, or an empty array.
	 */
	private function arrayFrom(Event $event, string $method): array {
		if (method_exists($event, $method) === false) {
			return [];
		}

		$value = $event->$method();

		if (is_array($value) === true) {
			return $value;
		}

		return [];
	}//end arrayFrom()

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
}//end class
