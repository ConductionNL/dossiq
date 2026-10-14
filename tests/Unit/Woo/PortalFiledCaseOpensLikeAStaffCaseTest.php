<?php

/**
 * A case a resident files through the portal opens as a staff-created case does.
 *
 * Found on :8080 with probe case 2026-0104 (14 October): a Woo request filed
 * through the portal got no acknowledgement of receipt, no handling group, no
 * confidentiality and no moment it entered its first status. The intake
 * writes inside OpenRegister's system operation, where OpenRegister withholds
 * `ObjectCreatedEvent`, and nothing filled the fields a staff create form
 * fills.
 *
 * Driven through the real caller, `WooRequestIntake::start()`, on a store that
 * behaves as OpenRegister does there: it has `runAsSystem()`, it answers the
 * write with an object entity, and it sends no event of its own. The event the
 * intake sends is handed to the real acknowledgement listener, so the test
 * ends where the resident's receipt starts: a queued AcknowledgementDispatchJob.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Test
 * @package   OCA\Dossiq\Tests\Unit\Woo
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/dossiq
 *
 * @spec openspec/changes/a-portal-filed-case-opens-like-a-staff-created-one/specs/woo-request-intake/spec.md#requirement-a-case-the-system-writes-opens-as-a-staff-created-case-does-req-wri-005
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Woo;

use OCA\Dossiq\BackgroundJob\AcknowledgementDispatchJob;
use OCA\Dossiq\Listener\AcknowledgementOnCreateListener;
use OCA\Dossiq\Service\Intake\CaseCreationAnnouncer;
use OCA\Dossiq\Service\Intake\SystemCaseStore;
use OCA\Dossiq\Service\ObjectSchemaSlugResolver;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Tests\Support\InMemoryRegister;
use OCA\Dossiq\Woo\WooRequestIntake;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCP\BackgroundJob\IJobList;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IURLGenerator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Files a Woo request through the intake and reads what the case became.
 *
 * @covers \OCA\Dossiq\Service\Intake\CaseCreationAnnouncer
 * @covers \OCA\Dossiq\Service\Intake\CaseOpeningDefaults
 * @covers \OCA\Dossiq\Service\Intake\SystemCaseStore
 *
 * @uses \OCA\Dossiq\Woo\WooRequestIntake
 * @uses \OCA\Dossiq\Woo\WooRequestForm
 * @uses \OCA\Dossiq\Woo\WooRequesterProperties
 * @uses \OCA\Dossiq\Woo\WooWrittenCase
 * @uses \OCA\Dossiq\Service\CaseType\CaseTypeHandling
 * @uses \OCA\Dossiq\Service\Intake\CollectionCaseLinks
 * @uses \OCA\Dossiq\Service\Support\UnscopedObjectReader
 * @uses \OCA\Dossiq\Service\Support\SearchesObjects
 * @uses \OCA\Dossiq\Listener\AcknowledgementOnCreateListener
 * @uses \OCA\Dossiq\Service\Intake\ReceiptMoment
 */
class PortalFiledCaseOpensLikeAStaffCaseTest extends TestCase {

	private const RESIDENT = 'subject-ref-anna';

	/**
	 * The rows.
	 *
	 * @var InMemoryRegister
	 */
	private InMemoryRegister $store;

	/**
	 * Whether the store is inside runAsSystem(), read by the dispatcher.
	 *
	 * @var array{depth: int}
	 */
	private array $system = ['depth' => 0];

	/**
	 * Every event the intake sent, with whether it went out as the system.
	 *
	 * @var array<int, array{event: Event, asSystem: bool}>
	 */
	private array $sent = [];

	/**
	 * Every job the real acknowledgement listener queued.
	 *
	 * @var array<int, array{class: string, argument: mixed}>
	 */
	private array $queued = [];

	/**
	 * The shipped Woo request case type, as register.d/81-woo-verzoek.json declares it.
	 *
	 * @return array<string, mixed>
	 */
	private static function shippedWooCaseType(): array {
		$fragment = json_decode(
			(string)file_get_contents(__DIR__ . '/../../../lib/Settings/register.d/81-woo-verzoek.json'),
			true,
			512,
			JSON_THROW_ON_ERROR
		);
		foreach ($fragment['components']['objects'] as $object) {
			if (($object['@self']['slug'] ?? '') === 'woo-verzoek') {
				return $object;
			}
		}

		self::fail('register.d/81-woo-verzoek.json ships no woo-verzoek case type');
	}//end shippedWooCaseType()

	/**
	 * Seed the shipped case type.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->store = new InMemoryRegister();
		$caseType    = self::shippedWooCaseType();
		$this->store->seed(schema: 'caseType', uuid: WooRequestIntake::CASE_TYPE_ID, row: $caseType);
	}//end setUp()

	/**
	 * The store as OpenRegister behaves in a system operation: it has
	 * runAsSystem(), answers a case write with an entity and sends no event.
	 *
	 * @return object
	 */
	private function openRegister(): object {
		$system = &$this->system;

		return new class($this->store, $system) {

			/**
			 * Constructor.
			 *
			 * @param InMemoryRegister  $inner  The rows.
			 * @param array{depth: int} $system Shared with the test: the system depth.
			 */
			public function __construct(private InMemoryRegister $inner, private array &$system) {
			}//end __construct()

			/**
			 * Run as the system, as ObjectService::runAsSystem() does.
			 *
			 * @param callable $operation The operation.
			 *
			 * @return mixed What it returned.
			 */
			public function runAsSystem(callable $operation): mixed {
				$this->system['depth']++;
				try {
					return $operation();
				} finally {
					$this->system['depth']--;
				}
			}//end runAsSystem()

			/**
			 * Store a row; a case comes back as the entity OpenRegister answers with.
			 *
			 * @param array<string, mixed> $object        The row.
			 * @param int|string           $register      Ignored.
			 * @param int|string           $schema        The schema slug.
			 * @param string|null          $uuid          The uuid to update, or null.
			 * @param bool                 $_rbac         Ignored.
			 * @param bool                 $_multitenancy Ignored.
			 *
			 * @return mixed The row, or for a case its entity.
			 */
			public function saveObject(
				array $object,
				int|string $register = '',
				int|string $schema = '',
				?string $uuid = null,
				bool $_rbac = true,
				bool $_multitenancy = true,
			): mixed {
				$row = $this->inner->saveObject(object: $object, register: $register, schema: $schema, uuid: $uuid, _rbac: $_rbac, _multitenancy: $_multitenancy);
				if ($schema !== 'case') {
					return $row;
				}

				$entity = new ObjectEntity();
				$entity->setUuid((string)$row['id']);
				$entity->setSchema('case');
				$entity->setObject($row);

				return $entity;
			}//end saveObject()

			/**
			 * Every other read goes to the rows.
			 *
			 * @param string            $name      The method.
			 * @param array<mixed>      $arguments Its arguments, named or not.
			 *
			 * @return mixed What the rows answered.
			 */
			public function __call(string $name, array $arguments): mixed {
				return $this->inner->$name(...$arguments);
			}//end __call()
		};
	}//end openRegister()

	/**
	 * The intake, wired as the container wires it, with the real acknowledgement listener behind the dispatcher.
	 *
	 * @param bool $withAnnouncer Whether the container handed it an announcer.
	 *
	 * @return WooRequestIntake
	 */
	private function intake(bool $withAnnouncer = true): WooRequestIntake {
		/** @var SettingsService&MockObject $settings */
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->openRegister());
		$settings->method('getConfigValue')->willReturnCallback(
			fn (string $key, string $default = ''): string => [
				'register' => 'dossiq',
				'case_schema' => 'case',
				'case_type_schema' => 'caseType',
				'case_object_schema' => 'caseObject',
				'property_definition_schema' => 'propertyDefinition',
			][$key] ?? $default
		);

		/** @var IURLGenerator&MockObject $urls */
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('getAbsoluteURL')->willReturnCallback(fn (string $path): string => 'https://gemeente.test' . $path);

		$jobList = $this->createMock(IJobList::class);
		$jobList->method('add')->willReturnCallback(
			function (string $class, mixed $argument = null): void {
				$this->queued[] = ['class' => $class, 'argument' => $argument];
			}
		);
		$slugs = $this->createMock(ObjectSchemaSlugResolver::class);
		$slugs->method('resolveFromPayload')->willReturnCallback(
			fn (array $payload): string => (string)($payload['@self']['schema'] ?? '')
		);
		$acknowledgement = new AcknowledgementOnCreateListener(
			jobList: $jobList,
			slugResolver: $slugs,
			logger: $this->createMock(LoggerInterface::class),
		);

		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(
			function (Event $event) use ($acknowledgement): void {
				$this->sent[] = ['event' => $event, 'asSystem' => $this->system['depth'] > 0];
				$acknowledgement->handle($event);
			}
		);

		$announcer = null;
		if ($withAnnouncer === true) {
			$announcer = new CaseCreationAnnouncer(dispatcher: $dispatcher, logger: $this->createMock(LoggerInterface::class));
		}

		$logger = $this->createMock(LoggerInterface::class);

		return new WooRequestIntake(
			settingsService: $settings,
			urlGenerator: $urls,
			logger: $logger,
			cases: new SystemCaseStore(settingsService: $settings, logger: $logger, announcer: $announcer),
		);
	}//end intake()

	/**
	 * A request as the portal sends it.
	 *
	 * @return array<string, mixed>
	 */
	private function request(): array {
		return [
			'subjectRef' => self::RESIDENT,
			'onderwerp' => 'Parkeren in het centrum',
			'omschrijving' => 'Alle stukken over de parkeerregels in het centrum.',
			'periodeVan' => '2025-01-01',
			'periodeTot' => '2025-12-31',
			'origin' => 'portal',
			'originReference' => 'portal-form-1',
		];
	}//end request()

	/**
	 * The filed case is announced once, as the system, and its acknowledgement is queued.
	 *
	 * @return void
	 */
	public function testTheFiledCaseIsAnnouncedAndItsReceiptIsQueued(): void {
		$result = $this->intake()->start($this->request());

		$this->assertCount(1, $this->sent, 'the intake announces the case exactly once');
		$this->assertInstanceOf(ObjectCreatedEvent::class, $this->sent[0]['event']);
		$this->assertTrue($this->sent[0]['asSystem'], 'listeners run with the authority the case was written with');
		$this->assertSame($result['caseId'], (string)$this->sent[0]['event']->getObject()->getUuid());

		$this->assertSame(
			[['class' => AcknowledgementDispatchJob::class, 'argument' => ['caseId' => $result['caseId'], 'attempt' => 1]]],
			$this->queued,
			'the acknowledgement of receipt is queued for the filed case'
		);
	}//end testTheFiledCaseIsAnnouncedAndItsReceiptIsQueued()

	/**
	 * The filed case carries the opening fields its case type declares.
	 *
	 * @return void
	 */
	public function testTheFiledCaseTakesItsOpeningFieldsFromItsCaseType(): void {
		$caseType = self::shippedWooCaseType();
		$before   = time();

		$result = $this->intake()->start($this->request());
		$case   = $this->store->row(schema: 'case', uuid: $result['caseId']);

		$this->assertSame('behandelaars', $case['assignedGroup'], 'the handling group the case type names');
		$this->assertSame($caseType['confidentiality'], $case['confidentiality']);
		$this->assertSame($caseType['initialStatus'], $case['status']);
		$this->assertArrayNotHasKey('assignee', $case, 'the shipped type names no handler, so none is made up');
		$this->assertGreaterThanOrEqual($before, strtotime((string)$case['currentStatusEnteredAt']));
	}//end testTheFiledCaseTakesItsOpeningFieldsFromItsCaseType()

	/**
	 * A handler the case type names is the case's handler; a value the caller wrote is kept.
	 *
	 * @return void
	 */
	public function testANamedHandlerIsSetAndAWrittenValueIsKept(): void {
		$caseType = self::shippedWooCaseType();
		$caseType['handling']['defaultHandler'] = 'thomas.kuiper';
		$caseType['confidentiality'] = 'vertrouwelijk';
		$this->store->seed(schema: 'caseType', uuid: WooRequestIntake::CASE_TYPE_ID, row: $caseType);

		$result = $this->intake()->start($this->request());
		$case   = $this->store->row(schema: 'case', uuid: $result['caseId']);

		$this->assertSame('thomas.kuiper', $case['assignee']);
		$this->assertSame('vertrouwelijk', $case['confidentiality']);
	}//end testANamedHandlerIsSetAndAWrittenValueIsKept()

	/**
	 * Without an announcer the case is still filed, unannounced.
	 *
	 * @return void
	 */
	public function testWithoutAnAnnouncerTheCaseIsStillFiled(): void {
		$result = $this->intake(withAnnouncer: false)->start($this->request());

		$this->assertNotSame('', $result['caseId']);
		$this->assertSame([], $this->sent);
		$this->assertSame([], $this->queued);
	}//end testWithoutAnAnnouncerTheCaseIsStillFiled()
}//end class
