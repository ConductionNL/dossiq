<?php

/**
 * Dossiq DsoStatusChangeNotifier.
 *
 * Dispatches the VergunningStatusChangedEvent when a DSO vergunningzaak
 * changes status. Split out of DsoCaseService alongside the existing
 * DsoDoorsturenNotifier: building and dispatching a domain event is a contract
 * downstream listeners bind to, so it belongs in a collaborator of its own
 * rather than inline in the transition method that happens to trigger it
 * (ADR-022).
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Dso
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
 * @spec openspec/changes/dso-omgevingsloket/tasks.md#T03
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Dso;

use OCA\Dossiq\Event\VergunningStatusChangedEvent;
use OCP\EventDispatcher\IEventDispatcher;

/**
 * Emits the VergunningStatusChanged event for downstream listeners.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/changes/dso-omgevingsloket/tasks.md#T03
 */
class DsoStatusChangeNotifier {
	/**
	 * Constructor.
	 *
	 * @param IEventDispatcher $eventDispatcher The event dispatcher.
	 */
	public function __construct(
		private readonly IEventDispatcher $eventDispatcher,
	) {
	}//end __construct()

	/**
	 * Dispatch the typed status-changed event for a transitioned zaak.
	 *
	 * @param string $requestRef The vergunningaanvraag UUID reference.
	 * @param string $oldStatus The previous status value.
	 * @param string $newStatus The new status value.
	 * @param string|null $besluitdatum Optional decision date (ISO 8601).
	 * @param string|null $notes Optional explanation text.
	 * @param string $userId The Nextcloud UID that triggered the transition.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/dso-omgevingsloket/tasks.md#T03
	 */
	public function dispatchStatusChanged(
		string $requestRef,
		string $oldStatus,
		string $newStatus,
		?string $besluitdatum,
		?string $notes,
		string $userId,
	): void {
		$event = new VergunningStatusChangedEvent(
			requestRef: $requestRef,
			oldStatus: $oldStatus,
			newStatus: $newStatus,
			besluitdatum: $besluitdatum,
			notes: $notes,
			userId: $userId,
		);

		$this->eventDispatcher->dispatchTyped(event: $event);
	}//end dispatchStatusChanged()
}//end class
