<?php

/**
 * An event dispatcher the tests own.
 *
 * It really dispatches: listeners added by name are called in priority order
 * with the event, and `dispatchTyped()` routes by the event's class name, the
 * way Nextcloud's own dispatcher does. A mocked `IEventDispatcher` proves only
 * the answer it was configured with, so the opt-out tests send their question
 * through this one and let a listener answer it.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Support
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
 * @spec openspec/changes/opt-out-before-send/specs/case-message-opt-out/spec.md#requirement-case-mail-asks-integriq-before-it-is-sent-req-coo-001
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Support;

use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use RuntimeException;

/**
 * Calls the listeners it holds, by event name.
 */
final class InMemoryEventDispatcher implements IEventDispatcher {

	/**
	 * Listeners by event name, then priority.
	 *
	 * @var array<string, array<int, list<callable>>>
	 */
	private array $listeners = [];

	/**
	 * Every event dispatched, in order.
	 *
	 * @var list<Event>
	 */
	public array $dispatched = [];

	/**
	 * {@inheritDoc}
	 */
	public function addListener(string $eventName, callable $listener, int $priority = 0): void {
		$this->listeners[$eventName][$priority][] = $listener;
	}//end addListener()

	/**
	 * {@inheritDoc}
	 */
	public function removeListener(string $eventName, callable $listener): void {
		foreach (($this->listeners[$eventName] ?? []) as $priority => $listeners) {
			$this->listeners[$eventName][$priority] = array_values(
				array_filter($listeners, static fn (callable $held): bool => $held !== $listener)
			);
		}
	}//end removeListener()

	/**
	 * {@inheritDoc}
	 */
	public function addServiceListener(string $eventName, string $className, int $priority = 0): void {
		throw new RuntimeException('Service listeners are not supported here; add a callable.');
	}//end addServiceListener()

	/**
	 * {@inheritDoc}
	 */
	public function hasListeners(string $eventName): bool {
		foreach (($this->listeners[$eventName] ?? []) as $listeners) {
			if ($listeners !== []) {
				return true;
			}
		}

		return false;
	}//end hasListeners()

	/**
	 * {@inheritDoc}
	 */
	public function dispatch(string $eventName, Event $event): void {
		$this->dispatched[] = $event;
		$byPriority = ($this->listeners[$eventName] ?? []);
		krsort($byPriority);
		foreach ($byPriority as $listeners) {
			foreach ($listeners as $listener) {
				$listener($event);
			}
		}
	}//end dispatch()

	/**
	 * {@inheritDoc}
	 */
	public function dispatchTyped(Event $event): void {
		$this->dispatch(get_class($event), $event);
	}//end dispatchTyped()
}//end class
