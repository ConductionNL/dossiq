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
	 * The mocked logger.
	 *
	 * @var LoggerInterface|MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * Set up the stand-ins.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->writer = new FakeTimelineWriter();
		$this->objects = new FakeTimelineObjectService();
		$this->logger = $this->createMock(LoggerInterface::class);
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
		$container->method('has')->willReturn($writerResolves);
		$container->method('get')->willReturnCallback(
			function (string $name) use ($writerResolves): object {
				if ($writerResolves === false) {
					throw new RuntimeException('not found: ' . $name);
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
