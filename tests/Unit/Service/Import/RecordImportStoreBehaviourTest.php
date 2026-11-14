<?php

/**
 * The record-import store's source listing, case type versions, result
 * writes and refused stamps.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Test
 * @package   OCA\Dossiq\Tests\Unit\Service\Import
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/dossiq
 *
 * @spec openspec/changes/woo-request-takes-over-from-opencatalogi/specs/case-record-import/spec.md#requirement-every-declared-source-record-is-imported-exactly-once-req-cri-002
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Import;

use DateTime;
use OCA\Dossiq\Service\Import\RecordCaseMapping;
use OCA\Dossiq\Service\Import\RecordImportStore;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Tests\Support\InMemoryRegister;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * What the store answers for each read and write, and when OpenRegister refuses one.
 *
 * @covers \OCA\Dossiq\Service\Import\RecordImportStore
 */
class RecordImportStoreBehaviourTest extends TestCase {

	/**
	 * The logger double.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * Set up the logger double.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * The store under test.
	 *
	 * @return RecordImportStore
	 */
	private function store(): RecordImportStore {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getConfigValue')->willReturnCallback(
			fn (string $key, string $default = ''): string => [
				'register' => 'dossiq',
				'case_schema' => 'case',
				'case_type_schema' => 'caseType',
				'result_schema' => 'result',
			][$key] ?? $default
		);

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new DateTime('2026-10-20T09:00:00+02:00'));

		return new RecordImportStore(
			settingsService: $settings,
			mapping: $this->createMock(RecordCaseMapping::class),
			time: $time,
			logger: $this->logger,
		);
	}//end store()

	/**
	 * The source records are listed from the import's own register and schema.
	 *
	 * @return void
	 */
	public function testTheSourcesAreReadFromTheDeclaredSchema(): void {
		$register = new InMemoryRegister();
		$register->seed(schema: 'record', uuid: 'rec-1', row: ['title' => 'A']);
		$register->seed(schema: 'other', uuid: 'x-1', row: ['title' => 'B']);

		self::assertSame(
			[['title' => 'A', 'id' => 'rec-1']],
			$this->store()->sources(objectService: $register, import: ['sourceRegister' => 'src', 'sourceSchema' => 'record'])
		);
	}//end testTheSourcesAreReadFromTheDeclaredSchema()

	/**
	 * An unreadable source is null, not an empty list, and is logged with the import key.
	 *
	 * @return void
	 */
	public function testUnreadableSourcesAreNullAndLogged(): void {
		$register = new InMemoryRegister();
		$register->missingSchemas = ['record'];

		$this->logger->expects($this->once())->method('error')->with(
			'RecordImportStore: the source records could not be read',
			$this->callback(static fn (array $context): bool => $context['import'] === 'woo' && $context['error'] !== '')
		);

		self::assertNull($this->store()->sources(objectService: $register, import: ['key' => 'woo', 'sourceSchema' => 'record']));
	}//end testUnreadableSourcesAreNullAndLogged()

	/**
	 * Without a result type, only the case is written.
	 *
	 * @return void
	 */
	public function testACaseWithoutAResultWritesNoResult(): void {
		$register = new InMemoryRegister();

		self::assertSame('generated-1', $this->store()->writeCase(objectService: $register, mapped: ['case' => ['title' => 'T'], 'result' => '']));
		self::assertSame([], $register->all('result'));
		self::assertSame(1, $register->writes);
	}//end testACaseWithoutAResultWritesNoResult()

	/**
	 * A refused result write still answers the case id, and is logged as a warning.
	 *
	 * @return void
	 */
	public function testARefusedResultKeepsTheCase(): void {
		$register = new InMemoryRegister();
		$register->refusedSchemas = ['result'];

		$this->logger->expects($this->once())->method('warning')->with(
			'RecordImportStore: the result of a closed record could not be written',
			$this->callback(static fn (array $context): bool => $context['case'] === 'generated-1')
		);

		self::assertSame('generated-1', $this->store()->writeCase(objectService: $register, mapped: ['case' => ['title' => 'T'], 'result' => 'r-1']));
		self::assertSame([['title' => 'T', 'id' => 'generated-1']], $register->all('case'));
	}//end testARefusedResultKeepsTheCase()

	/**
	 * A refused case write answers an empty id and writes no result.
	 *
	 * @return void
	 */
	public function testARefusedCaseAnswersNoId(): void {
		$register = new InMemoryRegister();
		$register->refusedSchemas = ['case'];

		$this->logger->expects($this->once())->method('error')->with('RecordImportStore: the case could not be written', $this->anything());

		self::assertSame('', $this->store()->writeCase(objectService: $register, mapped: ['case' => ['title' => 'T'], 'result' => 'r-1']));
		self::assertSame([], $register->all('result'));
	}//end testARefusedCaseAnswersNoId()

	/**
	 * A saved entity answers its own uuid, and the result is written against that uuid.
	 *
	 * @return void
	 */
	public function testASavedEntityAnswersItsUuidAndCarriesTheResult(): void {
		$service = new class {
			/**
			 * Every write, in order.
			 *
			 * @var array<int, array<string, mixed>>
			 */
			public array $writes = [];

			/**
			 * Record the write and answer an entity for the case.
			 *
			 * @param array<string, mixed> $object        The object.
			 * @param string               $register      The register.
			 * @param string               $schema        The schema.
			 * @param bool                 $_rbac         Ignored.
			 * @param bool                 $_multitenancy Ignored.
			 *
			 * @return object|array<string, mixed>
			 */
			public function saveObject(array $object, string $register, string $schema, bool $_rbac, bool $_multitenancy): object|array {
				$this->writes[] = ['object' => $object, 'register' => $register, 'schema' => $schema];
				if ($schema !== 'case') {
					return $object;
				}

				return new class {
					/**
					 * The uuid.
					 *
					 * @return string
					 */
					public function getUuid(): string {
						return 'case-uuid-7';
					}
				};
			}
		};

		self::assertSame('case-uuid-7', $this->store()->writeCase(objectService: $service, mapped: ['case' => ['title' => 'T'], 'result' => 'r-9']));
		self::assertSame(
			[
				['object' => ['title' => 'T'], 'register' => 'dossiq', 'schema' => 'case'],
				['object' => ['case' => 'case-uuid-7', 'resultType' => 'r-9'], 'register' => 'dossiq', 'schema' => 'result'],
			],
			$service->writes
		);
	}//end testASavedEntityAnswersItsUuidAndCarriesTheResult()

	/**
	 * A stamp without an "at" field writes only the case id.
	 *
	 * @return void
	 */
	public function testAStampWithoutAnAtFieldWritesOnlyTheCaseId(): void {
		$register = new InMemoryRegister();
		$register->seed(schema: 'record', uuid: 'rec-1', row: ['title' => 'T']);

		$stamped = $this->store()->stamp(
			objectService: $register,
			import: ['sourceRegister' => 'src', 'sourceSchema' => 'record', 'stamp' => ['caseId' => 'movedTo']],
			sourceId: 'rec-1',
			caseId: 'case-1'
		);

		self::assertTrue($stamped);
		self::assertSame('case-1', $register->row('record', 'rec-1')['movedTo']);
		self::assertArrayNotHasKey('movedAt', $register->row('record', 'rec-1'));
	}//end testAStampWithoutAnAtFieldWritesOnlyTheCaseId()

	/**
	 * A refused stamp answers false and is logged with the record id.
	 *
	 * @return void
	 */
	public function testARefusedStampAnswersFalse(): void {
		$register = new class extends InMemoryRegister {
			/**
			 * Refuse every read.
			 *
			 * @param mixed ...$args Ignored.
			 *
			 * @return array|null
			 */
			public function find(...$args): ?array {
				throw new RuntimeException('down');
			}//end find()
		};

		$this->logger->expects($this->once())->method('error')->with(
			'RecordImportStore: the stamp was refused',
			$this->callback(static fn (array $context): bool => $context['record'] === 'rec-1' && $context['error'] === 'down')
		);

		self::assertFalse(
			$this->store()->stamp(
				objectService: $register,
				import: ['sourceSchema' => 'record', 'stamp' => ['caseId' => 'movedTo']],
				sourceId: 'rec-1',
				caseId: 'case-1'
			)
		);
	}//end testARefusedStampAnswersFalse()

	/**
	 * Looked up by identifier, the case type answers the highest in-use version, not a draft or superseded one.
	 *
	 * @return void
	 */
	public function testTheCaseTypeByIdentifierIsTheHighestVersionInUse(): void {
		$register = new InMemoryRegister();
		$register->seed(schema: 'caseType', uuid: 'v1', row: ['identifier' => 'woo', 'version' => 1]);
		$register->seed(schema: 'caseType', uuid: 'v3', row: ['identifier' => 'woo', 'version' => 3]);
		$register->seed(schema: 'caseType', uuid: 'v4', row: ['identifier' => 'woo', 'version' => 4, 'isDraft' => true]);
		$register->seed(schema: 'caseType', uuid: 'v5', row: ['identifier' => 'woo', 'version' => 5, 'supersededBy' => 'v6']);
		$register->seed(schema: 'caseType', uuid: 'v2', row: ['identifier' => 'woo', 'version' => 2]);

		self::assertSame('v3', $this->store()->caseType(objectService: $register, caseType: 'woo')['id']);
	}//end testTheCaseTypeByIdentifierIsTheHighestVersionInUse()

	/**
	 * When no version is in use, the first one found is answered rather than nothing.
	 *
	 * @return void
	 */
	public function testWithNoVersionInUseTheFirstFoundIsAnswered(): void {
		$register = new InMemoryRegister();
		$register->seed(schema: 'caseType', uuid: 'd1', row: ['identifier' => 'woo', 'version' => 1, 'isDraft' => true]);
		$register->seed(schema: 'caseType', uuid: 'd2', row: ['identifier' => 'woo', 'version' => 2, 'supersededBy' => ' x ']);

		self::assertSame('d1', $this->store()->caseType(objectService: $register, caseType: 'woo')['id']);
	}//end testWithNoVersionInUseTheFirstFoundIsAnswered()

	/**
	 * The case types in use leave out drafts and superseded versions; a blank supersededBy counts as in use.
	 *
	 * @return void
	 */
	public function testTheCaseTypesInUseLeaveOutDraftsAndSupersededOnes(): void {
		$register = new InMemoryRegister();
		$register->seed(schema: 'caseType', uuid: 'a', row: ['version' => 1]);
		$register->seed(schema: 'caseType', uuid: 'b', row: ['isDraft' => true]);
		$register->seed(schema: 'caseType', uuid: 'c', row: ['supersededBy' => 'a']);
		$register->seed(schema: 'caseType', uuid: 'd', row: ['supersededBy' => '  ', 'isDraft' => false]);

		self::assertSame(
			['a', 'd'],
			array_column($this->store()->caseTypesInUse(objectService: $register), 'id')
		);
	}//end testTheCaseTypesInUseLeaveOutDraftsAndSupersededOnes()

	/**
	 * A listing reads every full page and stops after the first short one.
	 *
	 * @return void
	 */
	public function testAListingPagesUntilAShortPage(): void {
		$register = new InMemoryRegister();
		$register->pages = true;
		for ($i = 0; $i < 1001; $i++) {
			$register->seed(schema: 'record', uuid: 'rec-' . $i, row: []);
		}

		$rows = $this->store()->listAll(objectService: $register, register: 'src', schema: 'record', filters: []);

		self::assertCount(1001, $rows);
		self::assertSame('rec-1000', $rows[1000]['id']);
	}//end testAListingPagesUntilAShortPage()

	/**
	 * The id is read from id, then @self.id, then uuid.
	 *
	 * @return void
	 */
	public function testTheIdIsReadInOrderOfPreference(): void {
		$store = $this->store();

		self::assertSame('a', $store->idOf(row: ['id' => 'a', '@self' => ['id' => 'b'], 'uuid' => 'c']));
		self::assertSame('b', $store->idOf(row: ['@self' => ['id' => 'b'], 'uuid' => 'c']));
		self::assertSame('c', $store->idOf(row: ['uuid' => 'c']));
	}//end testTheIdIsReadInOrderOfPreference()
}//end class
