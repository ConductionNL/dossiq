<?php

/**
 * The advice timer listeners are wired, asserted from the registrar.
 *
 * A listener with a full test suite and no registration never runs: the
 * advice request would never be reminded or expired, and every unit test
 * would stay green. So the wiring is asserted from the caller.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\AppInfo
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

namespace OCA\Dossiq\Tests\Unit\AppInfo;

use OCA\Dossiq\AppInfo\Registrar\TermijnTimerRegistrar;
use OCA\Dossiq\Listener\AdviceTimerFiredListener;
use OCA\Dossiq\Listener\AdviceTimerListener;
use OCA\Dossiq\Listener\TermijnTimerFiredListener;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use PHPUnit\Framework\TestCase;

/**
 * What the timer registrar attaches.
 */
class TermijnTimerRegistrarTest extends TestCase {

	/**
	 * The term and advice timer listeners attach to their events.
	 *
	 * @return void
	 */
	public function testTheTimerListenersAreAttached(): void {
		$registered = [];
		$context    = $this->createMock(originalClassName: IRegistrationContext::class);
		$context->method('registerEventListener')->willReturnCallback(
			static function (string $event, string $listener) use (&$registered): void {
				$registered[] = [$event, $listener];
			}
		);

		(new TermijnTimerRegistrar())->register(context: $context);

		foreach ([
			['OCA\OpenRegister\Event\FlowTimerFiredEvent', TermijnTimerFiredListener::class],
			['OCA\OpenRegister\Event\FlowTimerFiredEvent', AdviceTimerFiredListener::class],
			['OCA\OpenRegister\Event\ObjectCreatedEvent', AdviceTimerListener::class],
			['OCA\OpenRegister\Event\ObjectUpdatedEvent', AdviceTimerListener::class],
		] as $pair) {
			self::assertContains(needle: $pair, haystack: $registered, message: implode(' -> ', $pair));
		}
	}//end testTheTimerListenersAreAttached()
}//end class
