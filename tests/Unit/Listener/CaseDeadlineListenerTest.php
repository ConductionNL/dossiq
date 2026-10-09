<?php

/**
 * CaseDeadlineListener unit tests.
 *
 * REQ-WTR-001: every case's deadline is its start plus the effective case
 * type's term, rolled by the Algemene termijnenwet on the calendar, with the
 * unrolled date kept as `deadlineBeforeRoll`. REQ-CT-20: a child type that
 * sets no term inherits its parent's. The resolver is the REAL one over a
 * fixed store, the timer is the REAL one on the statutory fallback calendar
 * (which marks Christmas, Boxing Day and King's Day), and the events are the
 * real OpenRegister event classes.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/case-types/spec.md
 * @spec openspec/specs/woo-case-type/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Listener;

use DateInterval;
use DateTimeImmutable;
use OCA\Dossiq\AppInfo\Registrar\CaseTypeListenerRegistrar;
use OCA\Dossiq\Listener\CaseDeadlineListener;
use OCA\Dossiq\Listener\CaseTypeParentCycleListener;
use OCA\Dossiq\Service\CaseTypeResolver;
use OCA\Dossiq\Service\CaseTypeSlugResolver;
use OCA\Dossiq\Service\CaseTypeStore;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Termijn\CaseDeadlineCalculator;
use OCA\Dossiq\Service\Termijn\CaseDeadlineMirror;
use OCA\Dossiq\Service\Termijn\TermDefinitions;
use OCA\Dossiq\Service\TermijnTimerService;
use OCA\Dossiq\Service\WorkingDayCalculator;
use OCA\Dossiq\Tests\Support\MakesCaseDateNormaliser;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Dossiq\Listener\CaseDeadlineListener
 * @covers \OCA\Dossiq\AppInfo\Registrar\CaseTypeListenerRegistrar
 * @covers \OCA\Dossiq\Service\Termijn\CaseDeadlineMirror
 * @uses   \OCA\Dossiq\Service\CaseTypeResolver
 * @uses   \OCA\Dossiq\Service\CaseTypeSlugResolver
 * @uses   \OCA\Dossiq\Service\CaseTypeStore
 * @uses   \OCA\Dossiq\Service\Termijn\TermDefinitions
 * @uses   \OCA\Dossiq\Service\Termijn\TermEndRoll
 * @uses   \OCA\Dossiq\Service\TermijnTimerService
 * @uses   \OCA\Dossiq\Service\WorkingDayCalculator
 * @uses   \OCA\Dossiq\Service\CaseDateNormaliser
 */
class CaseDeadlineListenerTest extends TestCase {
	use MakesCaseDateNormaliser;

	/**
	 * Bezwaar (twelve weeks), a child that is silent, a child that sets six
	 * weeks, a grandchild that is silent, and a type with no parent and no term.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private const STORED = [
		'bezwaar' => ['id' => 'bezwaar', 'title' => 'Bezwaar', 'processingDeadline' => 'P12W'],
		'stil' => ['id' => 'stil', 'title' => 'Bezwaar (stil)', 'parentCaseType' => 'bezwaar'],
		'verkort' => [
			'id' => 'verkort',
			'title' => 'Bezwaar (verkort)',
			'parentCaseType' => 'bezwaar',
			'processingDeadline' => 'P6W',
		],
		'kleinkind' => ['id' => 'kleinkind', 'title' => 'Bezwaar (kleinkind)', 'parentCaseType' => 'stil'],
		'los' => ['id' => 'los', 'title' => 'Los'],
		'krom' => ['id' => 'krom', 'title' => 'Krom', 'parentCaseType' => 'kromme-ouder'],
		'kromme-ouder' => ['id' => 'kromme-ouder', 'title' => 'Kromme ouder', 'processingDeadline' => 'twelve weeks'],
		'woo-verzoek' => ['id' => 'woo-verzoek', 'title' => 'Woo-verzoek', 'processingDeadline' => 'P28D'],
		'kalendertermijn' => ['id' => 'kalendertermijn', 'title' => 'Kalendertermijn', 'processingDeadline' => 'P28D'],
	];

	/**
	 * The term definitions, keyed by case type slug. Only the calendar term
	 * switches the Awt roll off.
	 *
	 * @var array<string, array<int, array<string, mixed>>>
	 */
	private const DEFINITIONS = [
		'woo-verzoek' => [['id' => 'def-woo', 'caseType' => 'woo-verzoek', 'standardDurationDays' => 28]],
		'kalendertermijn' => [
			['id' => 'def-kal', 'caseType' => 'kalendertermijn', 'standardDurationDays' => 28, 'rollToWorkingDay' => false],
		],
	];

	/**
	 * The mirror the term write path fills, shared with the listener.
	 *
	 * @var CaseDeadlineMirror
	 */
	private CaseDeadlineMirror $mirror;

	/**
	 * The logger, so a test can expect a warning.
	 *
	 * @var LoggerInterface
	 */
	private LoggerInterface $logger;

	/**
	 * Build the listener over a fixed store of case types.
	 *
	 * @return CaseDeadlineListener The listener.
	 */
	private function listener(): CaseDeadlineListener {
		$objectService = new class(self::STORED) {
			/**
			 * @param array<string, array<string, mixed>> $stored Case types, keyed by id.
			 */
			public function __construct(private array $stored) {
			}

			/**
			 * Read one object by id.
			 *
			 * @param string $id       The id.
			 * @param string $register The register.
			 * @param string $schema   The schema.
			 *
			 * @return array<string, mixed> The row, or an empty array.
			 */
			public function find(string $id, string $register, string $schema): array {
				return ($this->stored[$id] ?? []);
			}

			/**
			 * Search by register and schema slug; only term definitions are asked.
			 *
			 * @param string               $register The register.
			 * @param string               $schema   The schema.
			 * @param array<string, mixed> $filters  The filters.
			 *
			 * @return array<int, array<string, mixed>> The rows.
			 */
			public function searchObjectsBySlug(string $register, string $schema, array $filters): array {
				return (CaseDeadlineListenerTest::definitionsFor((string)($filters['caseType'] ?? '')));
			}
		};

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($objectService);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => match ($key) {
				'register' => 'dossiq',
				'case_type_schema' => 'case-type-schema-id',
				'case_schema' => 'case-schema-id',
				'termijn_definitie_schema' => 'deadline-definition',
				default => $default,
			}
		);
		$settings->method('getOpenRegisterClass')->willReturn(null);

		return new CaseDeadlineListener(
			$settings,
			new CaseTypeResolver(new CaseTypeStore($settings)),
			$this->logger,
			new CaseDeadlineCalculator(
				timerService: new TermijnTimerService(
					settingsService: $settings,
					logger: $this->logger,
					dates: $this->caseDates(),
					fallbackCalendar: new WorkingDayCalculator(),
				),
				definitions: new TermDefinitions(settingsService: $settings, logger: $this->logger),
				slugs: new CaseTypeSlugResolver(settingsService: $settings, logger: $this->logger),
			),
			$this->mirror,
		);
	}//end listener()

	/**
	 * The term definitions a case type has.
	 *
	 * @param string $caseType The case type slug.
	 *
	 * @return array<int, array<string, mixed>> The definitions.
	 */
	public static function definitionsFor(string $caseType): array {
		return (self::DEFINITIONS[$caseType] ?? []);
	}//end definitionsFor()

	/**
	 * Set up the logger.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->mirror = new CaseDeadlineMirror();
	}//end setUp()

	/**
	 * A case about to be created.
	 *
	 * @param array<string, mixed> $payload  Its fields.
	 * @param string               $schemaId Its schema.
	 *
	 * @return ObjectCreatingEvent The event, after the listener ran.
	 */
	private function create(array $payload, string $schemaId = 'case-schema-id'): ObjectCreatingEvent {
		$entity = new ObjectEntity();
		$entity->setObject($payload);
		$entity->setSchema($schemaId);
		$entity->setUuid('case-1');

		$event = new ObjectCreatingEvent($entity);
		$this->listener()->handle($event);

		return $event;
	}//end create()

	/**
	 * REQ-WTR-001: a Woo term that ends on Christmas Day rolls past Boxing Day
	 * and the Sunday to Monday 2026-12-28.
	 *
	 * @return void
	 */
	public function testAWooTermEndingOnChristmasRollsToMonday(): void {
		$event = $this->create(['caseType' => 'woo-verzoek', 'startDate' => '2026-11-27']);

		$this->assertSame(
			['deadline' => '2026-12-28', 'deadlineBeforeRoll' => '2026-12-25', 'statutoryTerm' => 'P28D'],
			$event->getModifiedData()
		);
	}//end testAWooTermEndingOnChristmasRollsToMonday()

	/**
	 * REQ-WTR-001: a term ending on King's Day rolls to the next day.
	 *
	 * @return void
	 */
	public function testKingsDayRollsToTheNextDay(): void {
		$event = $this->create(['caseType' => 'woo-verzoek', 'startDate' => '2027-03-30']);

		$this->assertSame('2027-04-28', $event->getModifiedData()['deadline'] ?? null);
		$this->assertSame('2027-04-27', $event->getModifiedData()['deadlineBeforeRoll'] ?? null);
	}//end testKingsDayRollsToTheNextDay()

	/**
	 * A term definition that switches the roll off keeps the unrolled date.
	 *
	 * @return void
	 */
	public function testADefinitionThatSwitchesTheRollOffKeepsChristmas(): void {
		$event = $this->create(['caseType' => 'kalendertermijn', 'startDate' => '2026-11-27']);

		$this->assertSame('2026-12-25', $event->getModifiedData()['deadline'] ?? null);
	}//end testADefinitionThatSwitchesTheRollOffKeepsChristmas()

	/**
	 * REQ-CT-20: a child that sets no deadline gets its twelve-week parent's.
	 *
	 * @return void
	 */
	public function testACaseOfASilentChildIsDueTheParentsTermAfterItStarts(): void {
		$event = $this->create(['caseType' => 'stil', 'startDate' => '2026-09-11', 'deadline' => null]);

		$this->assertSame(
			['deadline' => '2026-12-04', 'deadlineBeforeRoll' => '2026-12-04', 'statutoryTerm' => 'P12W'],
			$event->getModifiedData()
		);
	}//end testACaseOfASilentChildIsDueTheParentsTermAfterItStarts()

	/**
	 * Two levels up: the grandchild and its parent are both silent.
	 *
	 * @return void
	 */
	public function testTheTermIsFoundTwoLevelsUp(): void {
		$event = $this->create(['caseType' => 'kleinkind', 'startDate' => '2026-01-01']);

		$this->assertSame('2026-03-26', $event->getModifiedData()['deadline'] ?? null);
	}//end testTheTermIsFoundTwoLevelsUp()

	/**
	 * A type that sets its own term gets it, rolled, now that no calculation writes it.
	 *
	 * @return void
	 */
	public function testATypeThatSetsItsOwnTermGetsItsOwnDeadline(): void {
		$event = $this->create(['caseType' => 'verkort', 'startDate' => '2026-09-11']);
		$this->assertSame('2026-10-23', $event->getModifiedData()['deadline'] ?? null);

		$event = $this->create(['caseType' => 'bezwaar', 'startDate' => '2026-09-11']);
		$this->assertSame('2026-12-04', $event->getModifiedData()['deadline'] ?? null);
	}//end testATypeThatSetsItsOwnTermGetsItsOwnDeadline()

	/**
	 * A type with no term anywhere in its chain writes nothing.
	 *
	 * @return void
	 */
	public function testATypeWithoutATermIsLeftAlone(): void {
		$this->assertSame([], $this->create(['caseType' => 'los', 'startDate' => '2026-09-11'])->getModifiedData());
	}//end testATypeWithoutATermIsLeftAlone()

	/**
	 * An empty start date counts from today, as the startDate calculation does.
	 *
	 * @return void
	 */
	public function testAnEmptyStartDateCountsFromToday(): void {
		$event = $this->create(['caseType' => 'stil']);

		$expected = (new DateTimeImmutable('today'))->add(new DateInterval('P12W'))->format('Y-m-d');
		$this->assertSame($expected, $event->getModifiedData()['deadlineBeforeRoll'] ?? null);
	}//end testAnEmptyStartDateCountsFromToday()

	/**
	 * An update without a stored case is recomputed, and rolled: 2026-04-26 is
	 * a Sunday and the Monday after it is King's Day.
	 *
	 * @return void
	 */
	public function testAnUpdateIsRecomputedFromItsStartDate(): void {
		$entity = $this->entity(['caseType' => ['id' => 'stil'], 'startDate' => '2026-02-01T00:00:00+00:00']);

		$event = new ObjectUpdatingEvent($entity, null);
		$event->setModifiedData(['note' => 'set by another listener']);
		$this->listener()->handle($event);

		$this->assertSame(
			[
				'note' => 'set by another listener',
				'deadline' => '2026-04-28',
				'deadlineBeforeRoll' => '2026-04-26',
				'statutoryTerm' => 'P12W',
			],
			$event->getModifiedData(),
			'another listener\'s modified data must survive'
		);
	}//end testAnUpdateIsRecomputedFromItsStartDate()

	/**
	 * An extended deadline survives an ordinary save of the case.
	 *
	 * @return void
	 */
	public function testAnUpdateKeepsAnExtendedDeadline(): void {
		$old = $this->entity(
			['caseType' => 'woo-verzoek', 'startDate' => '2026-11-27', 'deadline' => '2027-01-11', 'deadlineBeforeRoll' => '2027-01-11']
		);
		$new = $this->entity(['caseType' => 'woo-verzoek', 'startDate' => '2026-11-27', 'title' => 'renamed']);

		$event = new ObjectUpdatingEvent($new, $old);
		$this->listener()->handle($event);

		$this->assertSame(['deadline' => '2027-01-11', 'deadlineBeforeRoll' => '2027-01-11'], $event->getModifiedData());
	}//end testAnUpdateKeepsAnExtendedDeadline()

	/**
	 * A moved start date recomputes the deadline.
	 *
	 * @return void
	 */
	public function testAMovedStartDateRecomputes(): void {
		$old = $this->entity(['caseType' => 'woo-verzoek', 'startDate' => '2026-11-20', 'deadline' => '2026-12-18']);
		$new = $this->entity(['caseType' => 'woo-verzoek', 'startDate' => '2026-11-27']);

		$event = new ObjectUpdatingEvent($new, $old);
		$this->listener()->handle($event);

		$this->assertSame('2026-12-28', $event->getModifiedData()['deadline'] ?? null);
	}//end testAMovedStartDateRecomputes()

	/**
	 * REQ-WTR-001: the date the term write path recorded wins, once.
	 *
	 * @return void
	 */
	public function testTheMirroredTermEndWinsOnTheNextSave(): void {
		$old = $this->entity(['caseType' => 'woo-verzoek', 'startDate' => '2026-11-27', 'deadline' => '2026-12-28']);
		$new = $this->entity(['caseType' => 'woo-verzoek', 'startDate' => '2026-11-27', 'deadline' => '2026-12-28']);
		$this->mirror->expect(caseId: 'case-1', deadline: '2027-01-11', deadlineBeforeRoll: '2027-01-11');

		$event = new ObjectUpdatingEvent($new, $old);
		$this->listener()->handle($event);
		$this->assertSame(['deadline' => '2027-01-11', 'deadlineBeforeRoll' => '2027-01-11'], $event->getModifiedData());

		$this->assertNull($this->mirror->take(caseId: 'case-1'), 'the mirrored date is taken once');
	}//end testTheMirroredTermEndWinsOnTheNextSave()

	/**
	 * A term that is not a duration writes nothing and says so.
	 *
	 * @return void
	 */
	public function testATermThatIsNotADurationWritesNothingAndWarns(): void {
		$this->logger->expects($this->once())->method('warning');

		$this->assertSame([], $this->create(['caseType' => 'krom', 'startDate' => '2026-09-11'])->getModifiedData());
	}//end testATermThatIsNotADurationWritesNothingAndWarns()

	/**
	 * Another schema, or a case with no type, is left alone.
	 *
	 * @return void
	 */
	public function testAnotherSchemaOrACaseWithoutATypeIsLeftAlone(): void {
		$this->assertSame([], $this->create(['caseType' => 'stil'], schemaId: 'other-schema')->getModifiedData());
		$this->assertSame([], $this->create(['startDate' => '2026-09-11'])->getModifiedData());

		$this->listener()->handle(new Event());
	}//end testAnotherSchemaOrACaseWithoutATypeIsLeftAlone()

	/**
	 * A case entity.
	 *
	 * @param array<string, mixed> $payload Its fields.
	 *
	 * @return ObjectEntity The entity.
	 */
	private function entity(array $payload): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setObject($payload);
		$entity->setSchema('case-schema-id');
		$entity->setUuid('case-1');

		return $entity;
	}//end entity()

	/**
	 * The deadline listener runs AFTER OpenRegister's calculation listener.
	 *
	 * The calculation registers at the default priority 0 and fills in
	 * `startDate`; a listener at 0 or above could read the date before it is
	 * there. The cycle guard has no such dependency and keeps the default.
	 *
	 * @return void
	 */
	public function testTheDeadlineListenerIsRegisteredBelowTheCalculation(): void {
		$registered = [];
		$context = $this->createMock(IRegistrationContext::class);
		$context->method('registerEventListener')->willReturnCallback(
			static function (string $event, string $listener, int $priority = 0) use (&$registered): void {
				$registered[] = [$event, $listener, $priority];
			}
		);

		(new CaseTypeListenerRegistrar())->register($context);

		foreach ([ObjectCreatingEvent::class, ObjectUpdatingEvent::class] as $event) {
			$this->assertContains([$event, CaseTypeParentCycleListener::class, 0], $registered);
			$this->assertContains([$event, CaseDeadlineListener::class, -100], $registered);
		}

		$this->assertLessThan(0, CaseTypeListenerRegistrar::INHERITED_DEADLINE_PRIORITY);
	}//end testTheDeadlineListenerIsRegisteredBelowTheCalculation()
}//end class
