<?php

/**
 * Dossiq's Dutch schema slugs are renamed in its own registers only.
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

use OCA\Dossiq\Repair\RenameDutchSchemaSlugs;
use OCP\DB\Exception;
use OCP\DB\IResult;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Dossiq\Repair\RenameDutchSchemaSlugs
 * @uses   \OCA\Dossiq\Repair\RenameDutchSchemaSlugDecisions
 * @uses   \OCA\Dossiq\Repair\SlugRenamePlan
 */
class RenameDutchSchemaSlugsTest extends TestCase {

	/**
	 * Every UPDATE the step ran: [sql, params].
	 *
	 * @var list<array{0: string, 1: array}>
	 */
	private array $updates = [];

	/**
	 * Every query the step ran: [sql, params].
	 *
	 * @var list<array{0: string, 1: array}>
	 */
	private array $queries = [];

	/**
	 * What the step reported, prefixed with the level.
	 *
	 * @var list<string>
	 */
	private array $said = [];

	/**
	 * What the step logged, prefixed with the level.
	 *
	 * @var list<string>
	 */
	private array $logged = [];

	/**
	 * A connection answering the register and schema reads from fixtures.
	 *
	 * @param array|null $registers   Register rows, or null to fail the read.
	 * @param array|null $schemas     Schema rows, or null to fail the read.
	 * @param list<string> $failRenames Old slugs whose UPDATE fails.
	 *
	 * @return IDBConnection The connection.
	 */
	private function db(?array $registers, ?array $schemas, array $failRenames=[]): IDBConnection {
		$db = $this->createMock(IDBConnection::class);
		$db->method('executeQuery')->willReturnCallback(
			function (string $sql, array $params) use ($registers, $schemas): IResult {
				$this->queries[] = [$sql, $params];
				$rows = str_contains($sql, 'openregister_registers') === true ? $registers : $schemas;
				if ($rows === null) {
					throw new Exception('read failed');
				}

				$result = $this->createMock(IResult::class);
				$result->method('fetchAll')->willReturn($rows);

				return $result;
			}
		);
		$db->method('executeStatement')->willReturnCallback(
			function (string $sql, array $params) use ($failRenames): int {
				if (in_array($params[1], $failRenames, true) === true) {
					throw new Exception('write failed');
				}

				$this->updates[] = [$sql, $params];
				return 1;
			}
		);

		return $db;
	}//end db()

	/**
	 * The step over a connection, recording its output and log.
	 *
	 * @param IDBConnection $db The connection.
	 *
	 * @return void
	 */
	private function runStep(IDBConnection $db): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(function (string $message, array $context=[]): void {
			$this->logged[] = $message . ' ' . json_encode($context);
		});
		$output = $this->createMock(IOutput::class);
		$output->method('info')->willReturnCallback(function (string $line): void {
			$this->said[] = $line;
		});

		(new RenameDutchSchemaSlugs($db, $logger))->run($output);
	}//end runStep()

	/**
	 * Present Dutch slugs are renamed within the register's schemas, and counted.
	 *
	 * @return void
	 */
	public function testPresentDutchSlugsAreRenamedWithinTheRegistersSchemas(): void {
		$this->runStep(
			$this->db(
				registers: [['schemas' => '[3,"4"]'], ['schemas' => '[4]']],
				schemas: [['slug' => 'bezwaar'], ['slug' => 'voorstel'], ['slug' => 'case']]
			)
		);

		self::assertSame(['dossiq'], $this->queries[0][1]);
		self::assertStringContainsString('WHERE slug IN (?)', $this->queries[0][0]);
		self::assertSame(['3', '4'], $this->queries[1][1]);
		self::assertStringContainsString('WHERE id IN (?,?)', $this->queries[1][0]);

		self::assertCount(2, $this->updates);
		self::assertStringContainsString('SET slug = ? WHERE slug = ? AND id IN (?,?)', $this->updates[0][0]);
		self::assertSame(['objectionProceeding', 'bezwaar', '3', '4'], $this->updates[0][1]);
		self::assertSame(['proposal', 'voorstel', '3', '4'], $this->updates[1][1]);
		self::assertSame(['RenameDutchSchemaSlugs: 2 slug(s) renamed, 0 refused.'], $this->said);
		self::assertSame([], $this->logged);
	}//end testPresentDutchSlugsAreRenamedWithinTheRegistersSchemas()

	/**
	 * A rename onto a slug already present is refused, logged and not written.
	 *
	 * @return void
	 */
	public function testARenameOntoATakenSlugIsRefusedAndLogged(): void {
		$this->runStep(
			$this->db(
				registers: [['schemas' => '[7]']],
				schemas: [['slug' => 'catalogus'], ['slug' => 'zgwCatalogus']]
			)
		);

		self::assertSame([], $this->updates);
		self::assertSame(
			["RenameDutchSchemaSlugs: target slug 'zgwCatalogus' already exists; renaming neither. {\"old\":\"catalogus\"}"],
			$this->logged
		);
		self::assertSame(['RenameDutchSchemaSlugs: 0 slug(s) renamed, 1 refused.'], $this->said);
	}//end testARenameOntoATakenSlugIsRefusedAndLogged()

	/**
	 * Without a dossiq register nothing is read further and nothing written.
	 *
	 * @return void
	 */
	public function testNoRegisterMeansNothingToDo(): void {
		$this->runStep($this->db(registers: [], schemas: [['slug' => 'bezwaar']]));

		self::assertCount(1, $this->queries);
		self::assertSame([], $this->updates);
		self::assertSame(['RenameDutchSchemaSlugs: no Dossiq registers on this install; nothing to do.'], $this->said);
	}//end testNoRegisterMeansNothingToDo()

	/**
	 * An unreadable register table is logged and treated as no register.
	 *
	 * @return void
	 */
	public function testAnUnreadableRegisterTableSkipsTheStep(): void {
		$this->runStep($this->db(registers: null, schemas: []));

		self::assertSame([], $this->updates);
		self::assertSame(
			['RenameDutchSchemaSlugs: could not resolve the registers; skipping. {"exception":"read failed"}'],
			$this->logged
		);
		self::assertSame(['RenameDutchSchemaSlugs: no Dossiq registers on this install; nothing to do.'], $this->said);
	}//end testAnUnreadableRegisterTableSkipsTheStep()

	/**
	 * Unreadable schema slugs rename nothing.
	 *
	 * @return void
	 */
	public function testUnreadableSchemaSlugsRenameNothing(): void {
		$this->runStep($this->db(registers: [['schemas' => '[1]']], schemas: null));

		self::assertSame([], $this->updates);
		self::assertSame(
			['RenameDutchSchemaSlugs: could not read schema slugs; skipping. {"exception":"read failed"}'],
			$this->logged
		);
		self::assertSame(['RenameDutchSchemaSlugs: 0 slug(s) renamed, 0 refused.'], $this->said);
	}//end testUnreadableSchemaSlugsRenameNothing()

	/**
	 * A failed rename is logged and not counted; the others still go ahead.
	 *
	 * @return void
	 */
	public function testAFailedRenameIsLoggedAndNotCounted(): void {
		$this->runStep(
			$this->db(
				registers: [['schemas' => '[1]']],
				schemas: [['slug' => 'kanaal'], ['slug' => 'tussenrapportage']],
				failRenames: ['kanaal']
			)
		);

		self::assertSame([['interimReport', 'tussenrapportage', '1']], array_column($this->updates, 1));
		self::assertSame(
			['RenameDutchSchemaSlugs: slug rename failed. {"old":"kanaal","new":"notificationChannel","exception":"write failed"}'],
			$this->logged
		);
		self::assertSame(['RenameDutchSchemaSlugs: 1 slug(s) renamed, 0 refused.'], $this->said);
	}//end testAFailedRenameIsLoggedAndNotCounted()
}//end class
