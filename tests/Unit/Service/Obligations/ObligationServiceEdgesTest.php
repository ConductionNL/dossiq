<?php

/**
 * Tests for the obligation service's edges: entity answers, ids under @self, and writes that fail.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\Obligations
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
 * @spec openspec/specs/consultation-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Obligations;

use JsonSerializable;
use OCA\Dossiq\Service\Obligations\ObligationDeclaration;
use OCA\Dossiq\Service\Obligations\ObligationService;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Tests\Support\InMemoryRegister;
use OCA\Dossiq\Tests\Support\MakesCaseDateNormaliser;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * A store answering what it is told to, and recording what it was given.
 */
class ScriptedObligationStore {

	/**
	 * What find() answers.
	 *
	 * @var mixed
	 */
	public mixed $found = null;

	/**
	 * What searchObjectsBySlug() answers.
	 *
	 * @var array<int, mixed>
	 */
	public array $rows = [];

	/**
	 * What saveObject() answers.
	 *
	 * @var mixed
	 */
	public mixed $saved = null;

	/**
	 * Every object given to saveObject().
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $writes = [];

	/**
	 * Answer the scripted find.
	 *
	 * @param string     $id       The id.
	 * @param int|string $register The register.
	 * @param int|string $schema   The schema.
	 *
	 * @return mixed
	 */
	public function find(string $id, int|string $register = '', int|string $schema = ''): mixed {
		return $this->found;
	}

	/**
	 * Answer the scripted search.
	 *
	 * @param string               $register The register.
	 * @param string               $schema   The schema.
	 * @param array<string, mixed> $filters  The filters.
	 *
	 * @return array<int, mixed>
	 */
	public function searchObjectsBySlug(string $register = '', string $schema = '', array $filters = []): array {
		return $this->rows;
	}

	/**
	 * Record the write and answer the scripted result.
	 *
	 * @param array<string, mixed> $object   The object.
	 * @param int|string           $register The register.
	 * @param int|string           $schema   The schema.
	 *
	 * @return mixed
	 */
	public function saveObject(array $object = [], int|string $register = '', int|string $schema = ''): mixed {
		$this->writes[] = $object;

		return $this->saved;
	}
}//end class

/**
 * An entity, as OpenRegister answers: only readable through jsonSerialize().
 */
class SerialisedObligation implements JsonSerializable {

	/**
	 * Hold the data.
	 *
	 * @param array<string, mixed> $data What it serialises to.
	 */
	public function __construct(private readonly array $data) {
	}

	/**
	 * Answer the data.
	 *
	 * @return array<string, mixed>
	 */
	public function jsonSerialize(): array {
		return $this->data;
	}
}//end class

/**
 * The edges of reading and writing obligations.
 *
 * @covers \OCA\Dossiq\Service\Obligations\ObligationService
 * @uses   \OCA\Dossiq\Service\Obligations\ObligationDeclaration
 * @uses   \OCA\Dossiq\Service\CaseDateNormaliser
 * @uses   \OCA\Dossiq\Service\Support\SearchesObjects
 *
 * @spec openspec/specs/consultation-management/spec.md
 */
class ObligationServiceEdgesTest extends TestCase {

	use MakesCaseDateNormaliser;

	/**
	 * The moment the frozen clock reports, in the administered zone.
	 *
	 * @var string
	 */
	private const NOW = '2026-09-20T00:30:00+02:00';

	/**
	 * The logger.
	 *
	 * @var LoggerInterface|MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * The configuration the service reads.
	 *
	 * @var array<string, string>
	 */
	private array $config = ['register' => 'dossiq', 'obligation_schema' => 'obligation'];

	/**
	 * Build the logger.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * The service under test, over the given object service.
	 *
	 * @param object $objectService The object service.
	 *
	 * @return ObligationService
	 */
	private function service(object $objectService): ObligationService {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($objectService);
		$settings->method('getConfigValue')->willReturnCallback(
			fn (string $key): string => ($this->config[$key] ?? '')
		);

		return new ObligationService(
			settingsService: $settings,
			declaration: new ObligationDeclaration(),
			dates: $this->caseDatesFrozenAt(),
			logger: $this->logger,
		);
	}//end service()

	/**
	 * A settlement whose write is refused answers false, and the failure is logged with the state.
	 *
	 * @return void
	 */
	public function testASettlementWhoseWriteFailsAnswersFalse(): void {
		$register = new InMemoryRegister();
		$register->seed(schema: 'obligation', uuid: 'ob-1', row: ['case' => 'case-1', 'state' => 'open']);
		$register->refusedSchemas = ['obligation'];

		$this->logger->expects($this->once())
			->method('error')
			->with(
				'Dossiq obligations: the obligation could not be written',
				['operation' => 'met', 'error' => 'Save refused for obligation']
			);

		$this->assertFalse($this->service(objectService: $register)->meet(obligationId: 'ob-1', note: 'done'));
		$this->assertSame('open', $register->row(schema: 'obligation', uuid: 'ob-1')['state']);
	}//end testASettlementWhoseWriteFailsAnswersFalse()

	/**
	 * A row that reads as nothing is not settled, and nothing is written.
	 *
	 * @return void
	 */
	public function testARowThatReadsAsNothingIsNotSettled(): void {
		$store        = new ScriptedObligationStore();
		$store->found = new SerialisedObligation(data: []);

		$this->assertFalse($this->service(objectService: $store)->withdraw(obligationId: 'ob-1', reason: 'x'));

		$store->found = 'not an object';
		$this->assertFalse($this->service(objectService: $store)->meet(obligationId: 'ob-1'));
		$this->assertSame([], $store->writes);
	}//end testARowThatReadsAsNothingIsNotSettled()

	/**
	 * An entity is read through jsonSerialize(), and the saved id is taken from @self.
	 *
	 * @return void
	 */
	public function testAnEntityIsSettledAndItsSelfIdCounts(): void {
		$store        = new ScriptedObligationStore();
		$store->found = new SerialisedObligation(data: ['case' => 'case-1', 'state' => 'open']);
		$store->saved = new SerialisedObligation(data: ['@self' => ['id' => 'ob-1']]);

		$this->assertTrue($this->service(objectService: $store)->withdraw(obligationId: 'ob-1', reason: 'Ingetrokken'));
		$this->assertSame(
			[['case' => 'case-1', 'state' => 'withdrawn', 'withdrawnAt' => self::NOW, 'withdrawalReason' => 'Ingetrokken']],
			$store->writes
		);
	}//end testAnEntityIsSettledAndItsSelfIdCounts()

	/**
	 * A write answering no id, or nothing readable, places nothing.
	 *
	 * @return void
	 */
	public function testAWriteWithoutAnIdPlacesNothing(): void {
		$store        = new ScriptedObligationStore();
		$store->saved = ['case' => 'case-1'];
		$service      = $this->service(objectService: $store);

		$this->assertSame('', $service->place(obligation: ['case' => 'case-1', 'kind' => 'advice']));

		$store->saved = null;
		$this->assertSame('', $service->place(obligation: ['case' => 'case-1', 'kind' => 'advice']));

		$store->saved = new SerialisedObligation(data: ['@self' => ['id' => 'ob-7']]);
		$this->assertSame('ob-7', $service->place(obligation: ['case' => ' case-1 ', 'kind' => 'advice']));
		$this->assertCount(3, $store->writes);
		$this->assertSame('open', $store->writes[2]['state']);
		$this->assertSame(self::NOW, $store->writes[2]['placedAt']);
	}//end testAWriteWithoutAnIdPlacesNothing()

	/**
	 * A source lookup skips rows without an id and reads one under @self.
	 *
	 * @return void
	 */
	public function testIdForSourceSkipsIdlessRowsAndReadsSelf(): void {
		$store       = new ScriptedObligationStore();
		$store->rows = [
			['source' => 'consultation-1'],
			['source' => 'consultation-1', 'id' => ''],
			new SerialisedObligation(data: ['source' => 'consultation-1', '@self' => ['id' => 'ob-9']]),
			['source' => 'consultation-1', 'id' => 'ob-10'],
		];

		$this->assertSame('ob-9', $this->service(objectService: $store)->idForSource(source: 'consultation-1'));

		$store->rows = [['source' => 'consultation-1']];
		$this->assertSame('', $this->service(objectService: $store)->idForSource(source: 'consultation-1'));
	}//end testIdForSourceSkipsIdlessRowsAndReadsSelf()

	/**
	 * Without a configured register nothing is read or written.
	 *
	 * @return void
	 */
	public function testWithoutARegisterNothingIsReadOrWritten(): void {
		$this->config['register'] = '';
		$store        = new ScriptedObligationStore();
		$store->rows  = [['case' => 'case-1', 'id' => 'ob-1']];
		$store->found = ['state' => 'open'];
		$store->saved = ['id' => 'ob-1'];
		$service      = $this->service(objectService: $store);

		$this->assertSame([], $service->allFor(caseId: 'case-1'));
		$this->assertSame('', $service->idForSource(source: 'consultation-1'));
		$this->assertSame('', $service->place(obligation: ['case' => 'case-1', 'kind' => 'advice']));
		$this->assertFalse($service->meet(obligationId: 'ob-1'));
		$this->assertSame([], $store->writes);
	}//end testWithoutARegisterNothingIsReadOrWritten()
}//end class
