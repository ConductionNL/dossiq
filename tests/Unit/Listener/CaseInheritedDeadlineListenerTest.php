<?php

/**
 * CaseInheritedDeadlineListener unit tests.
 *
 * REQ-CT-20: a case of a child type that sets no processing deadline gets its
 * parent's. The declarative calculation reads only the case type the case
 * points at, so without this listener such a case has no deadline at all.
 * The resolver is the REAL one over a fixed store: which term counts as
 * "inherited" is decided by the chain, and a mocked resolver would let the
 * listener ask the wrong type and still pass.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/case-types/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Listener;

use DateInterval;
use DateTimeImmutable;
use OCA\Dossiq\AppInfo\Registrar\CaseTypeListenerRegistrar;
use OCA\Dossiq\Listener\CaseInheritedDeadlineListener;
use OCA\Dossiq\Listener\CaseTypeParentCycleListener;
use OCA\Dossiq\Service\CaseTypeResolver;
use OCA\Dossiq\Service\CaseTypeStore;
use OCA\Dossiq\Service\SettingsService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Dossiq\Listener\CaseInheritedDeadlineListener
 * @covers \OCA\Dossiq\AppInfo\Registrar\CaseTypeListenerRegistrar
 * @uses   \OCA\Dossiq\Service\CaseTypeResolver
 * @uses   \OCA\Dossiq\Service\CaseTypeStore
 */
class CaseInheritedDeadlineListenerTest extends TestCase {

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
	];

	/**
	 * The logger, so a test can expect a warning.
	 *
	 * @var LoggerInterface
	 */
	private LoggerInterface $logger;

	/**
	 * Build the listener over a fixed store of case types.
	 *
	 * @return CaseInheritedDeadlineListener The listener.
	 */
	private function listener(): CaseInheritedDeadlineListener {
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
		};

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($objectService);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => match ($key) {
				'register' => 'dossiq',
				'case_type_schema' => 'case-type-schema-id',
				'case_schema' => 'case-schema-id',
				default => $default,
			}
		);

		return new CaseInheritedDeadlineListener(
			$settings,
			new CaseTypeResolver(new CaseTypeStore($settings)),
			$this->logger,
		);
	}//end listener()

	/**
	 * Set up the logger.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->logger = $this->createMock(LoggerInterface::class);
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
	 * The scenario: a child that sets no deadline, under a twelve-week parent.
	 *
	 * @return void
	 */
	public function testACaseOfASilentChildIsDueTheParentsTermAfterItStarts(): void {
		$event = $this->create(['caseType' => 'stil', 'startDate' => '2026-09-11', 'deadline' => null]);

		$this->assertSame(
			['deadline' => '2026-12-04', 'statutoryTerm' => 'P12W'],
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
	 * A child that sets its own term is left to the declarative calculation.
	 *
	 * @return void
	 */
	public function testAChildThatSetsItsOwnTermIsLeftAlone(): void {
		$event = $this->create(['caseType' => 'verkort', 'startDate' => '2026-09-11', 'deadline' => '2026-10-23']);

		$this->assertSame([], $event->getModifiedData());
	}//end testAChildThatSetsItsOwnTermIsLeftAlone()

	/**
	 * A type with no parent has nothing to inherit.
	 *
	 * @return void
	 */
	public function testATypeWithoutAParentIsLeftAlone(): void {
		$this->assertSame([], $this->create(['caseType' => 'los', 'startDate' => '2026-09-11'])->getModifiedData());
		$this->assertSame([], $this->create(['caseType' => 'bezwaar', 'startDate' => '2026-09-11'])->getModifiedData());
	}//end testATypeWithoutAParentIsLeftAlone()

	/**
	 * An empty start date counts from today, as the startDate calculation does.
	 *
	 * @return void
	 */
	public function testAnEmptyStartDateCountsFromToday(): void {
		$event = $this->create(['caseType' => 'stil']);

		$expected = (new DateTimeImmutable('today'))->add(new DateInterval('P12W'))->format('Y-m-d');
		$this->assertSame($expected, $event->getModifiedData()['deadline'] ?? null);
	}//end testAnEmptyStartDateCountsFromToday()

	/**
	 * An update is recomputed too, so a changed start date moves the deadline.
	 *
	 * @return void
	 */
	public function testAnUpdateIsRecomputedFromItsStartDate(): void {
		$entity = new ObjectEntity();
		$entity->setObject(['caseType' => ['id' => 'stil'], 'startDate' => '2026-02-01T00:00:00+00:00']);
		$entity->setSchema('case-schema-id');
		$entity->setUuid('case-1');

		$event = new ObjectUpdatingEvent($entity, null);
		$event->setModifiedData(['note' => 'set by another listener']);
		$this->listener()->handle($event);

		$this->assertSame(
			['note' => 'set by another listener', 'deadline' => '2026-04-26', 'statutoryTerm' => 'P12W'],
			$event->getModifiedData(),
			'another listener\'s modified data must survive'
		);
	}//end testAnUpdateIsRecomputedFromItsStartDate()

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
			$this->assertContains([$event, CaseInheritedDeadlineListener::class, -100], $registered);
		}

		$this->assertLessThan(0, CaseTypeListenerRegistrar::INHERITED_DEADLINE_PRIORITY);
	}//end testTheDeadlineListenerIsRegisteredBelowTheCalculation()
}//end class
