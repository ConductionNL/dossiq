<?php

/**
 * MoveDecisionTablesToOpenRegister when configuration is missing, a read fails, a table has no key or OpenRegister refuses one.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Repair
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
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Repair;

use OCA\Dossiq\Kernel\Contracts\SettingsServiceInterface;
use OCA\Dossiq\Kernel\Contracts\StoredDecisionTablesInterface;
use OCA\Dossiq\Repair\MoveDecisionTablesToOpenRegister;
use OCP\Migration\IOutput;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * An ObjectService double serving the old decision table rows, or failing.
 */
class OldDecisionTableObjectService {

	/**
	 * Construct.
	 *
	 * @param array<int, array<string, mixed>> $rows The stored tables.
	 * @param boolean                          $fail Whether a read throws.
	 */
	public function __construct(
		public array $rows=[],
		public bool $fail=false,
	) {
	}//end __construct()

	/**
	 * Mimic ObjectService::searchObjects().
	 *
	 * @param array<string, mixed> $query The query.
	 * @param mixed ...$scope Scope arguments.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function searchObjects(array $query, mixed ...$scope): array {
		if ($this->fail === true) {
			throw new RuntimeException('old register unreadable');
		}

		return $this->rows;
	}//end searchObjects()
}//end class

/**
 * @covers \OCA\Dossiq\Repair\MoveDecisionTablesToOpenRegister
 */
class MoveDecisionTablesToOpenRegisterEdgesTest extends TestCase {

	/**
	 * The new decision-table store.
	 *
	 * @var StoredDecisionTablesInterface&MockObject
	 */
	private StoredDecisionTablesInterface $tables;

	/**
	 * The logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * Set up the collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->tables = $this->createMock(StoredDecisionTablesInterface::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * The step over an object service and config values.
	 *
	 * @param object|null           $objects The object service.
	 * @param array<string, string> $config  The config values.
	 *
	 * @return MoveDecisionTablesToOpenRegister
	 */
	private function step(?object $objects, array $config=['register' => '20', 'decision_table_schema' => '31']): MoveDecisionTablesToOpenRegister {
		$settings = $this->createMock(SettingsServiceInterface::class);
		$settings->method('getObjectService')->willReturn($objects);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key): string => ($config[$key] ?? '')
		);

		return new MoveDecisionTablesToOpenRegister(settingsService: $settings, tables: $this->tables, logger: $this->logger);
	}//end step()

	/**
	 * The step names what it does.
	 *
	 * @return void
	 */
	public function testTheStepNamesWhatItDoes(): void {
		self::assertSame(
			"Move dossiq decision tables into OpenRegister's decision-tables register",
			$this->step(objects: null)->getName()
		);
	}//end testTheStepNamesWhatItDoes()

	/**
	 * Without a decision table schema there is nothing to move and nothing is written.
	 *
	 * @return void
	 */
	public function testWithoutASchemaNothingMoves(): void {
		$this->tables->expects($this->never())->method('save');
		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('info')->with('MoveDecisionTablesToOpenRegister: no dossiq decision tables to move.');

		$this->step(objects: new OldDecisionTableObjectService(rows: [['key' => 'k']]), config: ['register' => '20'])->run($output);
	}//end testWithoutASchemaNothingMoves()

	/**
	 * A failed read of the old tables is logged and treated as nothing to move.
	 *
	 * @return void
	 */
	public function testAFailedReadMovesNothing(): void {
		$this->tables->expects($this->never())->method('save');
		$this->logger->expects($this->once())
			->method('warning')
			->with('MoveDecisionTablesToOpenRegister: the dossiq decision tables could not be read.', ['exception' => 'old register unreadable']);
		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('info')->with('MoveDecisionTablesToOpenRegister: no dossiq decision tables to move.');

		$this->step(objects: new OldDecisionTableObjectService(fail: true))->run($output);
	}//end testAFailedReadMovesNothing()

	/**
	 * A table without a key and a table OpenRegister refuses are both counted as refused and stay.
	 *
	 * @return void
	 */
	public function testKeylessAndRefusedTablesStayAndAreReported(): void {
		$this->tables->method('keyFor')->willReturnCallback(
			static fn (string $key): string => ($key === '' ? '' : 'dossiq.' . $key)
		);
		$this->tables->method('idOf')->willReturn(null);
		$this->tables->expects($this->exactly(2))
			->method('save')
			->willReturnCallback(
				static function (array $table): array {
					if ($table['key'] === 'dossiq.refused') {
						throw new RuntimeException('schema mismatch');
					}

					return $table;
				}
			);

		$warnings = [];
		$this->logger->method('warning')->willReturnCallback(
			static function (string $message, array $context=[]) use (&$warnings): void {
				$warnings[] = [$message, $context];
			}
		);
		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())
			->method('info')
			->with('MoveDecisionTablesToOpenRegister: 1 moved, 0 already in OpenRegister, 0 disabled and left in dossiq, 2 refused.');
		$output->expects($this->once())
			->method('warning')
			->with('MoveDecisionTablesToOpenRegister: 2 table(s) were refused (see the log); they stay in dossiq.');

		$rows = [
			['name' => 'Nameless'],
			['key' => 'refused', 'name' => 'Refused'],
			['key' => 'fine', 'name' => 'Fine'],
		];
		$this->step(objects: new OldDecisionTableObjectService(rows: $rows))->run($output);

		self::assertSame(
			[
				['MoveDecisionTablesToOpenRegister: a table without a key cannot be named; left in dossiq.', ['name' => 'Nameless']],
				["MoveDecisionTablesToOpenRegister: OpenRegister refused a table; left in dossiq.", ['key' => 'dossiq.refused', 'exception' => 'schema mismatch']],
			],
			$warnings
		);
	}//end testKeylessAndRefusedTablesStayAndAreReported()
}//end class
