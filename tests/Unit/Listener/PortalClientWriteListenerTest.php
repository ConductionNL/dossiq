<?php

/**
 * Unit tests for the portal write listener.
 *
 * portaliq raises one `portal.write.client` fact per act a resident takes on
 * their own case: an amended answer, an added document, an answered task.
 * Nothing in dossiq listened, so the handler only found out by opening the
 * case (dossiq#3142). These arms prove the listener is bound to the name
 * portaliq dispatches and hands every getter the real class carries to the
 * recorder that tells the handler.
 *
 * The event is portaliq's own contract, loaded from a verbatim stub when the
 * real class is absent (tests/bootstrap.php). The double uses `onlyMethods`,
 * never `addMethods`.
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

use OCA\Dossiq\Listener\PortalClientWriteListener;
use OCA\Dossiq\Portal\ApplicantPortalActs;
use OCA\Portaliq\Event\PortalClientWriteEvent;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests that a resident's write on their case reaches the handler.
 *
 * @covers \OCA\Dossiq\Listener\PortalClientWriteListener
 */
class PortalClientWriteListenerTest extends TestCase {
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
	 * @return PortalClientWriteListener
	 */
	private function listener(): PortalClientWriteListener {
		return new PortalClientWriteListener(
			$this->acts,
			$this->createMock(LoggerInterface::class)
		);
	}//end listener()

	/**
	 * A write event as portaliq builds it.
	 *
	 * @param string $act The act.
	 *
	 * @return PortalClientWriteEvent
	 */
	private function event(string $act): PortalClientWriteEvent {
		return new PortalClientWriteEvent(
			register: 'case-management',
			schema: 'case',
			caseId: 'case-1',
			act: $act,
			fields: ['phone', 'email'],
			identity: ['subjectRef' => 'bsn:hashed', 'audience' => 'citizen'],
			mandate: ['action' => 'amend'],
			occurredAt: '2026-09-27T10:00:00+02:00',
		);
	}//end event()

	/**
	 * The listener is bound to the class portaliq actually dispatches.
	 *
	 * @return void
	 */
	public function testTheListenerIsBoundToTheEventPortaliqDispatches(): void {
		$this->assertSame('OCA\Portaliq\Event\PortalClientWriteEvent', PortalClientWriteListener::EVENT);
		$this->assertTrue(class_exists(PortalClientWriteListener::EVENT));
		$this->assertSame('portal.write.client', PortalClientWriteEvent::NAME);
	}//end testTheListenerIsBoundToTheEventPortaliqDispatches()

	/**
	 * Every act reaches the recorder with the case, the act, the fields and the moment.
	 *
	 * @param string $act The act portaliq names.
	 *
	 * @return void
	 *
	 * @dataProvider acts
	 */
	public function testEveryActReachesTheRecorder(string $act): void {
		$this->acts->expects($this->once())
			->method('recordWrite')
			->with('case-1', $act, ['phone', 'email'], '2026-09-27T10:00:00+02:00')
			->willReturn(true);
		$this->acts->expects($this->never())->method('recordWithdrawal');

		$this->listener()->handle($this->event(act: $act));
	}//end testEveryActReachesTheRecorder()

	/**
	 * The three acts portaliq raises.
	 *
	 * @return array<string, array<int, string>>
	 */
	public static function acts(): array {
		return [
			'amendment' => [PortalClientWriteEvent::ACT_AMENDMENT],
			'document' => [PortalClientWriteEvent::ACT_DOCUMENT],
			'task answer' => [PortalClientWriteEvent::ACT_TASK_ANSWER],
		];
	}//end acts()

	/**
	 * An event of another shape is left alone.
	 *
	 * @return void
	 */
	public function testAnotherEventIsLeftAlone(): void {
		$this->acts->expects($this->never())->method('recordWrite');

		$this->listener()->handle(new Event());
	}//end testAnotherEventIsLeftAlone()

	/**
	 * A recorder that throws does not stop portaliq's dispatch.
	 *
	 * @return void
	 */
	public function testARecorderThatThrowsDoesNotEscape(): void {
		$this->acts->method('recordWrite')->willThrowException(new RuntimeException('down'));

		$this->listener()->handle($this->event(act: PortalClientWriteEvent::ACT_DOCUMENT));

		$this->addToAssertionCount(1);
	}//end testARecorderThatThrowsDoesNotEscape()
}//end class
