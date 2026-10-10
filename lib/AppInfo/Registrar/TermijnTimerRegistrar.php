<?php

/**
 * Dossiq termijn timer listener registrar.
 *
 * One clock (termijnbewaking-op-engine-timers): the OpenRegister
 * FlowTimerWorker sweep fires the armed termijn timers, and
 * {@see \OCA\Dossiq\Listener\TermijnTimerFiredListener} does the domain
 * side — threshold bookkeeping, the breach flip, dwangsom accrual sync
 * and pause expiry. Subsystem-scoped per the registrar convention.
 *
 * @category AppInfo
 * @package  OCA\Dossiq\AppInfo\Registrar
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
 * @spec openspec/changes/termijnbewaking-op-engine-timers/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\AppInfo\Registrar;

use OCA\Dossiq\Listener\AdviceTimerFiredListener;
use OCA\Dossiq\Listener\AdviceTimerListener;
use OCA\Dossiq\Listener\BezwaarArchiveTimerFiredListener;
use OCA\Dossiq\Listener\BezwaarArchiveTimerListener;
use OCA\Dossiq\Listener\TermijnTimerFiredListener;
use OCA\Dossiq\Listener\TermStatusClockListener;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Event\FlowTimerFiredEvent;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Registers the engine timer fired-listener.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/changes/termijnbewaking-op-engine-timers/tasks.md
 */
class TermijnTimerRegistrar {
	/**
	 * Register the timer fired-listener.
	 *
	 * The ::class reference does not autoload, so registration is safe
	 * when OpenRegister is absent; the listener simply never fires.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/termijnbewaking-op-engine-timers/tasks.md
	 */
	public function register(IRegistrationContext $context): void {
		$context->registerEventListener(
			event: FlowTimerFiredEvent::class,
			listener: TermijnTimerFiredListener::class
		);

		// The advice deadline: its fire is the reminder and the expiry, and
		// every save that moves a request's status or deadline re-syncs it.
		$context->registerEventListener(
			event: FlowTimerFiredEvent::class,
			listener: AdviceTimerFiredListener::class
		);
		$context->registerEventListener(
			event: ObjectCreatedEvent::class,
			listener: AdviceTimerListener::class
		);
		$context->registerEventListener(
			event: ObjectUpdatedEvent::class,
			listener: AdviceTimerListener::class
		);

		// The bezwaartermijn: its breach archives the beschikking (or, with an
		// objection, only switches the trigger off), and every save that moves
		// a trigger's dates or switch re-syncs it.
		$context->registerEventListener(
			event: FlowTimerFiredEvent::class,
			listener: BezwaarArchiveTimerFiredListener::class
		);
		$context->registerEventListener(
			event: ObjectCreatedEvent::class,
			listener: BezwaarArchiveTimerListener::class
		);
		$context->registerEventListener(
			event: ObjectUpdatedEvent::class,
			listener: BezwaarArchiveTimerListener::class
		);

		// A term runs only in the statuses it declares, so the clock is
		// reconciled with the case's status after every save that landed. It
		// reconciles rather than reacting to a transition: the question is
		// only ever whether the case is in a running status and whether the
		// timer is stopped, which the saved case answers on its own.
		$context->registerEventListener(
			event: ObjectUpdatedEvent::class,
			listener: TermStatusClockListener::class
		);
	}//end register()
}//end class
