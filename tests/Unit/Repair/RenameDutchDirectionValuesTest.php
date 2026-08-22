<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Tests for the Dutch -> English `direction` value migration.
 *
 * The arms that matter are the EXEMPTION arms. This step rewrites the string
 * `intern`, and `intern` is also a value of the statutory ZGW
 * `vertrouwelijkheidaanduiding` enum. What keeps the two apart is that the
 * update is scoped to the `direction` COLUMN — so the tests pin the column
 * scope and the exact value set, not just the happy-path mapping.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Repair
 * @author   Conduction Development Team <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Repair;

use OCA\Dossiq\Repair\RenameDutchDirectionValues;
use OCA\Dossiq\Service\ZgwRulesBase;
use OCP\DB\Exception as DbException;
use OCP\DB\IPreparedStatement;
use OCP\DB\IResult;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;

/**
 * Unit tests for RenameDutchDirectionValues.
 *
 * @covers \OCA\Dossiq\Repair\RenameDutchDirectionValues
 */
class RenameDutchDirectionValuesTest extends TestCase {

	/**
	 * Build the step with mocked collaborators.
	 *
	 * @return RenameDutchDirectionValues
	 */
	private function step(): RenameDutchDirectionValues {
		return new RenameDutchDirectionValues(
			$this->createMock(IDBConnection::class),
			$this->createMock(LoggerInterface::class)
		);

	}//end step()

	/**
	 * Read a private constant.
	 *
	 * @param string $name The constant name.
	 *
	 * @return mixed
	 */
	private function constant(string $name) {
		return (new ReflectionClass(RenameDutchDirectionValues::class))->getConstant($name);
	}//end constant()

	/**
	 * Call a private method.
	 *
	 * @param string $name The method name.
	 * @param array<int, mixed> $args The arguments.
	 *
	 * @return mixed
	 */
	private function call(string $name, array $args) {
		$method = (new ReflectionClass(RenameDutchDirectionValues::class))->getMethod($name);
		$method->setAccessible(true);
		return $method->invokeArgs($this->step(), $args);
	}//end call()

	/**
	 * THE BEHAVIOURAL ARM — a procest shard table with a `direction` column is
	 * updated once per Dutch value, and only ever on that column.
	 *
	 * Everything else in this file inspects constants. This one drives run()
	 * end to end against mocked SQL and asserts the statements it issues, which
	 * is the only place the actual UPDATE is exercised.
	 *
	 * @return void
	 */
	public function testItUpdatesEachDutchValueOnADossiqShardTable(): void {
		$registers = $this->createMock(IResult::class);
		$registers->method('fetchAll')->willReturn([17]);

		// information_schema.tables, then the per-table column probe.
		$tables = $this->createMock(IPreparedStatement::class);
		$tables->method('execute')->willReturn($this->createMock(IResult::class));
		$tables->method('fetch')->willReturnOnConsecutiveCalls(
			['table_name' => 'oc_openregister_table_17_928'],
			// Another app's table — must be filtered out before any UPDATE.
			['table_name' => 'oc_openregister_table_16_41'],
			false,
			// The column probe for the one in-scope table.
			['column_name' => 'direction'],
			false
		);

		$db = $this->createMock(IDBConnection::class);
		$db->method('executeQuery')->willReturn($registers);
		$db->method('prepare')->willReturn($tables);
		$db->method('getDatabasePlatform')->willReturn(
			new class {
				/**
				 * @param string $identifier Identifier.
				 *
				 * @return string
				 */
				public function quoteSingleIdentifier(string $identifier): string {
					return '"' . $identifier . '"';
				}
			}
		);

		$statements = [];
		$db->method('executeStatement')->willReturnCallback(
			static function (string $sql, array $params) use (&$statements): int {
				$statements[] = [$sql, $params];
				return 1;
			}
		);

		$step = new RenameDutchDirectionValues($db, $this->createMock(LoggerInterface::class));
		$step->run($this->createMock(IOutput::class));

		// One UPDATE per mapped value, and no more — the out-of-scope table
		// contributed nothing.
		$this->assertCount(3, $statements);

		foreach ($statements as [$sql, $params]) {
			$this->assertStringContainsString('oc_openregister_table_17_928', $sql);
			$this->assertStringContainsString('"direction"', $sql);
			$this->assertStringNotContainsString('vertrouwelijkheidaanduiding', $sql);
			$this->assertStringNotContainsString('oc_openregister_table_16_41', $sql);
		}

		$pairs = array_map(static fn (array $s): array => $s[1], $statements);
		$this->assertEqualsCanonicalizing(
			[['inbound', 'inkomend'], ['outbound', 'uitgaand'], ['internal', 'intern']],
			$pairs
		);

	}//end testItUpdatesEachDutchValueOnADossiqShardTable()

	/**
	 * The step names itself for `occ maintenance:repair`.
	 *
	 * @return void
	 */
	public function testItNamesItself(): void {
		$this->assertStringContainsString('direction', $this->step()->getName());

	}//end testItNamesItself()

	/**
	 * An install with no procest register does nothing and SAYS so.
	 *
	 * A repair step that prints nothing on a clean install is indistinguishable
	 * from one that never ran.
	 *
	 * @return void
	 */
	public function testAnInstallWithoutDossiqRegistersReportsInsteadOfSilentlyPassing(): void {
		$result = $this->createMock(IResult::class);
		$result->method('fetchAll')->willReturn([]);

		$db = $this->createMock(IDBConnection::class);
		$db->method('executeQuery')->willReturn($result);
		// Nothing may be written when there is nothing in scope.
		$db->expects($this->never())->method('executeStatement');

		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())
			->method('info')
			->with($this->stringContains('nothing to do'));

		$step = new RenameDutchDirectionValues($db, $this->createMock(LoggerInterface::class));
		$step->run($output);

	}//end testAnInstallWithoutDossiqRegistersReportsInsteadOfSilentlyPassing()

	/**
	 * A database error while resolving registers is logged and skipped, not
	 * thrown — one broken install must not abort the whole repair run.
	 *
	 * @return void
	 */
	public function testAFailedRegisterLookupIsLoggedAndSkipped(): void {
		$db = $this->createMock(IDBConnection::class);
		$db->method('executeQuery')->willThrowException(new DbException('boom'));
		$db->expects($this->never())->method('executeStatement');

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning');

		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('info');

		$step = new RenameDutchDirectionValues($db, $logger);
		$step->run($output);

	}//end testAFailedRegisterLookupIsLoggedAndSkipped()

	/**
	 * A table whose columns cannot be inspected is skipped rather than updated
	 * blind.
	 *
	 * @return void
	 */
	public function testAnUninspectableTableIsSkippedRatherThanUpdatedBlind(): void {
		$db = $this->createMock(IDBConnection::class);
		$db->method('prepare')->willThrowException(new \RuntimeException('no information_schema'));

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning');

		$step = new RenameDutchDirectionValues($db, $logger);

		$method = (new ReflectionClass(RenameDutchDirectionValues::class))->getMethod('hasDirectionColumn');
		$method->setAccessible(true);

		$this->assertFalse($method->invokeArgs($step, ['oc_openregister_table_17_928']));

	}//end testAnUninspectableTableIsSkippedRatherThanUpdatedBlind()

	/**
	 * The three Dutch values map to their English equivalents.
	 *
	 * @return void
	 */
	public function testTheValueMapCoversTheThreeDutchDirections(): void {
		$this->assertSame(
			['inkomend' => 'inbound', 'uitgaand' => 'outbound', 'intern' => 'internal'],
			$this->constant('VALUE_MAP')
		);

	}//end testTheValueMapCoversTheThreeDutchDirections()

	/**
	 * THE EXEMPTION ARM — the step touches only the `direction` column.
	 *
	 * If this ever widens, the same `intern` rewrite lands on
	 * `vertrouwelijkheidaanduiding` and corrupts a statutory ZGW value on every
	 * row that carries one.
	 *
	 * @return void
	 */
	public function testItTouchesOnlyTheDirectionColumn(): void {
		$this->assertSame('direction', $this->constant('COLUMN'));

	}//end testItTouchesOnlyTheDirectionColumn()

	/**
	 * THE EXEMPTION ARM, from the other side — every statutory
	 * vertrouwelijkheidaanduiding except `intern` is absent from the map, and
	 * `intern` is present ONLY because it is a direction too.
	 *
	 * This is what makes the column scope load-bearing rather than incidental:
	 * the two vocabularies genuinely overlap on one word.
	 *
	 * @return void
	 */
	public function testTheStatutoryConfidentialityValuesAreNotRewritten(): void {
		$map = $this->constant('VALUE_MAP');
		$levels = (new ReflectionClass(ZgwRulesBase::class))->getConstant('VERTROUWELIJKHEID_LEVELS');

		$overlap = array_intersect(array_keys($map), array_keys($levels));

		// One word overlaps, and it is the reason the column scope exists.
		$this->assertSame(['intern'], array_values($overlap));

		foreach (array_keys($levels) as $level) {
			if ($level === 'intern') {
				continue;
			}

			$this->assertArrayNotHasKey(
				$level,
				$map,
				sprintf('statutory ZGW confidentiality value "%s" must never be rewritten', $level)
			);
		}

	}//end testTheStatutoryConfidentialityValuesAreNotRewritten()

	/**
	 * Both procest registers are in scope — resolving one slug would migrate
	 * half the rows and report success.
	 *
	 * @return void
	 */
	public function testBothDossiqRegistersAreInScope(): void {
		$this->assertSame('procest', $this->constant('REGISTER_SLUG_PREFIX'));

		$markers = ['openregister_table_17_', 'openregister_table_2424_'];

		$this->assertTrue($this->call('isShardOf', ['oc_openregister_table_17_928', $markers]));
		$this->assertTrue($this->call('isShardOf', ['oc_openregister_table_2424_928', $markers]));

	}//end testBothDossiqRegistersAreInScope()

	/**
	 * Another app's shard table is out of scope.
	 *
	 * Measured: 105 shard tables on the dev install carry a `direction` column,
	 * across pipelinq, decidesk, shillinq, scholiq and openconnector. Their
	 * vocabularies are their own.
	 *
	 * @return void
	 */
	public function testAnotherAppsShardTableIsNotMatched(): void {
		$markers = ['openregister_table_17_', 'openregister_table_2424_'];

		// 16 = pipelinq, 264 = shillinq, 65 = openconnector.
		$this->assertFalse($this->call('isShardOf', ['oc_openregister_table_16_41', $markers]));
		$this->assertFalse($this->call('isShardOf', ['oc_openregister_table_264_41', $markers]));
		$this->assertFalse($this->call('isShardOf', ['oc_openregister_table_65_5114', $markers]));

	}//end testAnotherAppsShardTableIsNotMatched()

	/**
	 * A register id that merely starts with an in-scope id is not a match.
	 *
	 * `openregister_table_17_` must not match register 170 or 1700.
	 *
	 * @return void
	 */
	public function testALongerRegisterIdIsNotMatched(): void {
		$markers = ['openregister_table_17_'];

		$this->assertFalse($this->call('isShardOf', ['oc_openregister_table_170_928', $markers]));
		$this->assertFalse($this->call('isShardOf', ['oc_openregister_table_1700_928', $markers]));

	}//end testALongerRegisterIdIsNotMatched()

	/**
	 * Values outside the map are left alone.
	 *
	 * portaalBericht stores `citizen_to_handler` in its `direction` column —
	 * a different vocabulary that happens to share the column name.
	 *
	 * @return void
	 */
	public function testAnUnrecognisedDirectionVocabularyIsNotRewritten(): void {
		$map = $this->constant('VALUE_MAP');

		$this->assertArrayNotHasKey('citizen_to_handler', $map);
		$this->assertArrayNotHasKey('handler_to_citizen', $map);

	}//end testAnUnrecognisedDirectionVocabularyIsNotRewritten()

	/**
	 * The step is idempotent by construction: no English target is also a
	 * source, so a second run maps nothing.
	 *
	 * @return void
	 */
	public function testTheMigrationIsIdempotentByConstruction(): void {
		$map = $this->constant('VALUE_MAP');

		foreach ($map as $old => $new) {
			$this->assertArrayNotHasKey(
				$new,
				$map,
				sprintf('"%s" is both a target and a source, so a re-run would rewrite it again', $new)
			);
			$this->assertNotSame($old, $new);
		}

	}//end testTheMigrationIsIdempotentByConstruction()

}//end class
