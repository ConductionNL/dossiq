<?php

/**
 * PlannedSeriesLedger::occurrencesOf() tests.
 *
 * Pins how the cases a series opened are read: one findAll() on the case
 * schema filtered by the series' handoff source, rows of any shape reduced to
 * `{id, title}`, rows without an id dropped, and an empty answer whenever the
 * store is unconfigured, unavailable or failing, or the follow-up is single.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Flow
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Flow;

use JsonSerializable;
use OCA\Dossiq\Service\Flow\PlannedFollowUpDocument;
use OCA\Dossiq\Service\Flow\PlannedSeriesLedger;
use OCA\Dossiq\Service\SettingsService;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for PlannedSeriesLedger::occurrencesOf().
 *
 * @covers \OCA\Dossiq\Service\Flow\PlannedSeriesLedger
 */
class PlannedSeriesOccurrencesTest extends TestCase {

	/**
	 * The settings bridge the ledger reads the store and config through.
	 *
	 * @var SettingsService&MockObject
	 */
	private SettingsService&MockObject $settings;

	/**
	 * The logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Fresh collaborators per test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->settings = $this->createMock(SettingsService::class);
		$this->logger   = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * The ledger under test.
	 *
	 * @return PlannedSeriesLedger
	 */
	private function ledger(): PlannedSeriesLedger {
		return new PlannedSeriesLedger(
			$this->createMock(ContainerInterface::class),
			$this->settings,
			$this->createMock(PlannedFollowUpDocument::class),
			$this->createMock(IAppConfig::class),
			$this->logger
		);
	}//end ledger()

	/**
	 * Configure the register and case schema the store is read on.
	 *
	 * @param string $register The register id.
	 * @param string $schema   The case schema id.
	 *
	 * @return void
	 */
	private function configure(string $register = 'reg-1', string $schema = 'schema-case'): void {
		$this->settings->method('getConfigValue')->willReturnMap(
			[
				['register', '', $register],
				['case_schema', '', $schema],
			]
		);
	}//end configure()

	/**
	 * An object service whose findAll() records its query and answers $answer.
	 *
	 * @param mixed $answer What findAll() returns, or a Throwable it throws.
	 *
	 * @return object The service; its `queries` property holds each query.
	 */
	private static function store(mixed $answer): object {
		return new class ($answer) {
			/**
			 * The queries findAll() received.
			 *
			 * @var array<int, array<string, mixed>>
			 */
			public array $queries = [];

			/**
			 * Constructor.
			 *
			 * @param mixed $answer The answer.
			 */
			public function __construct(private mixed $answer) {
			}//end __construct()

			/**
			 * Record the query and answer.
			 *
			 * @param array<string, mixed> $config The query.
			 *
			 * @return mixed The answer.
			 */
			public function findAll(array $config): mixed {
				$this->queries[] = $config;
				if ($this->answer instanceof \Throwable) {
					throw $this->answer;
				}

				return $this->answer;
			}//end findAll()
		};
	}//end store()

	/**
	 * A single follow-up has no occurrences and the store is never asked.
	 *
	 * @return void
	 */
	public function testASingleFollowUpReadsNothing(): void {
		$this->settings->expects($this->never())->method('getObjectService');

		self::assertSame([], $this->ledger()->occurrencesOf('flow-1', true));
	}//end testASingleFollowUpReadsNothing()

	/**
	 * A series is one findAll() on the case schema by its handoff source, one page long.
	 *
	 * @return void
	 */
	public function testASeriesIsReadByItsHandoffSource(): void {
		$store = self::store(['results' => [['id' => 'case-a', 'title' => 'Controle 2026']]]);
		$this->settings->method('getObjectService')->willReturn($store);
		$this->configure();

		$occurrences = $this->ledger()->occurrencesOf('flow-1', false);

		self::assertSame([['id' => 'case-a', 'title' => 'Controle 2026']], $occurrences);
		self::assertSame(
			[
				[
					'filters' => [
						'register' => 'reg-1',
						'schema' => 'schema-case',
						'handoffSource' => 'planned-series:flow-1',
					],
					'limit' => 200,
				],
			],
			$store->queries
		);
	}//end testASeriesIsReadByItsHandoffSource()

	/**
	 * Arrays, serialisable entities and plain objects are all read; an id is
	 * trimmed, a missing title is empty and a row without an id is dropped.
	 *
	 * @return void
	 */
	public function testRowsOfEveryShapeAreReducedToIdAndTitle(): void {
		$entity = new class implements JsonSerializable {
			/**
			 * The payload.
			 *
			 * @return array<string, string>
			 */
			public function jsonSerialize(): array {
				return ['id' => 'case-b', 'title' => 'Controle 2027'];
			}//end jsonSerialize()
		};
		$plain        = new \stdClass();
		$plain->id    = 'case-c';
		$plain->title = 'Controle 2028';

		$this->settings->method('getObjectService')->willReturn(
			self::store(
				[
					['id' => '  case-a  '],
					$entity,
					$plain,
					['id' => '   ', 'title' => 'blank id'],
					['title' => 'no id'],
				]
			)
		);
		$this->configure();

		self::assertSame(
			[
				['id' => 'case-a', 'title' => ''],
				['id' => 'case-b', 'title' => 'Controle 2027'],
				['id' => 'case-c', 'title' => 'Controle 2028'],
			],
			$this->ledger()->occurrencesOf('flow-1', false)
		);
	}//end testRowsOfEveryShapeAreReducedToIdAndTitle()

	/**
	 * An entity whose jsonSerialize() is not an array is read as its properties.
	 *
	 * @return void
	 */
	public function testANonArraySerialisationFallsBackToTheObjectsProperties(): void {
		$row = new class {
			/**
			 * The id.
			 *
			 * @var string
			 */
			public string $id = 'case-d';

			/**
			 * A scalar serialisation.
			 *
			 * @return string
			 */
			public function jsonSerialize(): string {
				return 'not-an-array';
			}//end jsonSerialize()
		};
		$this->settings->method('getObjectService')->willReturn(self::store([$row]));
		$this->configure();

		self::assertSame([['id' => 'case-d', 'title' => '']], $this->ledger()->occurrencesOf('flow-1', false));
	}//end testANonArraySerialisationFallsBackToTheObjectsProperties()

	/**
	 * Without a store, a register or a case schema there is nothing to read.
	 *
	 * @return void
	 */
	public function testAnUnconfiguredStoreAnswersNothing(): void {
		foreach ([['', 'schema-case', true], ['reg-1', '', true], ['reg-1', 'schema-case', false]] as [$register, $schema, $withStore]) {
			$this->setUp();
			$store = self::store([['id' => 'case-a']]);
			$this->settings->method('getObjectService')->willReturn($withStore === true ? $store : null);
			$this->configure($register, $schema);

			self::assertSame([], $this->ledger()->occurrencesOf('flow-1', false));
			self::assertSame([], $store->queries);
		}
	}//end testAnUnconfiguredStoreAnswersNothing()

	/**
	 * A failing store answers nothing and logs which series could not be read.
	 *
	 * @return void
	 */
	public function testAFailingStoreAnswersNothingAndWarns(): void {
		$this->settings->method('getObjectService')->willReturn(self::store(new RuntimeException('store down')));
		$this->configure();
		$this->logger->expects($this->once())->method('warning')->with(
			'PlannedSeriesLedger: could not read the cases a series created',
			['flow' => 'flow-1', 'exception' => 'store down']
		);

		self::assertSame([], $this->ledger()->occurrencesOf('flow-1', false));
	}//end testAFailingStoreAnswersNothingAndWarns()
}//end class
