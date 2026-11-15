<?php

/**
 * LhsDecisionTableLookup Tests
 *
 * The lookup sits in the enforcement path: what it answers becomes the
 * intervention recorded against a citizen or a business. So the questions worth
 * asking are not "does it read a table" but "when does it decline to answer",
 * because every decline hands the question back to the matrix and a decline it
 * should not have made is invisible in the result.
 *
 * The table is stored in an in-memory stand-in for OpenRegister's
 * DecisionTableStore that evaluates with OpenRegister's own evaluator, a
 * verbatim copy kept under tests/Stubs. That matters: a hand-written fake
 * evaluator would let these tests agree with whatever this class happens to
 * send it, which is the shape of a fake that cannot fail.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Vth
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/lhs-matrix-is-a-decision-table/specs/lhs-decision-table/spec.md
 * @spec openspec/changes/decision-tables-live-in-openregister/specs/decision-tables/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Vth;

use OCA\Dossiq\Service\Dmn\StoredDecisionTables;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Vth\LhsDecisionTableLookup;
use OCA\Dossiq\Tests\Support\InMemoryDecisionTableStore;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Service\Vth\LhsDecisionTableLookup
 * @covers \OCA\Dossiq\Service\Dmn\StoredDecisionTables
 */
class LhsDecisionTableLookupTest extends TestCase {

	/**
	 * The table projected from matrix version 3, as stored in OpenRegister.
	 *
	 * @return array<string, mixed> The table.
	 */
	private function table(): array {
		return [
			'key' => 'dossiq.lhs-matrix-3',
			'name' => 'LHS 2024',
			'hitPolicy' => 'UNIQUE',
			'inputs' => [
				['name' => 'severity', 'type' => 'string'],
				['name' => 'behaviour', 'type' => 'string'],
				['name' => 'actorType', 'type' => 'string'],
			],
			'outputs' => [['name' => 'intervention', 'type' => 'string']],
			'rules' => [
				['id' => 'a', 'inputEntries' => ['gering', 'goedwillend', 'burger'], 'outputEntries' => ['warning']],
				['id' => 'b', 'inputEntries' => ['ernstig', 'calculerend', 'burger'], 'outputEntries' => ['prosecute']],
			],
		];
	}//end table()

	/**
	 * The lookup over a container that answers with the given store.
	 *
	 * @param object|null $store The store, or null for an OpenRegister without one.
	 * @param LoggerInterface|null $logger The logger.
	 *
	 * @return LhsDecisionTableLookup The lookup.
	 */
	private function lookup(?object $store, ?LoggerInterface $logger = null): LhsDecisionTableLookup {
		$container = $this->createMock(ContainerInterface::class);
		if ($store === null) {
			$container->method('get')->willThrowException(new RuntimeException('no store'));
		} else {
			$container->method('get')->willReturn($store);
		}

		return new LhsDecisionTableLookup(
			new StoredDecisionTables($this->createMock(SettingsService::class), $container),
			($logger ?? $this->createMock(LoggerInterface::class))
		);
	}//end lookup()

	/**
	 * The stored table answers the triple, named by its matrix version.
	 *
	 * @return void
	 */
	public function testTheStoredTableAnswers(): void {
		$store = new InMemoryDecisionTableStore(['dossiq.lhs-matrix-3' => $this->table()]);

		self::assertSame('prosecute', $this->lookup($store)->intervention('3', 'ernstig', 'calculerend', 'burger'));
		self::assertSame(['dossiq.lhs-matrix-3'], $store->asked);
	}//end testTheStoredTableAnswers()

	/**
	 * Another version's table is not used: the reference names the version.
	 *
	 * @return void
	 */
	public function testAnotherVersionsTableIsNotUsed(): void {
		$store = new InMemoryDecisionTableStore(['dossiq.lhs-matrix-3' => $this->table()]);

		self::assertNull($this->lookup($store)->intervention('4', 'ernstig', 'calculerend', 'burger'));
	}//end testAnotherVersionsTableIsNotUsed()

	/**
	 * No table for the version declines quietly (the ordinary state before the projection runs).
	 *
	 * @return void
	 */
	public function testNoProjectionDeclinesWithoutAWarning(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method('warning');

		self::assertNull($this->lookup(new InMemoryDecisionTableStore(), $logger)->intervention('3', 'gering', 'goedwillend', 'burger'));
	}//end testNoProjectionDeclinesWithoutAWarning()

	/**
	 * A table that exists and matches no rule declines, and says so at warning.
	 *
	 * @return void
	 */
	public function testAnUnmatchedTripleDeclinesWithAWarning(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning');
		$store = new InMemoryDecisionTableStore(['dossiq.lhs-matrix-3' => $this->table()]);

		self::assertNull($this->lookup($store, $logger)->intervention('3', 'gering', 'calculerend', 'overheid'));
	}//end testAnUnmatchedTripleDeclinesWithAWarning()

	/**
	 * An OpenRegister without the store declines.
	 *
	 * @return void
	 */
	public function testAnAbsentStoreDeclines(): void {
		self::assertNull($this->lookup(null)->intervention('3', 'ernstig', 'calculerend', 'burger'));
	}//end testAnAbsentStoreDeclines()

	/**
	 * The stored key of a version, and an empty version reads as 1.
	 *
	 * @return void
	 */
	public function testTheReferenceOfAVersion(): void {
		self::assertSame('dossiq.lhs-matrix-3', $this->lookup(null)->referenceFor('3'));
		self::assertSame('dossiq.lhs-matrix-1', $this->lookup(null)->referenceFor(''));
	}//end testTheReferenceOfAVersion()
}//end class
