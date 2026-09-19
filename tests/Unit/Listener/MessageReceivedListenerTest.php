<?php

/**
 * Unit tests for dossiq answering integriq's offer of a received message.
 *
 * Before this listener existed dossiq bound one integriq event and nothing
 * listened for messages, so every offer went unanswered and landed in
 * integriq's `unassigned`. These arms are the three answers, the guard against
 * filing one message twice, and the one place the slot is deliberately left
 * empty.
 *
 * The doubles use `onlyMethods`, never `addMethods`.
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
 * @spec openspec/changes/inbound-messages-consume-integriq/specs/case-email-integration/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Listener;

use OCA\Dossiq\Listener\MessageReceivedListener;
use OCA\Dossiq\Service\Email\CaseEmailRepository;
use OCA\Dossiq\Service\Email\IntakeLog;
use OCA\Dossiq\Service\Email\UnmatchedMailIntake;
use OCA\Integriq\Event\MessageReceivedEvent;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests the three answers and the duplicate guard.
 *
 * @covers \OCA\Dossiq\Listener\MessageReceivedListener
 * @uses \OCA\Dossiq\Service\Email\CaseEmailRepository
 * @uses \OCA\Dossiq\Service\Email\IntakeLog
 * @uses \OCA\Dossiq\Service\Email\UnmatchedMailIntake
 */
class MessageReceivedListenerTest extends TestCase {
	/**
	 * @var CaseEmailRepository|MockObject
	 */
	private $cases;

	/**
	 * @var UnmatchedMailIntake|MockObject
	 */
	private $unmatched;

	/**
	 * @var IntakeLog|MockObject
	 */
	private $log;

	/**
	 * The log entries written, for assertions.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $recorded = [];

	/**
	 * One message as integriq's `message` schema holds it.
	 *
	 * @var array<string, mixed>
	 */
	private const MESSAGE = [
		'from' => 'a.burger@example.nl',
		'to' => 'zaken@gemeente.nl',
		'subject' => 'Vraag over 2026-114',
		'body' => 'Wanneer hoor ik iets?',
	];

	/**
	 * Set up the collaborators with nothing seen before.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->cases = $this->getMockBuilder(CaseEmailRepository::class)
			->disableOriginalConstructor()
			->onlyMethods(['findCaseIdByIdentifier', 'recordReceivedEmail'])
			->getMock();

		$this->unmatched = $this->getMockBuilder(UnmatchedMailIntake::class)
			->disableOriginalConstructor()
			->onlyMethods(['caseFor'])
			->getMock();

		$this->log = $this->getMockBuilder(IntakeLog::class)
			->disableOriginalConstructor()
			->onlyMethods(['findChannelEntry', 'recordChannelMessage'])
			->getMock();

		$this->log->method('findChannelEntry')->willReturn(null);
		$this->log->method('recordChannelMessage')->willReturnCallback(
			function (
				string $channel,
				string $channelMessageId,
				string $sender,
				string $subject,
				string $outcome,
				string $reason,
				string $caseId = '',
			): string {
				$this->recorded[] = [
					'channel' => $channel,
					'channelMessageId' => $channelMessageId,
					'outcome' => $outcome,
					'reason' => $reason,
					'case' => $caseId,
				];

				return 'entry-' . count($this->recorded);
			}
		);
	}//end setUp()

	/**
	 * Build the subject under test.
	 *
	 * @return MessageReceivedListener
	 */
	private function listener(): MessageReceivedListener {
		return new MessageReceivedListener(
			$this->cases,
			$this->unmatched,
			$this->log,
			$this->createMock(LoggerInterface::class)
		);
	}//end listener()

	/**
	 * The listener is registered for the name integriq actually dispatches.
	 *
	 * A listener bound to an event nobody fires is indistinguishable from one
	 * that works.
	 *
	 * @return void
	 */
	public function testItIsBoundToTheEventIntegriqDispatches(): void {
		$this->assertSame(
			MessageReceivedEvent::class,
			ltrim(MessageReceivedListener::EVENT, '\\')
		);
	}//end testItIsBoundToTheEventIntegriqDispatches()

	/**
	 * A reference naming a case is linked to it, and the message is filed.
	 *
	 * @return void
	 */
	public function testAReferenceNamingACaseAnswersLinked(): void {
		$this->cases->method('findCaseIdByIdentifier')->with('2026-114')->willReturn('case-114');
		$this->cases->expects($this->once())
			->method('recordReceivedEmail')
			->with('case-114', 'a.burger@example.nl', 'zaken@gemeente.nl', 'Vraag over 2026-114');

		$event = new MessageReceivedEvent(self::MESSAGE, '2026-114', 'msg-1', 'mailbox-1');
		$this->listener()->handle($event);

		$this->assertSame('linked', $event->getOutcome());
		$this->assertSame('case-114', $event->getObjectRef());
		$this->assertTrue($event->isClaimed());
		$this->assertSame(IntakeLog::OUTCOME_CASE, $this->recorded[0]['outcome']);
	}//end testAReferenceNamingACaseAnswersLinked()

	/**
	 * An unknown reference with a fallback case type answers created.
	 *
	 * @return void
	 */
	public function testAFallbackCaseTypeAnswersCreated(): void {
		$this->cases->method('findCaseIdByIdentifier')->willReturn(null);
		$this->unmatched->method('caseFor')->willReturn('case-new');

		$event = new MessageReceivedEvent(self::MESSAGE, '2026-999', 'msg-2', 'mailbox-1');
		$this->listener()->handle($event);

		$this->assertSame('created', $event->getOutcome());
		$this->assertSame('case-new', $event->getObjectRef());
	}//end testAFallbackCaseTypeAnswersCreated()

	/**
	 * The same message with no fallback answers declined, with the reason
	 * written where a handler can read it.
	 *
	 * @return void
	 */
	public function testNoFallbackAnswersDeclinedAndRecordsTheReason(): void {
		$this->cases->method('findCaseIdByIdentifier')->willReturn(null);
		$this->unmatched->method('caseFor')->willReturn(null);
		$this->cases->expects($this->never())->method('recordReceivedEmail');

		$event = new MessageReceivedEvent(self::MESSAGE, '2026-999', 'msg-3', 'mailbox-1');
		$this->listener()->handle($event);

		// ANSWERED, not silent. Integriq treats a decline like silence on
		// arrival, and its own docblock says so; what differs is that this one
		// is a decision somebody can read afterwards.
		$this->assertSame('declined', $event->getOutcome());
		$this->assertFalse($event->isClaimed());

		// The reason is in DOSSIQ'S log, because integriq's setOutcome() takes
		// an outcome and an object reference and has nowhere to put one.
		$this->assertSame(IntakeLog::OUTCOME_INBOX, $this->recorded[0]['outcome']);
		$this->assertStringContainsString('2026-999', $this->recorded[0]['reason']);
		$this->assertStringContainsString('fallback case type', $this->recorded[0]['reason']);
	}//end testNoFallbackAnswersDeclinedAndRecordsTheReason()

	/**
	 * A message with no reference at all gets the other sentence.
	 *
	 * One sentence covering both would tell each reader half of what they have
	 * to do: a bad reference needs somebody to look at the reference, no
	 * reference at all needs a fallback case type.
	 *
	 * @return void
	 */
	public function testAMessageWithNoReferenceGetsItsOwnSentence(): void {
		$this->unmatched->method('caseFor')->willReturn(null);
		$this->cases->expects($this->never())->method('findCaseIdByIdentifier');

		$event = new MessageReceivedEvent(self::MESSAGE, null, 'msg-4', 'mailbox-1');
		$this->listener()->handle($event);

		$this->assertSame('declined', $event->getOutcome());
		$this->assertStringContainsString('names no case', $this->recorded[0]['reason']);
	}//end testAMessageWithNoReferenceGetsItsOwnSentence()

	/**
	 * The same message id offered twice is filed once.
	 *
	 * @return void
	 */
	public function testTheSameMessageOfferedTwiceIsFiledOnce(): void {
		$log = $this->getMockBuilder(IntakeLog::class)
			->disableOriginalConstructor()
			->onlyMethods(['findChannelEntry', 'recordChannelMessage'])
			->getMock();
		$log->method('findChannelEntry')
			->with(MessageReceivedListener::CHANNEL, 'msg-1')
			->willReturn(['case' => 'case-114']);
		$log->expects($this->never())->method('recordChannelMessage');
		$this->log = $log;

		$this->cases->expects($this->never())->method('recordReceivedEmail');

		$event = new MessageReceivedEvent(self::MESSAGE, '2026-114', 'msg-1', 'mailbox-1');
		$this->listener()->handle($event);

		// STILL ANSWERED, and answered with the case the FIRST delivery filed
		// it on. Declining here would send a message integriq already has a
		// home for back to `unassigned`.
		$this->assertSame('linked', $event->getOutcome());
		$this->assertSame('case-114', $event->getObjectRef());
	}//end testTheSameMessageOfferedTwiceIsFiledOnce()

	/**
	 * A message seen before that became no case is declined, not linked.
	 *
	 * `setOutcome('linked', '')` throws in integriq's own contract, so
	 * answering linked with an empty reference would be an exception rather
	 * than a wrong answer; this arm proves the branch is taken deliberately.
	 *
	 * @return void
	 */
	public function testAMessageSeenBeforeThatBecameNoCaseIsDeclined(): void {
		$log = $this->getMockBuilder(IntakeLog::class)
			->disableOriginalConstructor()
			->onlyMethods(['findChannelEntry', 'recordChannelMessage'])
			->getMock();
		$log->method('findChannelEntry')->willReturn(['case' => '']);
		$this->log = $log;

		$event = new MessageReceivedEvent(self::MESSAGE, null, 'msg-5', 'mailbox-1');
		$this->listener()->handle($event);

		$this->assertSame('declined', $event->getOutcome());
	}//end testAMessageSeenBeforeThatBecameNoCaseIsDeclined()

	/**
	 * An event of another shape is ignored rather than guessed at.
	 *
	 * @return void
	 */
	public function testAnEventOfAnotherShapeIsIgnored(): void {
		$this->cases->expects($this->never())->method('recordReceivedEmail');

		$this->listener()->handle(new Event());

		$this->assertSame([], $this->recorded);
	}//end testAnEventOfAnotherShapeIsIgnored()

	/**
	 * An internal failure leaves the slot EMPTY rather than declining.
	 *
	 * This is the one place the listener does not answer, and it is
	 * deliberate: after a crash no decision was made, and writing `declined`
	 * would be this app claiming a judgement it never reached.
	 *
	 * @return void
	 */
	public function testAnInternalFailureLeavesTheSlotEmpty(): void {
		$this->cases->method('findCaseIdByIdentifier')
			->willThrowException(new \RuntimeException('OpenRegister is down'));

		$event = new MessageReceivedEvent(self::MESSAGE, '2026-114', 'msg-6', 'mailbox-1');
		$this->listener()->handle($event);

		$this->assertNull($event->getOutcome());
	}//end testAnInternalFailureLeavesTheSlotEmpty()
}//end class
