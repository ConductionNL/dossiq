<?php

/**
 * Dossiq DsoDoorsturenNotifier.
 *
 * Dispatches the VergunningDoorgestuurd domain event when a DSO case is
 * forwarded to another bevoegd gezag. Split out of DsoController because
 * building and dispatching a domain event is service work, not endpoint shape
 * (ADR-022) — the controller authorizes the mutation and reports the outcome,
 * this collaborator owns the event contract that downstream listeners bind to.
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
 * @spec openspec/changes/dso-omgevingsloket/tasks.md#T07
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Dso;

use OCP\EventDispatcher\GenericEvent;
use OCP\EventDispatcher\IEventDispatcher;

/**
 * Emits the VergunningDoorgestuurd event for downstream listeners.
 *
 * @spec openspec/changes/dso-omgevingsloket/tasks.md#T07
 */
class DsoDoorsturenNotifier {
	/**
	 * The event name downstream listeners bind to.
	 *
	 * @var string
	 */
	private const EVENT_NAME = 'OCA\Dossiq\Event\VergunningDoorgestuurd';

	/**
	 * Constructor.
	 *
	 * @param IEventDispatcher $eventDispatcher The event dispatcher.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IEventDispatcher $eventDispatcher,
	) {
	}//end __construct()

	/**
	 * Dispatch the VergunningDoorgestuurd event for a forwarded case.
	 *
	 * @param array<string,mixed> $case The zaak being forwarded.
	 * @param string $caseId The zaak UUID.
	 * @param string $targetBevoegdGezag The receiving bevoegd gezag.
	 * @param string $reason The reason for forwarding.
	 * @param string $userId The acting user id.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/dso-omgevingsloket/tasks.md#T07
	 */
	public function dispatchDoorgestuurd(
		array $case,
		string $caseId,
		string $targetBevoegdGezag,
		string $reason,
		string $userId,
	): void {
		$event = new GenericEvent(
			subject: $case,
			arguments: [
				'caseId' => $caseId,
				'targetBevoegdGezag' => $targetBevoegdGezag,
				'reason' => $reason,
				'userId' => $userId,
			]
		);

		$this->eventDispatcher->dispatch(
			eventName: self::EVENT_NAME,
			event: $event
		);
	}//end dispatchDoorgestuurd()
}//end class
