<?php

/**
 * Unit tests for the guard on a case note leaving the municipality.
 *
 * Three things are decided here and each of them is a way a note that did NOT
 * go out could read as one that did: an internal note stays, an instance with
 * nothing bound writes no marker at all, and anything integriq does not take
 * for at least one subscription is a failure carrying its reason.
 *
 * The doubles use `onlyMethods`, never `addMethods`.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\Notes
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

namespace OCA\Dossiq\Tests\Unit\Service\Notes;

use OCA\Dossiq\Service\Notes\NoteEnvelope;
use OCA\Dossiq\Service\Notes\NotePush;
use OCA\Dossiq\Service\Timeline\CaseTimeline;
use OCA\Integriq\Event\DeliveryRequestedEvent;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use RuntimeException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests what travels, what stays, and what the case says about it.
 *
 * @covers \OCA\Dossiq\Service\Notes\NotePush
 * @uses \OCA\Dossiq\Service\Notes\NoteEnvelope
 * @uses \OCA\Dossiq\Service\Timeline\CaseTimeline
 */
class NotePushTest extends TestCase {
	/**
	 * How integriq answers the delivery request: `handled`, `matched`, `refusal`, `throws`.
	 *
	 * @var array<string, mixed>
	 */
	private array $integriq = ['handled' => true, 'matched' => 1, 'refusal' => null, 'throws' => false];

	/**
	 * The delivery requests dispatched, for assertions.
	 *
	 * @var array<int, DeliveryRequestedEvent>
	 */
	private array $dispatched = [];

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

		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(
			function (object $event): void {
				if ($this->integriq['throws'] === true) {
					throw new RuntimeException('dispatcher broke');
				}

				$this->dispatched[] = $event;
				if (($event instanceof DeliveryRequestedEvent) === false || $this->integriq['handled'] !== true) {
					return;
				}

				$event->setMatchedSubscriptions(matchedSubscriptions: (int)$this->integriq['matched']);
				if (is_array($this->integriq['refusal']) === true) {
					$event->setRefusal(
						reason: (string)$this->integriq['refusal']['reason'],
						code: (string)$this->integriq['refusal']['code']
					);
				}

				$event->setHandled(handled: true);
			}
		);

		return new NotePush(
			$dispatcher,
			new NoteEnvelope(),
			$this->timeline,
			$config,
			$this->createMock(LoggerInterface::class)
		);
	}//end push()

	/**
	 * Nothing bound to receive case notes writes NO outcome at all.
	 *
	 * integriq took the request and no subscription matched: a marker that
	 * reads as a success while nobody was contacted is the failure this rule
	 * exists for, and a marker on every note of every unbound instance is a
	 * marker nobody reads.
	 *
	 * @return void
	 */
	public function testNothingBoundWritesNoMarker(): void {
		$this->integriq['matched'] = 0;

		$outcome = $this->push()->push('case-1', self::EXTERNAL_NOTE);

		$this->assertSame(NotePush::OUTCOME_NO_REGISTER, $outcome['outcome']);
		$this->assertSame(NotePush::RECORD_NONE, $outcome['caseRecord']);
		$this->assertSame([], $this->recorded);
	}//end testNothingBoundWritesNoMarker()

	/**
	 * The request names the note as a case-note on the zgw-documenten channel,
	 * carrying the document envelope.
	 *
	 * @return void
	 */
	public function testTheRequestCarriesTheNoteAsADocument(): void {
		$this->push()->push('case-1', self::EXTERNAL_NOTE);

		$this->assertCount(1, $this->dispatched);
		$event = $this->dispatched[0];
		$this->assertSame('dossiq', $event->getSourceApp());
		$this->assertSame(NotePush::DELIVERY_KIND, $event->getDeliveryKind());
		$this->assertSame(NotePush::DELIVERY_CHANNEL, $event->getChannel());
		$this->assertSame('case-1', $event->getSubjectId());
		$this->assertSame('dossiq-note-42', $event->getExternalReference());
		$payload = $event->getPayload();
		$this->assertSame($this->reservedType, $payload['informatieobjecttype']);
		$this->assertSame('case-1', $payload['zaak']);
		$this->assertSame(base64_encode('Gebeld met de aanvrager'), $payload['inhoud']);
	}//end testTheRequestCarriesTheNoteAsADocument()

	/**
	 * An internal note is not handed over; the same note made external is.
	 *
	 * @return void
	 */
	public function testAnInternalNoteStaysHereAndAnExternalOneGoes(): void {
		$internal = array_merge(self::EXTERNAL_NOTE, ['visibility' => 'internal']);
		$this->assertSame(NotePush::OUTCOME_NOT_SENT, $this->push()->push('case-1', $internal)['outcome']);
		$this->assertSame([], $this->dispatched);

		$this->assertSame(NotePush::OUTCOME_SENT, $this->push()->push('case-1', self::EXTERNAL_NOTE)['outcome']);
		$this->assertCount(1, $this->dispatched);
	}//end testAnInternalNoteStaysHereAndAnExternalOneGoes()

	/**
	 * A note with no visibility at all is treated as internal.
	 *
	 * The default stays internal, so nothing leaves the municipality by
	 * accident.
	 *
	 * @return void
	 */
	public function testANoteWithNoVisibilityNeverLeaves(): void {
		$note = self::EXTERNAL_NOTE;
		unset($note['visibility']);

		$this->assertSame(NotePush::OUTCOME_NOT_SENT, $this->push()->push('case-1', $note)['outcome']);
		$this->assertSame([], $this->dispatched);
	}//end testANoteWithNoVisibilityNeverLeaves()

	/**
	 * A refusal from integriq leaves the note marked failed, with the reason.
	 *
	 * @return void
	 */
	public function testARefusalLeavesTheNoteMarkedFailedWithTheReason(): void {
		$this->integriq['matched'] = 0;
		$this->integriq['refusal'] = ['code' => 'opted-out', 'reason' => 'the receiver opted out of case notes'];

		$outcome = $this->push()->push('case-1', self::EXTERNAL_NOTE);

		$this->assertSame(NotePush::OUTCOME_FAILED, $outcome['outcome']);
		$this->assertSame('the receiver opted out of case notes', $outcome['reason']);
		// The CASE says so: an outcome only the caller sees is an outcome
		// nobody finds again.
		$this->assertCount(1, $this->recorded);
		$this->assertSame(NotePush::OUTCOME_FAILED, $this->recorded[0]['fields']['status']);
		$this->assertStringContainsString('opted out', $this->recorded[0]['message']);
		$this->assertSame(CaseTimeline::INTERNAL, $this->recorded[0]['visibility']);
	}//end testARefusalLeavesTheNoteMarkedFailedWithTheReason()

	/**
	 * A request integriq did not take is a failure, not a send and not unbound.
	 *
	 * integriq is installed and its listener did not handle the request (it
	 * logged why). The note stayed home, so a sent marker or a silent "no
	 * register" would both be wrong.
	 *
	 * @return void
	 */
	public function testARequestIntegriqDidNotTakeIsAFailure(): void {
		$this->integriq['handled'] = false;

		$outcome = $this->push()->push('case-1', self::EXTERNAL_NOTE);

		$this->assertSame(NotePush::OUTCOME_FAILED, $outcome['outcome']);
		$this->assertStringContainsString('integriq could not take the note', $outcome['reason']);
		$this->assertSame(NotePush::OUTCOME_FAILED, $this->recorded[0]['fields']['status']);
	}//end testARequestIntegriqDidNotTakeIsAFailure()

	/**
	 * A dispatcher that throws leaves the note marked failed.
	 *
	 * @return void
	 */
	public function testADispatchThatThrowsIsAFailure(): void {
		$this->integriq['throws'] = true;

		$outcome = $this->push()->push('case-1', self::EXTERNAL_NOTE);

		$this->assertSame(NotePush::OUTCOME_FAILED, $outcome['outcome']);
		$this->assertSame(NotePush::RECORD_WRITTEN, $outcome['caseRecord']);
	}//end testADispatchThatThrowsIsAFailure()

	/**
	 * With no reserved type nothing is handed over, and the note is marked
	 * failed naming the key to set.
	 *
	 * @return void
	 */
	public function testWithNoReservedTypeNothingIsPushed(): void {
		$this->reservedType = '';

		$outcome = $this->push()->push('case-1', self::EXTERNAL_NOTE);

		$this->assertSame(NotePush::OUTCOME_FAILED, $outcome['outcome']);
		$this->assertStringContainsString('note_informatieobjecttype', $outcome['reason']);
		$this->assertSame([], $this->dispatched);
	}//end testWithNoReservedTypeNothingIsPushed()

	/**
	 * A hand-over records the outcome on the case.
	 *
	 * @return void
	 */
	public function testASendRecordsItself(): void {
		$outcome = $this->push()->push('case-1', self::EXTERNAL_NOTE);

		$this->assertSame(NotePush::OUTCOME_SENT, $outcome['outcome']);
		$this->assertSame('', $outcome['receiverUrl']);
		$this->assertSame(NotePush::OUTCOME_SENT, $this->recorded[0]['fields']['status']);
		$this->assertSame('42', $this->recorded[0]['fields']['noteId']);
		$this->assertSame(NotePush::RECORD_WRITTEN, $outcome['caseRecord']);
	}//end testASendRecordsItself()

	/**
	 * A failure the case could not be told about says so, rather than
	 * answering as though the case now carries it.
	 *
	 * @return void
	 */
	public function testAFailureTheCaseCouldNotRecordSaysSo(): void {
		$this->timelineWrites = false;
		$this->integriq['refusal'] = ['code' => 'refused', 'reason' => 'the catalogue refused the type'];

		$outcome = $this->push()->push('case-1', self::EXTERNAL_NOTE);

		$this->assertSame(NotePush::OUTCOME_FAILED, $outcome['outcome']);
		$this->assertSame(NotePush::RECORD_LOST, $outcome['caseRecord']);
		// The refusal's own reason still travels, and the lost record is told
		// beside it rather than instead of it.
		$this->assertStringContainsString('the catalogue refused the type', $outcome['reason']);
		$this->assertStringContainsString('The case could not record this', $outcome['reason']);
		$this->assertSame([], $this->recorded);
	}//end testAFailureTheCaseCouldNotRecordSaysSo()

	/**
	 * A note handed over, on a case that could not be told, answers sent and
	 * says the history is missing it.
	 *
	 * @return void
	 */
	public function testASendTheCaseCouldNotRecordStillSaysSo(): void {
		$this->timelineWrites = false;

		$outcome = $this->push()->push('case-1', self::EXTERNAL_NOTE);

		$this->assertSame(NotePush::OUTCOME_SENT, $outcome['outcome']);
		$this->assertSame(NotePush::RECORD_LOST, $outcome['caseRecord']);
		$this->assertStringContainsString('The case could not record this', $outcome['reason']);
	}//end testASendTheCaseCouldNotRecordStillSaysSo()

	/**
	 * An internal note that somebody tried to send leaves a line on the case.
	 *
	 * @return void
	 */
	public function testAnInternalNoteSomebodyTriedToSendIsRecorded(): void {
		$internal = array_merge(self::EXTERNAL_NOTE, ['visibility' => 'internal']);
		$outcome = $this->push()->push('case-1', $internal);

		$this->assertSame(NotePush::OUTCOME_NOT_SENT, $outcome['outcome']);
		$this->assertSame(NotePush::RECORD_WRITTEN, $outcome['caseRecord']);
		$this->assertCount(1, $this->recorded);
		$this->assertSame(NotePush::OUTCOME_NOT_SENT, $this->recorded[0]['fields']['status']);
		$this->assertSame(CaseTimeline::INTERNAL, $this->recorded[0]['visibility']);
	}//end testAnInternalNoteSomebodyTriedToSendIsRecorded()
}//end class
