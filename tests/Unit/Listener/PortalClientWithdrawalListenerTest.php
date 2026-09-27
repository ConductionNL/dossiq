<?php

/**
 * Unit tests for the portal withdrawal listener.
 *
 * portaliq raises `portal.withdraw.client` when a resident ends their own
 * request from the portal. It is its own event so a rule can tell a citizen's
 * withdrawal from a handler setting the same status, and nothing in dossiq
 * listened (dossiq#3142). These arms prove the listener is bound to the name
 * portaliq dispatches and hands the case, the status, the reason and the
 * moment to the recorder that tells the handler.
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
 * @spec openspec/specs/portal-contribution/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Listener;

use OCA\Dossiq\Listener\PortalClientWithdrawalListener;
use OCA\Dossiq\Portal\ApplicantPortalActs;
use OCA\Portaliq\Event\PortalClientWithdrawalEvent;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests that a resident's withdrawal reaches the handler.
 *
 * @covers \OCA\Dossiq\Listener\PortalClientWithdrawalListener
 */
class PortalClientWithdrawalListenerTest extends TestCase {
	/**
	 * @var ApplicantPortalActs|MockObject
	 */
	private $acts;

	/**
	 * Set up the collaborator.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->acts = $this->getMockBuilder(ApplicantPortalActs::class)
			->disableOriginalConstructor()
			->onlyMethods(['recordWrite', 'recordWithdrawal'])
			->getMock();
	}//end setUp()

	/**
	 * Build the subject under test.
	 *
	 * @return PortalClientWithdrawalListener
	 */
	private function listener(): PortalClientWithdrawalListener {
		return new PortalClientWithdrawalListener(
			$this->acts,
			$this->createMock(LoggerInterface::class)
		);
	}//end listener()

	/**
	 * A withdrawal event as portaliq builds it.
	 *
	 * @return PortalClientWithdrawalEvent
	 */
	private function event(): PortalClientWithdrawalEvent {
		return new PortalClientWithdrawalEvent(
			register: 'case-management',
			schema: 'case',
			caseId: 'case-1',
			status: 'status-withdrawn',
			reason: 'Ik heb de vergunning niet meer nodig.',
			identity: ['subjectRef' => 'bsn:hashed'],
			mandate: ['action' => 'withdraw'],
			occurredAt: '2026-09-27T11:00:00+02:00',
		);
	}//end event()

	/**
	 * The listener is bound to the class portaliq actually dispatches.
	 *
	 * @return void
	 */
	public function testTheListenerIsBoundToTheEventPortaliqDispatches(): void {
		$this->assertSame('OCA\Portaliq\Event\PortalClientWithdrawalEvent', PortalClientWithdrawalListener::EVENT);
		$this->assertTrue(class_exists(PortalClientWithdrawalListener::EVENT));
		$this->assertSame('portal.withdraw.client', PortalClientWithdrawalEvent::NAME);
	}//end testTheListenerIsBoundToTheEventPortaliqDispatches()

	/**
	 * The withdrawal reaches the recorder with the case, status, reason and moment.
	 *
	 * @return void
	 */
	public function testTheWithdrawalReachesTheRecorder(): void {
		$this->acts->expects($this->once())
			->method('recordWithdrawal')
			->with('case-1', 'status-withdrawn', 'Ik heb de vergunning niet meer nodig.', '2026-09-27T11:00:00+02:00')
			->willReturn(true);
		$this->acts->expects($this->never())->method('recordWrite');

		$this->listener()->handle($this->event());
	}//end testTheWithdrawalReachesTheRecorder()

	/**
	 * An event of another shape is left alone.
	 *
	 * @return void
	 */
	public function testAnotherEventIsLeftAlone(): void {
		$this->acts->expects($this->never())->method('recordWithdrawal');

		$this->listener()->handle(new Event());
	}//end testAnotherEventIsLeftAlone()

	/**
	 * A recorder that throws does not stop portaliq's dispatch.
	 *
	 * @return void
	 */
	public function testARecorderThatThrowsDoesNotEscape(): void {
		$this->acts->method('recordWithdrawal')->willThrowException(new RuntimeException('down'));

		$this->listener()->handle($this->event());

		$this->addToAssertionCount(1);
	}//end testARecorderThatThrowsDoesNotEscape()
}//end class
