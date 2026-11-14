<?php

/**
 * Dossiq StufZdsCaseConcludedListener.
 *
 * Integriq concludes every StUF-ZDS message dossiq asked for with a
 * StufZdsCaseConcludedEvent. A message that failed for good reaches the
 * administrators as the Notifier's stuf_* notification, as it did when dossiq
 * sent StUF itself: CIRCUIT_OPEN as stuf_circuit_open, TIMEOUT as stuf_timeout,
 * anything else as stuf_permanent_error. A success, or another app's message,
 * is not dossiq's to report.
 *
 * Integriq is an optional runtime dependency, so the registrar names the event
 * class as a string; here instanceof is false while integriq is absent.
 *
 * @category Listener
 * @package  OCA\Dossiq\Listener
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/stuf-onto-integriq/specs/stuf-zkn-outbound/spec.md#requirement-a-failed-message-still-reaches-the-administrators
 */

declare(strict_types=1);

namespace OCA\Dossiq\Listener;

use OCA\Dossiq\Kernel\AppIdentity;
use OCA\Dossiq\Kernel\Contracts\NeedsInputDispatcherInterface;
use OCA\Dossiq\Kernel\Contracts\NotifierInterface;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/**
 * Turns a failed StUF-ZDS conclusion into an administrator notification.
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/changes/stuf-onto-integriq/specs/stuf-zkn-outbound/spec.md#requirement-a-failed-message-still-reaches-the-administrators
 */
class StufZdsCaseConcludedListener implements IEventListener {

	/**
	 * Integriq's conclusion event.
	 *
	 * @var string
	 */
	public const EVENT_CLASS = 'OCA\Integriq\Event\StufZdsCaseConcludedEvent';

	/**
	 * Constructor.
	 *
	 * @param NeedsInputDispatcherInterface $needsInput Notifies the administrators.
	 */
	public function __construct(
		private readonly NeedsInputDispatcherInterface $needsInput,
	) {

	}//end __construct()

	/**
	 * Handle a conclusion.
	 *
	 * @param Event $event The event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/stuf-onto-integriq/specs/stuf-zkn-outbound/spec.md#requirement-a-failed-message-still-reaches-the-administrators
	 */
	public function handle(Event $event): void {
		// Integriq's class, optional at runtime: instanceof on an absent class is
		// simply false, as in DeliveryConcludedListener.
		if (($event instanceof \OCA\Integriq\Event\StufZdsCaseConcludedEvent) === false || $event->getSourceApp() !== AppIdentity::APP_ID) {
			return;
		}

		$result = (array)$event->getResult();
		if (($result['success'] ?? false) === true || ($result['retrying'] ?? false) === true) {
			return;
		}

		$code = (string)(((array)($result['fout'] ?? []))['code'] ?? '');
		$this->needsInput->dispatch(
			type: $this->subjectFor(code: $code),
			context: $this->contextOf(event: $event, result: $result)
		);
	}//end handle()

	/**
	 * The notification subject for a StUF failure code.
	 *
	 * @param string $code The failure code.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/stuf-onto-integriq/specs/stuf-zkn-outbound/spec.md#requirement-a-failed-message-still-reaches-the-administrators
	 */
	private function subjectFor(string $code): string {
		return match ($code) {
			'CIRCUIT_OPEN' => NotifierInterface::SUBJECT_STUF_CIRCUIT_OPEN,
			'TIMEOUT' => NotifierInterface::SUBJECT_STUF_TIMEOUT,
			default => NotifierInterface::SUBJECT_STUF_PERMANENT_ERROR,
		};
	}//end subjectFor()

	/**
	 * What the notification tells the administrator about the failed StUF write.
	 *
	 * @param object               $event  Integriq's StufZdsCaseConcludedEvent.
	 * @param array<string, mixed> $result The write's result.
	 *
	 * @return array<string, string>
	 *
	 * @spec openspec/changes/stuf-onto-integriq/specs/stuf-zkn-outbound/spec.md#requirement-a-failed-message-still-reaches-the-administrators
	 */
	private function contextOf(object $event, array $result): array {
		$fout = (array)($result['fout'] ?? []);

		// StufIntegriqBridge correlates as `<endpointId>:<random>`.
		return [
			'endpointId' => explode(':', $event->getCorrelationId(), 2)[0],
			'operation' => $event->getOperation(),
			'referentienummer' => (string)($result['referentienummer'] ?? ''),
			'fout' => (string)($fout['code'] ?? ''),
			'omschrijving' => (string)($fout['omschrijving'] ?? ''),
		];
	}//end contextOf()
}//end class
