<?php

/**
 * The receipt and start dates are set before the case is stored, above OpenRegister's calculations.
 *
 * A listener on the wrong event, or below the calculation that falls back to
 * `now` in UTC, passes every listener test and still stores the UTC day: the
 * calculation sets `startDate` first and the listener keeps what the case
 * carries. Found live on 11 October 2026. So the wiring is asserted from the
 * registrar.
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
 * @spec openspec/changes/receipt-and-start-dates-are-set-at-creation/specs/burger-notifications/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\AppInfo;

use OCA\Dossiq\AppInfo\Registrar\WorkflowListenerRegistrar;
use OCA\Dossiq\Listener\IntakeTermStartListener;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use PHPUnit\Framework\TestCase;

/**
 * Where IntakeTermStartListener is attached, and at which priority.
 */
class ReceiptDatesRegistrationTest extends TestCase {

	/**
	 * The listener runs on the creating event above priority 0, and on the created event as the fallback.
	 *
	 * @return void
	 */
	public function testTheDatesAreSetBeforeTheCaseIsStoredAboveTheCalculations(): void {
		$registered = [];
		$context = $this->createMock(originalClassName: IRegistrationContext::class);
		$context->method('registerEventListener')->willReturnCallback(
			static function (string $event, string $listener, int $priority = 0) use (&$registered): void {
				if ($listener === IntakeTermStartListener::class) {
					$registered[$event] = $priority;
				}
			}
		);

		(new WorkflowListenerRegistrar())->register(context: $context);

		$this->assertArrayHasKey('OCA\OpenRegister\Event\ObjectCreatingEvent', $registered, 'Set before the case is stored.');
		$this->assertGreaterThan(
			0,
			$registered['OCA\OpenRegister\Event\ObjectCreatingEvent'],
			'Above OpenRegister\'s CalculationOnSaveListener, which registers at the default priority 0.',
		);
		$this->assertArrayHasKey('OCA\OpenRegister\Event\ObjectCreatedEvent', $registered, 'The bulk save only dispatches the created event.');
	}//end testTheDatesAreSetBeforeTheCaseIsStoredAboveTheCalculations()
}//end class
