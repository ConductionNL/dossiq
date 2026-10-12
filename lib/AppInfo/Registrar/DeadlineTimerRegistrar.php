<?php

/**
 * Dossiq deadline timer registrar.
 *
 * Registers the listeners of the four deadline clocks that moved off daily
 * jobs onto OpenRegister engine timers (termijnbewaking-op-engine-timers):
 * the advice term, the bezwaartermijn, the DSO decision term and the
 * milestone stall. Each has a fired-listener on `FlowTimerFiredEvent` and a
 * saved-object listener that re-syncs its timer when a save moves its
 * deadline. It also attaches the routing take-back window: its fired-listener,
 * and the listener that cancels it when the assignee accepts the case
 * (routing-by-weight-position-and-area 3.1). Split from
 * {@see TermijnTimerRegistrar}, which calls this, to
 * keep each registrar's coupling under the phpmd threshold.
 *
 * The ::class references do not autoload, so registration is safe when
 * OpenRegister is absent; the listeners simply never fire.
 *
 * @category AppInfo
 * @package  OCA\Dossiq\AppInfo\Registrar
 *
 * @author    Conduction Development Team <dev@conduction.nl>
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
 * @spec openspec/changes/termijnbewaking-op-engine-timers/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\AppInfo\Registrar;

use OCA\Dossiq\Listener\AdviceTimerFiredListener;
use OCA\Dossiq\Listener\AdviceTimerListener;
use OCA\Dossiq\Listener\BezwaarArchiveTimerFiredListener;
use OCA\Dossiq\Listener\BezwaarArchiveTimerListener;
use OCA\Dossiq\Listener\DsoDeadlineTimerFiredListener;
use OCA\Dossiq\Listener\DsoDeadlineTimerListener;
use OCA\Dossiq\Listener\MilestoneStallTimerFiredListener;
use OCA\Dossiq\Listener\MilestoneStallTimerListener;
use OCA\Dossiq\Listener\RoutedCaseAcceptanceListener;
use OCA\Dossiq\Listener\TakeBackTimerFiredListener;
use OCA\OpenRegister\Event\FlowTimerFiredEvent;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Registers the four deadline clocks' timer listeners.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/changes/termijnbewaking-op-engine-timers/specs/termijnbewaking-op-engine-timers/spec.md
 */
class DeadlineTimerRegistrar {

	/**
	 * The fired-listener of each clock.
	 *
	 * @var array<int, class-string>
	 */
	private const FIRED = [
		AdviceTimerFiredListener::class,
		BezwaarArchiveTimerFiredListener::class,
		DsoDeadlineTimerFiredListener::class,
		MilestoneStallTimerFiredListener::class,
		TakeBackTimerFiredListener::class,
	];

	/**
	 * The saved-object listener of each clock.
	 *
	 * @var array<int, class-string>
	 */
	private const SAVED = [
		AdviceTimerListener::class,
		BezwaarArchiveTimerListener::class,
		DsoDeadlineTimerListener::class,
		MilestoneStallTimerListener::class,
	];

	/**
	 * Register the listeners.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/termijnbewaking-op-engine-timers/tasks.md
	 */
	public function register(IRegistrationContext $context): void {
		foreach (self::FIRED as $listener) {
			$context->registerEventListener(event: FlowTimerFiredEvent::class, listener: $listener);
		}

		foreach (self::SAVED as $listener) {
			$context->registerEventListener(event: ObjectCreatedEvent::class, listener: $listener);
			$context->registerEventListener(event: ObjectUpdatedEvent::class, listener: $listener);
		}

		// Accepting a routed case is an edit of it, so only updates count.
		$context->registerEventListener(event: ObjectUpdatedEvent::class, listener: RoutedCaseAcceptanceListener::class);
	}//end register()
}//end class
