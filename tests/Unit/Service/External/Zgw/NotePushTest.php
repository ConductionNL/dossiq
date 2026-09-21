<?php

/**
 * Unit tests for the guard on a case note leaving the municipality.
 *
 * Three things are decided here and each of them is a way a note that did NOT
 * go out could read as one that did: an internal note stays, a dormant adapter
 * writes no marker at all, and anything the adapter does not answer `PUSHED`
 * to is a failure carrying its reason.
 *
 * The doubles use `onlyMethods`, never `addMethods`.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\External\Zgw
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/zgw-api-mapping/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\External\Zgw;

use OCA\Dossiq\Service\External\Zgw\NoteEnvelope;
use OCA\Dossiq\Service\External\Zgw\NotePush;
use OCA\Dossiq\Service\External\Zgw\ZgwExternalAdapterInterface;
use OCA\Dossiq\Service\External\Zgw\ZgwPushResult;
use OCA\Dossiq\Service\Timeline\CaseTimeline;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests what travels, what stays, and what the case says about it.
 *
 * @covers \OCA\Dossiq\Service\External\Zgw\NotePush
 * @uses \OCA\Dossiq\Service\External\Zgw\NoteEnvelope
 * @uses \OCA\Dossiq\Service\External\Zgw\ZgwExternalAdapterInterface
 * @uses \OCA\Dossiq\Service\External\Zgw\ZgwPushResult
 * @uses \OCA\Dossiq\Service\Timeline\CaseTimeline
 */
class NotePushTest extends TestCase {
	/**
	 * @var ZgwExternalAdapterInterface|MockObject
	 */
	private $adapter;

	/**
	 * @var CaseTimeline|MockObject
	 */
	private $timeline;

	/**
	 * The timeline entries written, for assertions.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $recorded = [];

	/**
	 * The reserved informatieobjecttype this instance has.
	 *
	 * @var string
	 */
	private string $reservedType = 'https://catalogi.example/iot/werknotitie';

	/**
	 * Whether the timeline write lands.
	 *
	 * `CaseTimeline::record()` answers `''` and only logs whenever OpenRegister
	 * is absent, the register or the case schema is unconfigured, the case
	 * cannot be read, or the write throws. Set this false to be that instance.
	 *
	 * @var bool
	 */
	private bool $timelineWrites = true;

	/**
	 * A note that may leave.
	 *
	 * @var array<string, mixed>
	 */
	private const EXTERNAL_NOTE = [
		'id' => 42,
		'message' => 'Gebeld met de aanvrager',
		'actorId' => 'jdoe',
		'actorDisplayName' => 'J. Doe',
		'createdAt' => '2026-09-18T10:15:00+02:00',
		'visibility' => 'public',
	];

	/**
	 * Set up the collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->adapter = $this->createMock(ZgwExternalAdapterInterface::class);
		$this->timeline = $this->getMockBuilder(CaseTimeline::class)
			->disableOriginalConstructor()
			->onlyMethods(['record'])
			->getMock();

		$this->timeline->method('record')->willReturnCallback(
			function (
				string $caseId,
				string $kind,
				string $message,
				array $fields = [],
				string $visibility = 'internal',
				array $relatedCaseIds = [],
			): string {
				if ($this->timelineWrites === false) {
					// What the real one answers when it could not write: an
					// empty string and a logged warning, nothing louder.
					return '';
				}

				$this->recorded[] = [
					'message' => $message,
					'fields' => $fields,
					'visibility' => $visibility,
				];

				return 'entry-' . count($this->recorded);
			}
		);
	}//end setUp()

	/**
	 * Build the subject under test.
	 *
	 * @return NotePush
	 */
	private function push(): NotePush {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => (
				$key === NotePush::TYPE_CONFIG_KEY ? $this->reservedType : '002564440'
			)
		);

		return new NotePush(
			$this->adapter,
			new NoteEnvelope(),
			$this->timeline,
			$config,
			$this->createMock(LoggerInterface::class)
		);
	}//end push()

	/**
	 * A dormant adapter writes NO outcome at all.
	 *
	 * A marker that reads as a success on an adapter that contacted nobody is
	 * the failure this rule exists for, and a marker on every note of every
	 * unbound instance is a marker nobody reads.
	 *
	 * @return void
	 */
	public function testADormantAdapterWritesNoMarker(): void {
		$this->adapter->method('isDormant')->willReturn(true);
		$this->adapter->expects($this->never())->method('submitDocument');

		$outcome = $this->push()->push('case-1', self::EXTERNAL_NOTE);

		$this->assertSame(NotePush::OUTCOME_NO_REGISTER, $outcome['outcome']);
		$this->assertSame([], $this->recorded);
	}//end testADormantAdapterWritesNoMarker()

	/**
	 * An internal note is not pushed; the same note made external is.
	 *
	 * @return void
	 */
	public function testAnInternalNoteStaysHereAndAnExternalOneGoes(): void {
		$this->adapter->method('isDormant')->willReturn(false);
		$this->adapter->method('submitDocument')->willReturn(
			new ZgwPushResult('PUSHED', 'https://drc.example/eio/9', 'corr-1', false)
		);

		$internal = array_merge(self::EXTERNAL_NOTE, ['visibility' => 'internal']);
		$this->assertSame(NotePush::OUTCOME_NOT_SENT, $this->push()->push('case-1', $internal)['outcome']);

		$this->assertSame(NotePush::OUTCOME_SENT, $this->push()->push('case-1', self::EXTERNAL_NOTE)['outcome']);
	}//end testAnInternalNoteStaysHereAndAnExternalOneGoes()

	/**
	 * A note with no visibility at all is treated as internal.
	 *
	 * The default stays internal, so nothing leaves the municipality by
	 * accident. Failing towards keeping a note here is the only safe
	 * direction: the other way sends a colleague's working note to another
	 * organisation.
	 *
	 * @return void
	 */
	public function testANoteWithNoVisibilityNeverLeaves(): void {
		$this->adapter->method('isDormant')->willReturn(false);
		$this->adapter->expects($this->never())->method('submitDocument');

		$note = self::EXTERNAL_NOTE;
		unset($note['visibility']);

		$this->assertSame(NotePush::OUTCOME_NOT_SENT, $this->push()->push('case-1', $note)['outcome']);
	}//end testANoteWithNoVisibilityNeverLeaves()

	/**
	 * A refusing adapter leaves the note marked failed, with the reason.
	 *
	 * @return void
	 */
	public function testARefusingAdapterLeavesTheNoteMarkedFailedWithTheReason(): void {
		$this->adapter->method('isDormant')->willReturn(false);
		$this->adapter->method('submitDocument')->willReturn(
			new ZgwPushResult(
				'REJECTED',
				'',
				'corr-1',
				false,
				['rejectionReason' => 'informatieobjecttype is not in this catalogue']
			)
		);

		$outcome = $this->push()->push('case-1', self::EXTERNAL_NOTE);

		$this->assertSame(NotePush::OUTCOME_FAILED, $outcome['outcome']);
		$this->assertSame('informatieobjecttype is not in this catalogue', $outcome['reason']);
		// The CASE says so: an outcome only the caller sees is an outcome
		// nobody finds again.
		$this->assertCount(1, $this->recorded);
		$this->assertSame(NotePush::OUTCOME_FAILED, $this->recorded[0]['fields']['status']);
		$this->assertStringContainsString('not in this catalogue', $this->recorded[0]['message']);
		$this->assertSame(CaseTimeline::INTERNAL, $this->recorded[0]['visibility']);
	}//end testARefusingAdapterLeavesTheNoteMarkedFailedWithTheReason()

	/**
	 * A deferred push on a live adapter is a failure, not a send.
	 *
	 * The dormant case is answered before the adapter is ever called, so a
	 * `PUSH_DEFERRED` here means a live adapter did not deliver. Treating it
	 * as a success would put a sent marker on a note that stayed home, which
	 * is the single thing this change exists to prevent.
	 *
	 * @return void
	 */
	public function testADeferredPushOnALiveAdapterIsAFailure(): void {
		$this->adapter->method('isDormant')->willReturn(false);
		$this->adapter->method('submitDocument')->willReturn(
			new ZgwPushResult('PUSH_DEFERRED', '', 'corr-1', false, ['reason' => 'queue is full'])
		);

		$outcome = $this->push()->push('case-1', self::EXTERNAL_NOTE);

		$this->assertSame(NotePush::OUTCOME_FAILED, $outcome['outcome']);
		$this->assertSame('queue is full', $outcome['reason']);
	}//end testADeferredPushOnALiveAdapterIsAFailure()

	/**
	 * With no reserved type nothing is pushed, and the note is marked failed
	 * naming the key to set.
	 *
	 * @return void
	 */
	public function testWithNoReservedTypeNothingIsPushed(): void {
		$this->reservedType = '';
		$this->adapter->method('isDormant')->willReturn(false);
		$this->adapter->expects($this->never())->method('submitDocument');

		$outcome = $this->push()->push('case-1', self::EXTERNAL_NOTE);

		$this->assertSame(NotePush::OUTCOME_FAILED, $outcome['outcome']);
		$this->assertStringContainsString('note_informatieobjecttype', $outcome['reason']);
	}//end testWithNoReservedTypeNothingIsPushed()

	/**
	 * A send records the outcome on the case and answers the receiver's url.
	 *
	 * @return void
	 */
	public function testASendRecordsItselfAndAnswersTheReceiverUrl(): void {
		$this->adapter->method('isDormant')->willReturn(false);
		$this->adapter->method('submitDocument')->willReturn(
			new ZgwPushResult('PUSHED', 'https://drc.example/eio/9', 'corr-1', false)
		);

		$outcome = $this->push()->push('case-1', self::EXTERNAL_NOTE);

		$this->assertSame(NotePush::OUTCOME_SENT, $outcome['outcome']);
		$this->assertSame('https://drc.example/eio/9', $outcome['receiverUrl']);
		$this->assertSame(NotePush::OUTCOME_SENT, $this->recorded[0]['fields']['status']);
		$this->assertSame('42', $this->recorded[0]['fields']['noteId']);
		$this->assertSame(NotePush::RECORD_WRITTEN, $outcome['caseRecord']);
	}//end testASendRecordsItselfAndAnswersTheReceiverUrl()

	/**
	 * A failure the case could not be told about says so, rather than
	 * answering as though the case now carries it.
	 *
	 * `CaseTimeline::record()` catches its own failures, logs a warning and
	 * answers an empty string, so a push that ignored the answer would tell
	 * the caller "failed" and write nothing. Tomorrow the case history reads
	 * as though nobody ever pushed this note, which is the same evidence loss
	 * as a note that did not travel looking like one that did.
	 *
	 * @return void
	 */
	public function testAFailureTheCaseCouldNotRecordSaysSo(): void {
		$this->timelineWrites = false;
		$this->adapter->method('isDormant')->willReturn(false);
		$this->adapter->method('submitDocument')->willReturn(
			new ZgwPushResult('REJECTED', '', 'corr-1', false, ['rejectionReason' => 'the catalogue refused the type'])
		);

		$outcome = $this->push()->push('case-1', self::EXTERNAL_NOTE);

		$this->assertSame(NotePush::OUTCOME_FAILED, $outcome['outcome']);
		$this->assertSame(NotePush::RECORD_LOST, $outcome['caseRecord']);
		// The adapter's own reason still travels, and the lost record is told
		// beside it rather than instead of it.
		$this->assertStringContainsString('the catalogue refused the type', $outcome['reason']);
		$this->assertStringContainsString('The case could not record this', $outcome['reason']);
		$this->assertSame([], $this->recorded);
	}//end testAFailureTheCaseCouldNotRecordSaysSo()

	/**
	 * A note that DID reach the register, on a case that could not be told,
	 * answers sent and says the history is missing it.
	 *
	 * The send is real and is not downgraded: the note is at the neighbouring
	 * register whatever this instance managed to write down. What the caller
	 * must not be left with is a sent answer plus a silent hole in the case.
	 *
	 * @return void
	 */
	public function testASendTheCaseCouldNotRecordStillSaysSo(): void {
		$this->timelineWrites = false;
		$this->adapter->method('isDormant')->willReturn(false);
		$this->adapter->method('submitDocument')->willReturn(
			new ZgwPushResult('PUSHED', 'https://drc.example/eio/9', 'corr-1', false)
		);

		$outcome = $this->push()->push('case-1', self::EXTERNAL_NOTE);

		$this->assertSame(NotePush::OUTCOME_SENT, $outcome['outcome']);
		$this->assertSame('https://drc.example/eio/9', $outcome['receiverUrl']);
		$this->assertSame(NotePush::RECORD_LOST, $outcome['caseRecord']);
		$this->assertStringContainsString('The case could not record this', $outcome['reason']);
	}//end testASendTheCaseCouldNotRecordStillSaysSo()

	/**
	 * An internal note that somebody tried to send leaves a line on the case.
	 *
	 * Somebody asked for this note to go out and it stayed. The answer in the
	 * response is gone the moment the tab closes; the case is where the next
	 * handler looks for what became of a note.
	 *
	 * @return void
	 */
	public function testAnInternalNoteSomebodyTriedToSendIsRecorded(): void {
		$this->adapter->method('isDormant')->willReturn(false);
		$this->adapter->expects($this->never())->method('submitDocument');

		$internal = array_merge(self::EXTERNAL_NOTE, ['visibility' => 'internal']);
		$outcome = $this->push()->push('case-1', $internal);

		$this->assertSame(NotePush::OUTCOME_NOT_SENT, $outcome['outcome']);
		$this->assertSame(NotePush::RECORD_WRITTEN, $outcome['caseRecord']);
		$this->assertCount(1, $this->recorded);
		$this->assertSame(NotePush::OUTCOME_NOT_SENT, $this->recorded[0]['fields']['status']);
		$this->assertSame(CaseTimeline::INTERNAL, $this->recorded[0]['visibility']);
	}//end testAnInternalNoteSomebodyTriedToSendIsRecorded()

	/**
	 * A dormant adapter answers that nothing was due, not that a write was
	 * lost.
	 *
	 * The two are opposite states and one shared flag would hide the second
	 * behind the first on every unbound instance, which is every instance
	 * until a connector is configured.
	 *
	 * @return void
	 */
	public function testADormantAdapterAnswersThatNothingWasDue(): void {
		$this->adapter->method('isDormant')->willReturn(true);

		$outcome = $this->push()->push('case-1', self::EXTERNAL_NOTE);

		$this->assertSame(NotePush::RECORD_NONE, $outcome['caseRecord']);
	}//end testADormantAdapterAnswersThatNothingWasDue()
}//end class
