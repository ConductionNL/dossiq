<?php

/**
 * Golden test for the three Dutch-to-English shard repairs.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Repair;

use OCA\Dossiq\Repair\RenameDutchColumns;
use OCA\Dossiq\Repair\RenameDutchDeadlineColumns;
use OCA\Dossiq\Repair\RenameDutchDirectionValues;
use OCA\Dossiq\Tests\Support\MatchesGoldenFiles;
use OCP\DB\Exception;
use OCP\DB\IPreparedStatement;
use OCP\DB\IResult;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Every query, statement, log line and output line of each repair, per world.
 *
 * @covers \OCA\Dossiq\Repair\RenameDutchColumns
 * @covers \OCA\Dossiq\Repair\RenameDutchDeadlineColumns
 * @covers \OCA\Dossiq\Repair\RenameDutchDirectionValues
 * @covers \OCA\Dossiq\Repair\Support\ReadsRegisterShards
 * @covers \OCA\Dossiq\Repair\Support\ListsOpenRegisterTables
 *
 * @spec openspec/specs/case-management/spec.md
 */
class RenameDutchRepairsGoldenTest extends TestCase {
	use MatchesGoldenFiles;

	/**
	 * The calls, in order.
	 *
	 * @var list<array<string, mixed>>
	 */
	public array $log = [];

	/**
	 * The repairs and the worlds they run in.
	 *
	 * @return array<string, array{0: class-string, 1: array<string, mixed>}>
	 */
	public static function worlds(): array {
		$tables = ['oc_openregister_table_17_85', 'oc_openregister_table_17_86', 'oc_openregister_table_17_85_backup', 'oc_openregister_table_4_9', ''];
		$columns = [
			'oc_openregister_table_17_85' => ['aangevraagd_op', 'requested_on', 'onderwerp', 'omschrijving', 'beschrijving', 'direction'],
			'oc_openregister_table_17_86' => ['aangevraagd_bedrag', 'aanvraag_datum', 'onderwerp', 'name', 'direction'],
			'oc_openregister_table_17_85_backup' => ['aangevraagd_op', 'direction'],
			'oc_openregister_table_4_9' => ['aangevraagd_op', 'direction'],
		];
		$world = ['ids' => [17], 'tables' => $tables, 'columns' => $columns, 'rows' => 2];
		$out = [];
		foreach ([RenameDutchColumns::class, RenameDutchDeadlineColumns::class, RenameDutchDirectionValues::class] as $repair) {
			$short = substr($repair, (strrpos($repair, '\\') + 1));
			$out[$short . ' migrates'] = [$repair, $world];
			$out[$short . ' with no registers'] = [$repair, ['ids' => []] + $world];
			$out[$short . ' when the registers cannot be read'] = [$repair, ['idsThrow' => true] + $world];
			$out[$short . ' when the tables cannot be listed'] = [$repair, ['tablesThrow' => true] + $world];
			$out[$short . ' with no shard tables'] = [$repair, ['tables' => ['oc_other']] + $world];
			$out[$short . ' when a table cannot be inspected'] = [$repair, ['columnsThrow' => 'oc_openregister_table_17_86'] + $world];
			$out[$short . ' when a statement fails'] = [$repair, ['statementThrows' => 'oc_openregister_table_17_85'] + $world];
			$out[$short . ' when nothing changes'] = [$repair, ['rows' => 0] + $world];
			$ambiguous = $world;
			$ambiguous['columns']['oc_openregister_table_17_85'][] = 'aanvraag_datum';
			$ambiguous['columns']['oc_openregister_table_17_85'][] = 'verzoek_datum';
			$out[$short . ' with an ambiguous pair'] = [$repair, $ambiguous];
		}

		return $out;
	}//end worlds()

	/**
	 * The calls and the output match the recording.
	 *
	 * @param class-string         $repair The repair step.
	 * @param array<string, mixed> $world  The database.
	 *
	 * @dataProvider worlds
	 *
	 * @return void
	 */
	public function testTheRepairMatchesTheRecording(string $repair, array $world): void {
		$output = $this->createMock(originalClassName: IOutput::class);
		$output->method('info')->willReturnCallback(
			function (string $message): void {
				$this->log[] = ['output' => $message];
			}
		);

		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function (string $message, array $context = []): void {
				$this->log[] = ['warning' => $message, 'context' => $context];
			}
		);

		(new $repair($this->database(world: $world), $logger))->run($output);

		$name = trim(strtolower((string)preg_replace('/[^a-z]+/i', '-', $this->dataName())), '-');
		$this->assertMatchesGolden(name: 'rename-dutch-repairs/' . $name, value: $this->log);
	}//end testTheRepairMatchesTheRecording()

	/**
	 * Each step names itself.
	 *
	 * @return void
	 */
	public function testEachStepNamesItself(): void {
		$names = [];
		foreach ([RenameDutchColumns::class, RenameDutchDeadlineColumns::class, RenameDutchDirectionValues::class] as $repair) {
			$names[] = (new $repair($this->database(world: []), $this->createMock(originalClassName: LoggerInterface::class)))->getName();
		}

		$this->assertMatchesGolden(name: 'rename-dutch-repairs/names', value: $names);
	}//end testEachStepNamesItself()

	/**
	 * A database that answers from the world and records every call.
	 *
	 * @param array<string, mixed> $world The world.
	 *
	 * @return IDBConnection
	 */
	private function database(array $world): IDBConnection {
		$db = $this->createMock(originalClassName: IDBConnection::class);
		$db->method('executeQuery')->willReturnCallback(
			function (string $sql, array $params = []) use ($world): IResult {
				$this->log[] = ['executeQuery' => $sql, 'params' => $params];
				if (($world['idsThrow'] ?? false) === true) {
					throw new Exception('registers unreadable');
				}

				$result = $this->createMock(originalClassName: IResult::class);
				$result->method('fetchAll')->willReturn($world['ids']);
				return $result;
			}
		);
		$db->method('prepare')->willReturnCallback(fn (string $sql): IPreparedStatement => $this->statement(sql: $sql, world: $world));
		$db->method('executeStatement')->willReturnCallback(
			function (string $sql, array $params = []) use ($world): int {
				$this->log[] = ['executeStatement' => $sql, 'params' => $params];
				if (isset($world['statementThrows']) === true && str_contains($sql, $world['statementThrows']) === true) {
					throw new Exception('statement refused');
				}

				return $world['rows'];
			}
		);

		$platform = new class {
			/**
			 * Quote an identifier.
			 *
			 * @param string $identifier The identifier.
			 *
			 * @return string
			 */
			public function quoteSingleIdentifier(string $identifier): string {
				return '"' . $identifier . '"';
			}
		};
		$db->method('getDatabasePlatform')->willReturn($platform);

		return $db;
	}//end database()

	/**
	 * A prepared statement over the world's tables and columns.
	 *
	 * @param string               $sql   The statement.
	 * @param array<string, mixed> $world The world.
	 *
	 * @return IPreparedStatement
	 */
	private function statement(string $sql, array $world): IPreparedStatement {
		$this->log[] = ['prepare' => $sql];
		$bound = [];
		$rows = null;
		$statement = $this->createMock(originalClassName: IPreparedStatement::class);
		$statement->method('bindValue')->willReturnCallback(
			function (mixed $param, mixed $value) use (&$bound): bool {
				$this->log[] = ['bindValue' => [$param, $value]];
				$bound[$param] = $value;
				return true;
			}
		);
		$statement->method('execute')->willReturnCallback(
			function () use ($sql, $world, &$bound, &$rows): IResult {
				$this->log[] = ['execute' => $bound];
				$rows = $this->rowsFor(sql: $sql, world: $world + ['bound' => $bound]);
				return $this->createMock(originalClassName: IResult::class);
			}
		);
		$statement->method('fetch')->willReturnCallback(
			function () use (&$rows): mixed {
				if ($rows === null || $rows === []) {
					return false;
				}

				return array_shift($rows);
			}
		);

		return $statement;
	}//end statement()

	/**
	 * The rows a statement finds, or an exception when the world says it fails.
	 *
	 * @param string               $sql   The statement.
	 * @param array<string, mixed> $world The world, with the bound values.
	 *
	 * @return list<array<string, string>>
	 */
	private function rowsFor(string $sql, array $world): array {
		$bound = $world['bound'];
		if (str_contains($sql, 'information_schema.tables') === true) {
			if (($world['tablesThrow'] ?? false) === true) {
				throw new Exception('tables unreadable');
			}

			return array_map(static fn (string $name): array => ['table_name' => $name], $world['tables']);
		}

		$table = (string)($bound['table'] ?? '');
		if (($world['columnsThrow'] ?? '') === $table) {
			throw new Exception('columns unreadable');
		}

		$columns = ($world['columns'][$table] ?? []);
		if (isset($bound['column']) === true) {
			$columns = array_values(array_intersect($columns, [$bound['column']]));
		}

		return array_map(static fn (string $name): array => ['column_name' => $name], $columns);
	}//end rowsFor()
}//end class
