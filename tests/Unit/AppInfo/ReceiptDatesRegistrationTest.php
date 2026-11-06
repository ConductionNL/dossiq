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
 * @spec openspec/specs/burger-notifications/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\AppInfo;

use OCA\Dossiq\AppInfo\Registrar\IntakeListenerRegistrar;
use OCA\Dossiq\AppInfo\Registrar\WorkflowListenerRegistrar;
use OCA\Dossiq\Listener\AcknowledgementOnCreateListener;
use OCA\Dossiq\Listener\DeadlineCaseCreatedListener;
use OCA\Dossiq\Listener\IntakeRequirementsListener;
use OCA\Dossiq\Listener\IntakeTermStartListener;
use OCA\Dossiq\Service\Intake\ReceiptMoment;
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
		(new IntakeListenerRegistrar())->register(context: $context);

		$this->assertArrayHasKey('OCA\OpenRegister\Event\ObjectCreatingEvent', $registered, 'Set before the case is stored.');
		$this->assertGreaterThan(
			0,
			$registered['OCA\OpenRegister\Event\ObjectCreatingEvent'],
			'Above OpenRegister\'s CalculationOnSaveListener, which registers at the default priority 0.',
		);
		$this->assertArrayHasKey('OCA\OpenRegister\Event\ObjectCreatedEvent', $registered, 'The bulk save only dispatches the created event.');
	}//end testTheDatesAreSetBeforeTheCaseIsStoredAboveTheCalculations()

	/**
	 * Sending a draft is wired as a receipt: the stamp in the sending save, the receipt effects on the activation.
	 *
	 * Asserted from the registrars the app boots with, so a listener that
	 * handles a sent draft in its own tests and is attached to nothing fails
	 * here (decision 180).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/a-request-form-opens-the-case-at-once/specs/case-intake-destination/spec.md#requirement-the-case-create-must-carry-its-term-fields
	 */
	public function testSendingADraftReachesTheReceiptListeners(): void {
		$registered = [];
		$context = $this->createMock(originalClassName: IRegistrationContext::class);
		$context->method('registerEventListener')->willReturnCallback(
			static function (string $event, string $listener, int $priority = 0) use (&$registered): void {
				$registered[$listener][$event][] = $priority;
			}
		);

		(new WorkflowListenerRegistrar())->register(context: $context);
		(new IntakeListenerRegistrar())->register(context: $context);

		$updating = 'OCA\OpenRegister\Event\ObjectUpdatingEvent';
		$activated = 'OCA\OpenRegister\Event\ObjectActivatedEvent';
		$this->assertTrue(class_exists($activated), 'The event OpenRegister sends when a draft is sent.');
		$this->assertSame($activated, ReceiptMoment::ACTIVATED_EVENT);

		$this->assertSame(
			[IntakeListenerRegistrar::RECEIPT_DATES_PRIORITY],
			($registered[IntakeTermStartListener::class][$updating] ?? []),
			'The stamp and the dates are set once, in the save that sends the draft, above the calculations.',
		);
		$this->assertGreaterThan(0, IntakeListenerRegistrar::RECEIPT_DATES_PRIORITY);
		$this->assertCount(
			1,
			($registered[IntakeTermStartListener::class]['OCA\OpenRegister\Event\ObjectCreatingEvent'] ?? []),
			'One listener sets the term on a create, registered once.',
		);

		foreach ([AcknowledgementOnCreateListener::class, DeadlineCaseCreatedListener::class] as $listener) {
			$this->assertCount(1, ($registered[$listener][$activated] ?? []), $listener . ' hears a sent draft, once.');
			$this->assertCount(
				1,
				($registered[$listener]['OCA\OpenRegister\Event\ObjectCreatedEvent'] ?? []),
				$listener . ' still hears a case created outright.',
			);
		}

		$this->assertArrayHasKey($updating, ($registered[IntakeRequirementsListener::class] ?? []), 'What must be answered is checked on the sending save.');
	}//end testSendingADraftReachesTheReceiptListeners()
}//end class
