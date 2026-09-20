<?php

/**
 * Unit tests for the digital post status listener.
 *
 * integriq's event fires on EVERY status change of a tracked message, and its
 * own docblock says a consumer listening only for the happy path will hear
 * about the unhappy one too. These arms are what prove this listener does not
 * quietly drop the unhappy one, which would leave a case showing the last good
 * news anyone heard about a letter that failed.
 *
 * The double uses `onlyMethods`, never `addMethods`.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Listener
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/berichtenbox-integration/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Listener;

use OCA\Dossiq\Listener\DigitalPostDeliveredListener;
use OCA\Dossiq\Service\BerichtenboxService;
use OCA\Integriq\Event\DigitalPostDeliveredEvent;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests that every status integriq reports reaches the case.
 *
 * @covers \OCA\Dossiq\Listener\DigitalPostDeliveredListener
 * @uses \OCA\Dossiq\Service\BerichtenboxService
 */
class DigitalPostDeliveredListenerTest extends TestCase {
	/**
	 * @var BerichtenboxService|MockObject
	 */
	private $berichtenbox;

	/**
	 * Set up the collaborator.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->berichtenbox = $this->getMockBuilder(BerichtenboxService::class)
			->disableOriginalConstructor()
			->onlyMethods(['recordDeliveryStatus'])
			->getMock();
	}//end setUp()

	/**
	 * Build the subject under test.
	 *
	 * @return DigitalPostDeliveredListener
	 */
	private function listener(): DigitalPostDeliveredListener {
		return new DigitalPostDeliveredListener(
			$this->berichtenbox,
			$this->createMock(LoggerInterface::class)
		);
	}//end listener()

	/**
	 * The listener is registered for the name integriq actually dispatches.
	 *
	 * A listener bound to an event nobody fires is indistinguishable from one
	 * that works, which is why the constant is asserted against the class this
	 * test imports rather than trusted.
	 *
	 * @return void
	 */
	public function testItIsBoundToTheEventIntegriqDispatches(): void {
		$this->assertSame(
			DigitalPostDeliveredEvent::class,
			ltrim(DigitalPostDeliveredListener::EVENT, '\\')
		);
	}//end testItIsBoundToTheEventIntegriqDispatches()

	/**
	 * A delivery is recorded with its message id and status.
	 *
	 * @return void
	 */
	public function testADeliveryIsRecordedOnTheCase(): void {
		$this->berichtenbox->expects($this->once())
			->method('recordDeliveryStatus')
			->with('dp-4711', 'delivered', '', false)
			->willReturn(true);

		$this->listener()->handle(new DigitalPostDeliveredEvent('dp-4711', 'delivered'));
	}//end testADeliveryIsRecordedOnTheCase()

	/**
	 * A failure is written as failed, with the provider's reason, not dropped.
	 *
	 * @return void
	 */
	public function testAFailureIsWrittenAsFailedWithItsReason(): void {
		$this->berichtenbox->expects($this->once())
			->method('recordDeliveryStatus')
			->with('dp-4711', 'failed', 'The recipient has no Berichtenbox.', false)
			->willReturn(true);

		$this->listener()->handle(
			new DigitalPostDeliveredEvent(
				'dp-4711',
				'failed',
				'handler',
				'sent',
				false,
				'The recipient has no Berichtenbox.'
			)
		);
	}//end testAFailureIsWrittenAsFailedWithItsReason()

	/**
	 * A simulated send is passed through as simulated.
	 *
	 * The log binding of integriq's factory sends nothing and says so on the
	 * event. Dropping that flag would file a simulated delivery beside a real
	 * one with nothing to tell them apart.
	 *
	 * @return void
	 */
	public function testASimulatedSendIsPassedThroughAsSimulated(): void {
		$this->berichtenbox->expects($this->once())
			->method('recordDeliveryStatus')
			->with('dp-4711', 'delivered', '', true)
			->willReturn(true);

		$this->listener()->handle(
			new DigitalPostDeliveredEvent('dp-4711', 'delivered', 'handler', 'sent', true)
		);
	}//end testASimulatedSendIsPassedThroughAsSimulated()

	/**
	 * An event of another shape is ignored rather than guessed at.
	 *
	 * @return void
	 */
	public function testAnEventOfAnotherShapeIsIgnored(): void {
		$this->berichtenbox->expects($this->never())->method('recordDeliveryStatus');

		$this->listener()->handle(new Event());
	}//end testAnEventOfAnotherShapeIsIgnored()

	/**
	 * A service that throws does not take integriq's dispatch down with it.
	 *
	 * An exception escaping a listener stops the dispatch mid-batch, so one app
	 * failing to file a receipt would cost every other app on the instance
	 * theirs.
	 *
	 * @return void
	 */
	public function testAServiceThatThrowsDoesNotEscapeTheListener(): void {
		$this->berichtenbox->method('recordDeliveryStatus')
			->willThrowException(new \RuntimeException('OpenRegister is down'));

		$this->listener()->handle(new DigitalPostDeliveredEvent('dp-4711', 'delivered'));

		$this->assertTrue(true, 'the listener returned rather than throwing');
	}//end testAServiceThatThrowsDoesNotEscapeTheListener()
}//end class
