<?php

/**
 * CaseTimeline Unit Tests.
 *
 * The one seam that writes a timeline entry, and the declarations it writes
 * against. What these tests are here to catch:
 *
 *  - a write that stops happening silently because OpenRegister is older,
 *    unconfigured or throwing, which is exactly the failure a soft-failing
 *    writer is built to have and therefore the one nobody notices;
 *  - a DRIFT between the contactmoment kind's declared vocabulary and the
 *    vocabulary `ContactMomentService` actually validates and stores. That
 *    drift makes OpenRegister refuse every entry this app writes, the
 *    refusal is caught and logged rather than shown, and the timeline simply
 *    stops filling. No other test in this repository can see it, because the
 *    two lists live in two files that never mention each other.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Timeline
 *
 * @author    Conduction Development Team <info@conduction.nl>
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
 * @spec openspec/changes/one-timeline-on-the-case/specs/case-history-surface/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Timeline;

use OCA\Dossiq\Service\ContactMomentService;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Timeline\CaseTimeline;
use OCA\Dossiq\Service\Timeline\TimelineKinds;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use RuntimeException;

/**
 * A stand-in for the entry OpenRegister answers with.
 */
class FakeTimelineEntry {

	/**
	 * Constructor.
	 *
	 * @param string $uuid The entry id.
	 */
	public function __construct(private readonly string $uuid) {
	}//end __construct()

	/**
	 * The entry's stable id.
	 *
	 * @return string The uuid.
	 */
	public function getUuid(): string {
		return $this->uuid;
	}//end getUuid()
}//end class

/**
 * A stand-in for OpenRegister's TimelineWriteService.
 */
class FakeTimelineWriter {

	/**
	 * Every payload handed to write().
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $written = [];

	/**
	 * The object lists handed to writeToMany().
	 *
	 * @var array<int, array<int, mixed>>
	 */
	public array $writtenToMany = [];

	/**
	 * Set when the next write should blow up.
	 *
	 * @var boolean
	 */
	public bool $throws = false;

	/**
	 * Write one entry.
	 *
	 * @param mixed                $object The object it hangs on.
	 * @param array<string, mixed> $data   The payload.
	 *
	 * @return FakeTimelineEntry The entry.
	 */
	public function write(mixed $object, array $data): FakeTimelineEntry {
		if ($this->throws === true) {
			throw new RuntimeException('the kind is undeclared');
		}

		$this->written[] = $data;

		return new FakeTimelineEntry('entry-' . count($this->written));
	}//end write()

	/**
	 * Write the same entry on several objects.
	 *
	 * @param array<int, mixed>    $objects The objects.
	 * @param array<string, mixed> $data    The payload.
	 *
	 * @return array<int, FakeTimelineEntry> The entries.
	 */
	public function writeToMany(array $objects, array $data): array {
		$this->writtenToMany[] = $objects;
		$this->written[] = $data;

		$entries = [];
		foreach ($objects as $index => $object) {
			$entries[] = new FakeTimelineEntry('entry-many-' . $index);
		}

		return $entries;
	}//end writeToMany()
}//end class

/**
 * A stand-in for the entity OpenRegister's reader answers with.
 */
class FakeTimelineRow {

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $row The fields.
	 */
	public function __construct(private readonly array $row) {
	}//end __construct()

	/**
	 * The row as an array.
	 *
	 * @return array<string, mixed> The fields.
	 */
	public function jsonSerialize(): array {
		return $this->row;
	}//end jsonSerialize()
}//end class

/**
 * A stand-in for OpenRegister's TimelineEntryService.
 *
 * It answers the entries it was seeded with, and RECORDS the visibility it was
 * asked for, because the reader's contract is that it asks for the public ones
 * and no caller can ask it for anything else.
 */
class FakeTimelineReader {

	/**
	 * The entries this stand-in hands back.
	 *
	 * @var array<int, mixed>
	 */
	public array $entries = [];

	/**
	 * Every visibility it was asked for.
	 *
	 * @var array<int, string|null>
	 */
	public array $asked = [];

	/**
	 * Set when the next read should blow up.
	 *
	 * @var boolean
	 */
	public bool $throws = false;

	/**
	 * One object's timeline.
	 *
	 * @param mixed       $object     The object.
	 * @param string|null $visibility The filter.
	 * @param integer     $limit      Page size.
	 * @param integer     $offset     Page offset.
	 *
	 * @return array<int, mixed> The entries.
	 */
	public function listForObject(
		mixed $object,
		?string $visibility = null,
		int $limit = 50,
		int $offset = 0,
	): array {
		if ($this->throws === true) {
			throw new RuntimeException('the reader is a release behind');
		}

		$this->asked[] = $visibility;

		return $this->entries;
	}//end listForObject()
}//end class

/**
 * A stand-in for OpenRegister's ObjectService, with the named parameters
 * the real one takes, because the caller uses named arguments.
 */
class FakeTimelineObjectService {

	/**
	 * Ids this store refuses to answer for.
	 *
	 * @var array<int, string>
	 */
	public array $unreadable = [];

	/**
	 * Find one object.
	 *
	 * @param string $id       The object id.
	 * @param mixed  $register The register.
	 * @param mixed  $schema   The schema.
	 *
	 * @return object|null The object, or null.
	 */
	public function find(string $id, mixed $register = null, mixed $schema = null): ?object {
		if (in_array($id, $this->unreadable, true) === true) {
			return null;
		}

		return (object)['uuid' => $id];
	}//end find()
}//end class

/**
 * The one seam, and the declarations behind it.
 *
 * @covers \OCA\Dossiq\Service\Timeline\CaseTimeline
 * @covers \OCA\Dossiq\Service\Timeline\TimelineKinds
 */
class CaseTimelineTest extends TestCase {

	/**
	 * The stand-in writer the container hands out.
	 *
	 * @var FakeTimelineWriter
	 */
	private FakeTimelineWriter $writer;

	/**
	 * The stand-in object store.
	 *
	 * @var FakeTimelineObjectService
	 */
	private FakeTimelineObjectService $objects;

	/**
	 * The stand-in reader the container hands out.
	 *
	 * @var FakeTimelineReader
	 */
	private FakeTimelineReader $reader;

	/**
	 * The mocked logger.
	 *
	 * @var LoggerInterface|MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * Whether the container claims the writer and then fails to build it.
	 *
	 * @var boolean
	 */
	private bool $containerAnswersButCannotBuild = false;

	/**
	 * Set up the stand-ins.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->writer = new FakeTimelineWriter();
		$this->objects = new FakeTimelineObjectService();
		$this->reader = new FakeTimelineReader();
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->containerAnswersButCannotBuild = false;
	}//end setUp()

	/**
	 * Build the seam.
	 *
	 * @param boolean $openRegister   Whether OpenRegister reads as available.
	 * @param boolean $writerResolves Whether the container answers for the writer.
	 * @param string  $caseSchema     The configured case schema, '' for unconfigured.
	 *
	 * @return CaseTimeline The seam under test.
	 */
	private function timeline(
		bool $openRegister = true,
		bool $writerResolves = true,
		string $caseSchema = 'case',
	): CaseTimeline {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('isOpenRegisterAvailable')->willReturn($openRegister);
		$settings->method('getObjectService')->willReturn($this->objects);
		$settings->method('getConfigValue')->willReturnCallback(
			static function (string $key) use ($caseSchema): string {
				return match ($key) {
					'register' => 'dossiq',
					'case_schema' => $caseSchema,
					default => '',
				};
			}
		);

		// BOTH PSR-11 methods are stubbed, from the real interface rather than
		// added to the double: `isAvailable()` asks `has()` and `record()` calls
		// `get()`, and a double that answered only one of them would let a
		// change to which method the seam uses pass unnoticed.
		$container = $this->createMock(ContainerInterface::class);
		$container->method('has')->willReturn($writerResolves || $this->containerAnswersButCannotBuild);
		$container->method('get')->willReturnCallback(
			function (string $name) use ($writerResolves): object {
				if ($writerResolves === false) {
					throw new RuntimeException('not found: ' . $name);
				}

				if ($name === CaseTimeline::READ_SERVICE) {
					return $this->reader;
				}

				return $this->writer;
			}
		);

		return new CaseTimeline(
			settings: $settings,
			container: $container,
			logger: $this->logger,
		);
	}//end timeline()

	/**
	 * The happy path carries the kind, the visibility and the fields through.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/one-timeline-on-the-case/specs/case-history-surface/spec.md
	 */
	public function testRecordWritesTheKindVisibilityAndFields(): void {
		$id = $this->timeline()->record(
			caseId: 'case-1',
			kind: TimelineKinds::MAIL_OUT,
			message: 'Beschikking verzonden',
			fields: ['recipient' => 'a@example.org', 'subject' => 'Beschikking'],
			visibility: CaseTimeline::PUBLIC_ENTRY,
		);

		$this->assertSame('entry-1', $id);
		$this->assertCount(1, $this->writer->written);

		$payload = $this->writer->written[0];
		$this->assertSame(TimelineKinds::MAIL_OUT, $payload['kind']);
		$this->assertSame('public', $payload['visibility']);
		$this->assertSame('Beschikking verzonden', $payload['message']);
		$this->assertSame('a@example.org', $payload['fields']['recipient']);
		$this->assertSame('dossiq', $payload['register']);
		$this->assertSame('case', $payload['schema']);
	}//end testRecordWritesTheKindVisibilityAndFields()

	/**
	 * The default visibility is internal, and it is a real default rather
	 * than a value every caller happens to pass.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/one-timeline-on-the-case/specs/case-history-surface/spec.md
	 */
	public function testAnEntryIsInternalUnlessTheCallerSaysOtherwise(): void {
		$this->timeline()->record(
			caseId: 'case-1',
			kind: TimelineKinds::CONTACTMOMENT,
			message: 'Gebeld',
		);

		$this->assertSame('internal', $this->writer->written[0]['visibility']);
	}//end testAnEntryIsInternalUnlessTheCallerSaysOtherwise()

	/**
	 * Related cases go through writeToMany, with the addressed case first,
	 * so every case carries the entry or none of them does.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/one-timeline-on-the-case/specs/case-history-surface/spec.md
	 */
	public function testRelatedCasesAreWrittenInOneAct(): void {
		$id = $this->timeline()->record(
			caseId: 'case-1',
			kind: TimelineKinds::CONTACTMOMENT,
			message: 'Gebeld over drie zaken',
			relatedCaseIds: ['case-2', 'case-3'],
		);

		$this->assertSame('entry-many-0', $id);
		$this->assertCount(1, $this->writer->writtenToMany);

		$objects = $this->writer->writtenToMany[0];
		$this->assertCount(3, $objects);
		$this->assertSame('case-1', $objects[0]->uuid);
		$this->assertSame('case-2', $objects[1]->uuid);
		$this->assertSame('case-3', $objects[2]->uuid);
	}//end testRelatedCasesAreWrittenInOneAct()

	/**
	 * The addressed case named again in the related list is not written twice.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/one-timeline-on-the-case/specs/case-history-surface/spec.md
	 */
	public function testTheAddressedCaseIsNeverWrittenTwice(): void {
		$this->timeline()->record(
			caseId: 'case-1',
			kind: TimelineKinds::CONTACTMOMENT,
			message: 'Gebeld',
			relatedCaseIds: ['case-1', 'case-2', 'case-2'],
		);

		$objects = $this->writer->writtenToMany[0];
		$this->assertCount(2, $objects);
		$this->assertSame(['case-1', 'case-2'], array_column($objects, 'uuid'));
	}//end testTheAddressedCaseIsNeverWrittenTwice()

	/**
	 * A related case that cannot be read is left out AND named in the log,
	 * rather than dropped in silence.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/one-timeline-on-the-case/specs/case-history-surface/spec.md
	 */
	public function testAnUnreadableRelatedCaseIsLoggedByName(): void {
		$this->objects->unreadable = ['case-3'];

		$named = [];
		$this->logger->method('warning')->willReturnCallback(
			static function (string $message, array $context = []) use (&$named): void {
				if (isset($context['case']) === true) {
					$named[] = $context['case'];
				}
			}
		);

		$this->timeline()->record(
			caseId: 'case-1',
			kind: TimelineKinds::CONTACTMOMENT,
			message: 'Gebeld',
			relatedCaseIds: ['case-2', 'case-3'],
		);

		$this->assertSame(['case-3'], $named);
		$this->assertCount(2, $this->writer->writtenToMany[0]);
	}//end testAnUnreadableRelatedCaseIsLoggedByName()

	/**
	 * An instance whose OpenRegister has no timeline writes nothing, answers
	 * '' and does not blow up the act that called it.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/one-timeline-on-the-case/specs/case-history-surface/spec.md
	 */
	public function testAnOpenRegisterWithoutTheTimelineWritesNothing(): void {
		$id = $this->timeline(writerResolves: false)->record(
			caseId: 'case-1',
			kind: TimelineKinds::CONTACTMOMENT,
			message: 'Gebeld',
		);

		$this->assertSame('', $id);
		$this->assertSame([], $this->writer->written);
	}//end testAnOpenRegisterWithoutTheTimelineWritesNothing()

	/**
	 * OpenRegister disabled altogether is the same answer, and the container
	 * is never asked.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/one-timeline-on-the-case/specs/case-history-surface/spec.md
	 */
	public function testOpenRegisterDisabledIsNotAvailable(): void {
		$this->assertFalse($this->timeline(openRegister: false)->isAvailable());
		$this->assertTrue($this->timeline()->isAvailable());
	}//end testOpenRegisterDisabledIsNotAvailable()

	/**
	 * An unconfigured case schema is logged as a reason, not thrown.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/one-timeline-on-the-case/specs/case-history-surface/spec.md
	 */
	public function testAnUnconfiguredCaseSchemaIsLoggedNotThrown(): void {
		$this->logger->expects($this->once())->method('warning');

		$id = $this->timeline(caseSchema: '')->record(
			caseId: 'case-1',
			kind: TimelineKinds::CONTACTMOMENT,
			message: 'Gebeld',
		);

		$this->assertSame('', $id);
	}//end testAnUnconfiguredCaseSchemaIsLoggedNotThrown()

	/**
	 * A refused write, which is what an undeclared kind or a bad field value
	 * is, never reaches the caller.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/one-timeline-on-the-case/specs/case-history-surface/spec.md
	 */
	public function testARefusedWriteIsSwallowedAndLogged(): void {
		$this->writer->throws = true;
		$this->logger->expects($this->once())->method('warning');

		$id = $this->timeline()->record(
			caseId: 'case-1',
			kind: 'nobody-declared-this',
			message: 'Gebeld',
		);

		$this->assertSame('', $id);
	}//end testARefusedWriteIsSwallowedAndLogged()

	/**
	 * The container claiming the writer and then failing to build it is the
	 * path the refactor opened: `isAvailable()` asks `has()`, which answers
	 * yes, and `get()` throws anyway because OpenRegister could not construct
	 * the service. It must reach the one catch that logs it, not the caller.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/one-timeline-on-the-case/specs/case-history-surface/spec.md
	 */
	public function testAWriterThatCannotBeBuiltIsLoggedNotThrown(): void {
		$this->containerAnswersButCannotBuild = true;
		$this->logger->expects($this->once())->method('warning');

		$id = $this->timeline(writerResolves: false)->record(
			caseId: 'case-1',
			kind: TimelineKinds::CONTACTMOMENT,
			message: 'Gebeld',
		);

		$this->assertSame('', $id);
		$this->assertSame([], $this->writer->written);
	}//end testAWriterThatCannotBeBuiltIsLoggedNotThrown()

	/**
	 * A blank case or a blank kind writes nothing at all.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/one-timeline-on-the-case/specs/case-history-surface/spec.md
	 */
	public function testABlankCaseOrKindWritesNothing(): void {
		$timeline = $this->timeline();

		$this->assertSame('', $timeline->record(caseId: '', kind: TimelineKinds::CONTACTMOMENT, message: 'x'));
		$this->assertSame('', $timeline->record(caseId: 'case-1', kind: '', message: 'x'));
		$this->assertSame([], $this->writer->written);
	}//end testABlankCaseOrKindWritesNothing()

	/**
	 * A case that cannot be read writes nothing and says so.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/one-timeline-on-the-case/specs/case-history-surface/spec.md
	 */
	public function testACaseThatCannotBeReadWritesNothing(): void {
		$this->objects->unreadable = ['case-1'];
		$this->logger->expects($this->once())->method('warning');

		$this->assertSame(
			'',
			$this->timeline()->record(caseId: 'case-1', kind: TimelineKinds::CONTACTMOMENT, message: 'x')
		);
	}//end testACaseThatCannotBeReadWritesNothing()

	/**
	 * THE DRIFT GUARD. The contactmoment kind must declare exactly the
	 * channel vocabulary `ContactMomentService` validates and stores, or
	 * OpenRegister refuses every contact moment this app writes, silently.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/one-timeline-on-the-case/specs/case-history-surface/spec.md
	 */
	public function testTheContactMomentKindDeclaresTheChannelsTheServiceAccepts(): void {
		$reflected = new ReflectionClass(ContactMomentService::class);
		$accepted = $reflected->getConstant('VALID_KANALEN');

		$declared = null;
		foreach (TimelineKinds::DECLARATIONS as $declaration) {
			if ($declaration['slug'] === TimelineKinds::CONTACTMOMENT) {
				$declared = $declaration['properties']['channel']['enum'];
			}
		}

		$this->assertNotNull($declared, 'the contactmoment kind declares a channel');
		sort($accepted);
		sort($declared);
		$this->assertSame(
			$accepted,
			$declared,
			'the contactmoment kind and ContactMomentService::VALID_KANALEN have drifted apart'
		);
	}//end testTheContactMomentKindDeclaresTheChannelsTheServiceAccepts()

	/**
	 * The public reader asks for the public entries, and cannot be asked for
	 * anything else.
	 *
	 * THE ASSERTION ON `asked` IS THE WHOLE TEST. `publicEntries()` takes no
	 * visibility argument, so the only way an internal entry reaches an
	 * applicant is if this call stops naming the filter. A reader handed the
	 * unfiltered feed would return exactly the same shape, pass every other
	 * assertion in this file, and put internal notes on a citizen's screen.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/timeline-entries-default-internal/specs/portal-contribution/spec.md
	 */
	public function testPublicEntriesAsksForThePublicOnes(): void {
		$this->reader->entries = [
			['uuid' => 'e1', 'kind' => 'statuswijziging', 'message' => 'Status: In behandeling'],
		];

		$entries = $this->timeline()->publicEntries(caseId: 'case-1');

		$this->assertSame(['public'], $this->reader->asked);
		$this->assertCount(1, $entries);
		$this->assertSame('e1', $entries[0]['id']);
		$this->assertSame('statuswijziging', $entries[0]['kind']);
		$this->assertSame('Status: In behandeling', $entries[0]['message']);
	}//end testPublicEntriesAsksForThePublicOnes()

	/**
	 * The projection drops the author, and keeps the moment.
	 *
	 * A handler's user id is not part of what happened on the case as far as
	 * the applicant is concerned. The date is: "delivered on the 4th" is the
	 * line, not "delivered".
	 *
	 * @return void
	 *
	 * @spec openspec/changes/timeline-entries-default-internal/specs/portal-contribution/spec.md
	 */
	public function testThePublicProjectionDropsTheAuthorAndKeepsTheMoment(): void {
		$this->reader->entries = [
			[
				'uuid' => 'e1',
				'kind' => TimelineKinds::DECISION_SENT,
				'message' => 'Beschikking verzonden',
				'author' => 'handler-42',
				'fields' => ['channel' => 'berichtenbox'],
				'created' => new \DateTimeImmutable('2026-05-04T09:12:00+02:00'),
			],
		];

		$entry = $this->timeline()->publicEntries(caseId: 'case-1')[0];

		$this->assertArrayNotHasKey('author', $entry);
		$this->assertSame('berichtenbox', $entry['fields']['channel']);
		$this->assertStringStartsWith('2026-05-04T09:12:00', $entry['occurredAt']);
	}//end testThePublicProjectionDropsTheAuthorAndKeepsTheMoment()

	/**
	 * An entity that serialises itself is read the same way as a plain row.
	 *
	 * OpenRegister answers entities, not arrays, and a projection that only
	 * understood arrays would answer a list of empty entries rather than fail.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/timeline-entries-default-internal/specs/portal-contribution/spec.md
	 */
	public function testAnEntityIsProjectedLikeARow(): void {
		$this->reader->entries = [new FakeTimelineRow(['uuid' => 'e9', 'message' => 'Verzonden'])];

		$entry = $this->timeline()->publicEntries(caseId: 'case-1')[0];

		$this->assertSame('e9', $entry['id']);
		$this->assertSame('Verzonden', $entry['message']);
	}//end testAnEntityIsProjectedLikeARow()

	/**
	 * An absence the reader can ESTABLISH answers the empty list.
	 *
	 * Each of these is a fact the method works out for itself: nothing was
	 * asked for, OpenRegister or its reader is not on this instance, the
	 * register is unconfigured, or the case is not there. None of them is a
	 * failure being hidden.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/timeline-entries-default-internal/specs/portal-contribution/spec.md
	 */
	public function testAnEstablishedAbsenceAnswersNothing(): void {
		$this->assertSame([], $this->timeline()->publicEntries(caseId: ''));
		$this->assertSame([], $this->timeline(openRegister: false)->publicEntries(caseId: 'case-1'));
		$this->assertSame([], $this->timeline(writerResolves: false)->publicEntries(caseId: 'case-1'));
		$this->assertSame([], $this->timeline(caseSchema: '')->publicEntries(caseId: 'case-1'));

		$this->objects->unreadable = ['case-1'];
		$this->assertSame([], $this->timeline()->publicEntries(caseId: 'case-1'));
	}//end testAnEstablishedAbsenceAnswersNothing()

	/**
	 * A read that THROWS is logged and travels on, rather than reading as an
	 * empty timeline.
	 *
	 * THIS IS THE ONE ASSERTION THAT SEPARATES THE TWO FACTS. "I could not
	 * read" and "nothing here is public" have the same shape and opposite
	 * meanings, and the wrong one on a citizen's screen says nothing has
	 * happened on their case. The warning is asserted beside the throw,
	 * because a rethrow nobody logged leaves the failure nameless.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/timeline-entries-default-internal/specs/portal-contribution/spec.md
	 */
	public function testAReadThatThrowsIsLoggedAndTravelsOn(): void {
		$this->reader->throws = true;

		$this->logger->expects($this->atLeastOnce())->method('warning');

		$this->expectException(RuntimeException::class);
		$this->timeline()->publicEntries(caseId: 'case-1');
	}//end testAReadThatThrowsIsLoggedAndTravelsOn()

	/**
	 * Every kind a writer in this app names is actually declared, and every
	 * declaration is well formed. A kind constant nothing declares is a 400
	 * on the first write of it.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/one-timeline-on-the-case/specs/case-history-surface/spec.md
	 */
	public function testEveryDeclaredKindIsWellFormed(): void {
		$slugs = array_column(TimelineKinds::DECLARATIONS, 'slug');

		$named = [
			TimelineKinds::CONTACTMOMENT,
			TimelineKinds::MAIL_IN,
			TimelineKinds::MAIL_OUT,
			TimelineKinds::PORTAL_MESSAGE,
			TimelineKinds::ACKNOWLEDGEMENT,
			TimelineKinds::STATUS_CHANGE,
			TimelineKinds::TERM_EVENT,
			TimelineKinds::DECISION_SENT,
			TimelineKinds::DATA_SUBJECT_REQUEST,
		];

		foreach ($named as $slug) {
			$this->assertContains($slug, $slugs, $slug . ' is named by a writer but not declared');
		}

		$this->assertSame(count($named), count($slugs), 'a declaration exists that no constant names');

		foreach (TimelineKinds::DECLARATIONS as $declaration) {
			$this->assertNotSame('', trim((string)$declaration['title']));
			foreach ($declaration['required'] as $required) {
				$this->assertArrayHasKey(
					$required,
					$declaration['properties'],
					$declaration['slug'] . ' requires a property it does not declare'
				);
			}
		}
	}//end testEveryDeclaredKindIsWellFormed()

	/**
	 * Only inbound mail carries a follow-up. `carriesFollowUp` opens one on
	 * EVERY entry of a kind, so a second kind declaring it would open a task
	 * on every logged call and every status move.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/one-timeline-on-the-case/specs/case-history-surface/spec.md
	 */
	public function testOnlyInboundMailCarriesAFollowUp(): void {
		$carrying = [];
		foreach (TimelineKinds::DECLARATIONS as $declaration) {
			if ($declaration['followUp'] === true) {
				$carrying[] = $declaration['slug'];
			}
		}

		$this->assertSame([TimelineKinds::MAIL_IN], $carrying);
	}//end testOnlyInboundMailCarriesAFollowUp()

	/**
	 * The portal message kind does not declare a place to put a BSN. A public
	 * entry is the last surface a citizen identifier belongs on, and a field
	 * the kind does not declare is dropped by OpenRegister rather than stored.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/one-timeline-on-the-case/specs/case-history-surface/spec.md
	 */
	public function testThePortalMessageKindDeclaresNoRecipientIdentifier(): void {
		foreach (TimelineKinds::DECLARATIONS as $declaration) {
			if ($declaration['slug'] !== TimelineKinds::PORTAL_MESSAGE) {
				continue;
			}

			$properties = array_keys($declaration['properties']);
			$this->assertNotContains('bsn', $properties);
			$this->assertNotContains('recipient', $properties);
			$this->assertSame(['subject', 'messageId', 'status'], $properties);
		}
	}//end testThePortalMessageKindDeclaresNoRecipientIdentifier()

	/**
	 * Every seeded standard note has a name and a body, since a block with
	 * an empty body is refused by OpenRegister and would seed nothing.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/one-timeline-on-the-case/specs/case-history-surface/spec.md
	 */
	public function testEverySeededTextBlockHasANameAndABody(): void {
		$this->assertNotSame([], TimelineKinds::TEXT_BLOCKS);

		$slugs = [];
		foreach (TimelineKinds::TEXT_BLOCKS as $block) {
			$this->assertNotSame('', trim($block['slug']));
			$this->assertNotSame('', trim($block['body']));
			$this->assertNotSame('', trim($block['title']));
			$slugs[] = $block['slug'];
		}

		$this->assertSame($slugs, array_unique($slugs), 'two standard notes share a slug');
	}//end testEverySeededTextBlockHasANameAndABody()
}//end class
