<?php

/**
 * The four deadline clocks' listeners are attached.
 *
 * A listener that nothing registers never runs, and its unit tests stay
 * green; the advice, bezwaar, DSO and milestone deadlines would silently stop
 * with their jobs retired. So each pair is asserted from the registrar.
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

use OCA\Dossiq\AppInfo\Registrar\DeadlineTimerRegistrar;
use OCA\Dossiq\Listener\AdviceTimerFiredListener;
use OCA\Dossiq\Listener\MilestoneStallTimerListener;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use PHPUnit\Framework\TestCase;

/**
 * Four fired-listeners and four saved-object listeners, each on its events.
 */
class DeadlineTimerRegistrarTest extends TestCase {

	/**
	 * Every clock has a fired-listener and a saved-object listener on create and update.
	 *
	 * @return void
	 */
	public function testEveryClockIsAttached(): void {
		$registered = [];
		$context    = $this->createMock(originalClassName: IRegistrationContext::class);
		$context->method('registerEventListener')->willReturnCallback(
			static function (string $event, string $listener) use (&$registered): void {
				$registered[] = [$event, $listener];
			}
		);

		(new DeadlineTimerRegistrar())->register(context: $context);

		$this->assertCount(expectedCount: 12, haystack: $registered);
		$this->assertContains(needle: ['OCA\OpenRegister\Event\FlowTimerFiredEvent', AdviceTimerFiredListener::class], haystack: $registered);
		$this->assertContains(needle: ['OCA\OpenRegister\Event\ObjectCreatedEvent', MilestoneStallTimerListener::class], haystack: $registered);
		$this->assertContains(needle: ['OCA\OpenRegister\Event\ObjectUpdatedEvent', MilestoneStallTimerListener::class], haystack: $registered);
	}//end testEveryClockIsAttached()
}//end class
