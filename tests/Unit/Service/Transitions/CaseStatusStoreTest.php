<?php

/**
 * Unit tests for CaseStatusStore: reading and writing cases and status
 * records through OpenRegister, the configuration each needs, the shape of a
 * status record, and the status type lookups.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Transitions;

use JsonSerializable;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Timeline\StatusMoveEntry;
use OCA\Dossiq\Service\Transitions\CaseStatusStore;
use OCA\Dossiq\Service\Transitions\StatusTypeLookup;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Service\Transitions\CaseStatusStore
 * @uses   \OCA\Dossiq\Service\Support\TranslatedText
 * @uses   \OCA\Dossiq\Service\Support\LanguageMapText
 */
class CaseStatusStoreTest extends TestCase {

	private SettingsService&MockObject $settings;

	private StatusTypeLookup&MockObject $lookup;

	private LoggerInterface&MockObject $logger;

	/** @var array<string, string> */
	private array $config = [];

	/** The fake object service: records the calls it receives. */
	private object $objects;

	protected function setUp(): void {
		parent::setUp();
		$this->config = ['register' => '5', 'case_schema' => '7', 'status_record_schema' => '9'];
		$this->objects = new class {
			/** @var array<int, array> */
			public array $calls = [];
			public mixed $findResult = null;
			public ?\Throwable $findThrows = null;
			public mixed $saveResult = null;
			public mixed $searchResult = [];
			public ?\Throwable $searchThrows = null;

			public function find(string $id, string $register, string $schema): mixed {
				$this->calls[] = ['find', $id, $register, $schema];
				if ($this->findThrows !== null) {
					throw $this->findThrows;
				}
				return $this->findResult;
			}

			public function saveObject(array $object, string $register, string $schema): mixed {
				$this->calls[] = ['saveObject', $object, $register, $schema];
				return $this->saveResult ?? $object;
			}

			public function searchObjects(array $query): mixed {
				$this->calls[] = ['searchObjects', $query];
				if ($this->searchThrows !== null) {
					throw $this->searchThrows;
				}
				return $this->searchResult;
			}
		};

		$this->settings = $this->createMock(SettingsService::class);
		$this->settings->method('getObjectService')->willReturnCallback(fn (): ?object => $this->objects);
		$this->settings->method('getConfigValue')->willReturnCallback(fn (string $key): string => ($this->config[$key] ?? ''));
		$this->lookup = $this->createMock(StatusTypeLookup::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}

	private function store(?StatusMoveEntry $moveEntry = null): CaseStatusStore {
		return new CaseStatusStore($this->settings, $this->lookup, $this->logger, $moveEntry);
	}

	private function withoutObjectService(): void {
		$this->settings = $this->createMock(SettingsService::class);
		$this->settings->method('getObjectService')->willReturn(null);
		$this->settings->method('getConfigValue')->willReturnCallback(fn (string $key): string => ($this->config[$key] ?? ''));
	}

	public function testLoadCaseFindsTheCaseInTheConfiguredSchema(): void {
		$this->objects->findResult = new class implements JsonSerializable {
			public function jsonSerialize(): array {
				return ['id' => 'case-1', 'status' => 's1'];
			}
		};

		$this->assertSame(['id' => 'case-1', 'status' => 's1'], $this->store()->loadCase('case-1'));
		$this->assertSame([['find', 'case-1', '5', '7']], $this->objects->calls);
	}

	public function testLoadCaseIsNullWithoutStorageOrConfiguration(): void {
		$this->config['case_schema'] = '';
		$this->assertNull($this->store()->loadCase('case-1'));
		$this->assertSame([], $this->objects->calls);

		$this->withoutObjectService();
		$this->assertNull($this->store()->loadCase('case-1'));
	}

	public function testLoadCaseLogsAndReturnsNullWhenTheReadFails(): void {
		$this->objects->findThrows = new RuntimeException('gone');
		$this->logger->expects($this->once())->method('error')->with(
			'StatusTransitionService: loadCase failed',
			['exception' => 'gone', 'caseId' => 'case-1'],
		);

		$this->assertNull($this->store()->loadCase('case-1'));
	}

	public function testLoadCaseReturnsAnEmptyArrayForAnUnserialisableResult(): void {
		$this->objects->findResult = new \stdClass();
		$this->assertSame([], $this->store()->loadCase('case-1'));

		$this->objects->findResult = new class implements JsonSerializable {
			public function jsonSerialize(): string {
				return 'scalar';
			}
		};
		$this->assertSame([], $this->store()->loadCase('case-1'));
	}

	public function testSaveCaseWritesToTheCaseSchema(): void {
		$this->objects->saveResult = ['id' => 'case-1', 'status' => 's2'];

		$this->assertSame(['id' => 'case-1', 'status' => 's2'], $this->store()->saveCase(['id' => 'case-1']));
		$this->assertSame([['saveObject', ['id' => 'case-1'], '5', '7']], $this->objects->calls);
	}

	public function testSaveCaseRefusesWithoutConfiguration(): void {
		$this->config['register'] = '';
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('case_schema_not_configured');

		$this->store()->saveCase(['id' => 'case-1']);
	}

	public function testSaveCaseRefusesWithoutStorage(): void {
		$this->withoutObjectService();
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('storage_unavailable');

		$this->store()->saveCase(['id' => 'case-1']);
	}

	public function testWriteStatusRecordWritesTheFullRecordAndTheTimelineEntry(): void {
		$this->objects->saveResult = ['id' => 'rec-1'];
		$moveEntry = $this->createMock(StatusMoveEntry::class);
		$moveEntry->expects($this->once())->method('record')->with(
			'case-1', 's2', 's1', 'Approve', 'looks fine', ['id' => 'rec-1'], 'alice',
		);

		$record = $this->store($moveEntry)->writeStatusRecord(
			caseId: 'case-1',
			toStatus: 's2',
			fromStatus: 's1',
			label: 'Approve',
			comment: 'looks fine',
			evaluatedGuards: [['type' => 'role', 'passed' => true]],
			noWorkflowTemplate: false,
			actor: 'alice',
		);

		$this->assertSame(['id' => 'rec-1'], $record);
		$this->assertSame(
			[
				'saveObject',
				[
					'case' => 'case-1',
					'statusType' => 's2',
					'transitionLabel' => 'Approve',
					'evaluatedGuards' => [['type' => 'role', 'passed' => true]],
					'dispatchedActions' => [],
					'noWorkflowTemplate' => false,
					'actor' => 'alice',
					'fromStatus' => 's1',
					'description' => 'looks fine',
				],
				'5',
				'9',
			],
			$this->objects->calls[0]
		);
	}

	public function testWriteStatusRecordOmitsAnEmptyActorOriginAndComment(): void {
		$this->store()->writeStatusRecord(
			caseId: 'case-1',
			toStatus: 's2',
			fromStatus: '',
			label: 'Start',
			comment: '',
			evaluatedGuards: [],
			noWorkflowTemplate: true,
			actor: '   ',
		);

		$this->assertSame(
			[
				'case' => 'case-1',
				'statusType' => 's2',
				'transitionLabel' => 'Start',
				'evaluatedGuards' => [],
				'dispatchedActions' => [],
				'noWorkflowTemplate' => true,
			],
			$this->objects->calls[0][1]
		);
	}

	public function testWriteStatusRecordRefusesWithoutTheRecordSchema(): void {
		$this->config['status_record_schema'] = '';
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('status_record_schema_not_configured');

		$this->store()->writeStatusRecord('case-1', 's2', 's1', 'Go', null, [], false);
	}

	public function testWriteStatusRecordRefusesWithoutStorage(): void {
		$this->withoutObjectService();
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('storage_unavailable');

		$this->store()->writeStatusRecord('case-1', 's2', 's1', 'Go', null, [], false);
	}

	public function testUpdateStatusRecordSavesOrReturnsTheRecordUnchanged(): void {
		$this->objects->saveResult = ['id' => 'rec-1', 'dispatchedActions' => ['x']];
		$this->assertSame(['id' => 'rec-1', 'dispatchedActions' => ['x']], $this->store()->updateStatusRecord(['id' => 'rec-1']));
		$this->assertSame([['saveObject', ['id' => 'rec-1'], '5', '9']], $this->objects->calls);

		$this->config['status_record_schema'] = '';
		$this->assertSame(['id' => 'rec-1'], $this->store()->updateStatusRecord(['id' => 'rec-1']));
		$this->assertCount(1, $this->objects->calls);

		$this->withoutObjectService();
		$this->assertSame(['id' => 'rec-2'], $this->store()->updateStatusRecord(['id' => 'rec-2']));
	}

	public function testFindStatusRecordsQueriesByCaseAndSerialisesEachRecord(): void {
		$this->objects->searchResult = [
			['id' => 'rec-1'],
			new class implements JsonSerializable {
				public function jsonSerialize(): array {
					return ['id' => 'rec-2'];
				}
			},
		];

		$this->assertSame([['id' => 'rec-1'], ['id' => 'rec-2']], $this->store()->findStatusRecords('case-1'));
		$this->assertSame(
			[['searchObjects', ['@self' => ['register' => 5, 'schema' => 9], 'case' => 'case-1']]],
			$this->objects->calls
		);
	}

	public function testFindStatusRecordsTreatsANonArrayResultAsNoRecords(): void {
		$this->objects->searchResult = null;

		$this->assertSame([], $this->store()->findStatusRecords('case-1'));
	}

	public function testFindStatusRecordsLogsAndReturnsNullWhenTheSearchFails(): void {
		$this->objects->searchThrows = new RuntimeException('down');
		$this->logger->expects($this->once())->method('error')->with(
			'StatusTransitionService: replay searchObjects failed',
			['exception' => 'down', 'caseId' => 'case-1'],
		);

		$this->assertNull($this->store()->findStatusRecords('case-1'));
	}

	public function testFindStatusRecordsIsNullWithoutStorageOrConfiguration(): void {
		$this->config['register'] = '';
		$this->assertNull($this->store()->findStatusRecords('case-1'));
		$this->assertSame([], $this->objects->calls);

		$this->withoutObjectService();
		$this->assertNull($this->store()->findStatusRecords('case-1'));
	}

	public function testStatusLookupsReadTheStatusTypeRow(): void {
		$this->lookup->method('nameFor')->with('st-1')->willReturn('Intake');
		$this->lookup->method('rowFor')->willReturnMap([
			['st-1', ['colour' => '#ff0000', 'description' => 'First step']],
			['st-2', ['colour' => ['not', 'a', 'string']]],
		]);
		$store = $this->store();

		$this->assertSame('Intake', $store->lookupStatusName('st-1'));
		$this->assertSame('#ff0000', $store->lookupStatusColour('st-1'));
		$this->assertSame('', $store->lookupStatusColour('st-2'));
		$this->assertSame('First step', $store->lookupStatusDescription('st-1'));
		$this->assertSame('', $store->lookupStatusDescription('st-2'));
	}

	public function testAStatusBelongsToTheCaseTypeItsRowNames(): void {
		$this->lookup->method('rowFor')->willReturnMap([
			['st-1', ['caseType' => 'ct-1']],
			['st-2', ['caseType' => ['id' => 'ct-1']]],
			['st-3', ['caseType' => ['uuid' => 'ct-1']]],
		]);
		$store = $this->store();

		$store->assertStatusBelongsToCaseType('ct-1', 'st-1');
		$store->assertStatusBelongsToCaseType('ct-1', 'st-2');
		$store->assertStatusBelongsToCaseType('ct-1', 'st-3');
		$this->addToAssertionCount(3);
	}

	public function testAStatusOfAnotherCaseTypeIsRefused(): void {
		$this->lookup->method('rowFor')->willReturn(['caseType' => 'ct-2']);
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('status_type_not_in_case_type');

		$this->store()->assertStatusBelongsToCaseType('ct-1', 'st-1');
	}

	public function testAnEmptyCaseTypeOrStatusIsUnconfigured(): void {
		$this->lookup->expects($this->never())->method('rowFor');
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('case_type_not_configured');

		$this->store()->assertStatusBelongsToCaseType('', 'st-1');
	}
}
