<?php

/**
 * Drops the configuration rows read earlier in the request when an object changes.
 *
 * {@see \OCA\Dossiq\Service\CaseTypeStore} and
 * {@see \OCA\Dossiq\Service\Transitions\StatusTypeLookup} keep what they read
 * for the rest of the request. A create, update or delete of any OpenRegister
 * object in the same request drops it, so a service that saves a case type, a
 * status type or a template and reads it back sees what it saved.
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
 * @spec openspec/specs/case-types/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Listener;

use OCA\Dossiq\Kernel\Contracts\CaseTypeStoreInterface;
use OCA\Dossiq\Kernel\Contracts\StatusTypeLookupInterface;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/**
 * Forgets the request's configuration reads on any object change.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/specs/case-types/spec.md
 */
class RequestReadsForgetListener implements IEventListener {
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
	 * @param CaseTypeStoreInterface     $store       The case type store.
	 * @param StatusTypeLookupInterface $statusTypes The status type reads.
	 */
	public function __construct(
		private readonly CaseTypeStoreInterface $store,
		private readonly StatusTypeLookupInterface $statusTypes,
	) {
	}//end __construct()

	/**
	 * Forget the kept reads when the event is an object change.
	 *
	 * @param Event $event The event.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/case-types/spec.md
	 */
	public function handle(Event $event): void {
		foreach (self::EVENTS as $name) {
			if (is_a($event, $name) === true) {
				$this->store->forget();
				$this->statusTypes->forget();
				return;
			}
		}
	}//end handle()
}//end class
