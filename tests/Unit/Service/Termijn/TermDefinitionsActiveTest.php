<?php

/**
 * Which term definitions are active for a case type, and the planned clock a
 * case gets from its own lead time or a planned definition.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\Termijn
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Termijn;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Termijn\TermDefinitions;
use OCA\Dossiq\Service\Termijn\WorkingDayRoll;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for TermDefinitions::allActiveFor() and TermDefinitions::plannedClock().
 *
 * @covers \OCA\Dossiq\Service\Termijn\TermDefinitions
 * @uses   \OCA\Dossiq\Service\SettingsService
 */
class TermDefinitionsActiveTest extends TestCase {

	/**
	 * Warnings logged, as [message, context].
	 *
	 * @var list<array{0: string, 1: array<string, mixed>}>
	 */
	private array $warnings = [];

	/**
	 * Definitions over an object service and configuration.
	 *
	 * @param object|null           $objectService The object service, or null when OpenRegister is absent.
	 * @param array<string, string> $config        Configuration values by key.
	 *
	 * @return TermDefinitions The definitions.
	 */
	private function definitions(?object $objectService, array $config): TermDefinitions {
		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getObjectService')->willReturn($objectService);
		$settings->method('getConfigValue')->willReturnCallback(
			static function (string $key) use ($config): string {
				return ($config[$key] ?? '');
			}
		);

		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function (string $message, array $context = []): void {
				$this->warnings[] = [$message, $context];
			}
		);

		return new TermDefinitions(settingsService: $settings, logger: $logger);
	}//end definitions()

	/**
	 * Without the object service, a register or a schema, there are no definitions and nothing is searched.
	 *
	 * @return void
	 */
	public function testIncompleteConfigurationHasNoDefinitions(): void {
		$objects = new RecordingObjectService(rows: [['validFrom' => '2020-01-01']]);
		$full    = ['register' => '5', 'termijn_definitie_schema' => '9'];

		$this->assertSame(expected: [], actual: $this->definitions(objectService: null, config: $full)->allActiveFor(caseType: 'x'));
		$this->assertSame(expected: [], actual: $this->definitions(objectService: $objects, config: ['register' => '5'])->allActiveFor(caseType: 'x'));
		$noRegister = $this->definitions(objectService: $objects, config: ['termijn_definitie_schema' => '9']);
		$this->assertSame(expected: [], actual: $noRegister->allActiveFor(caseType: 'x'));
		$this->assertSame(expected: [], actual: $objects->searches);
	}//end testIncompleteConfigurationHasNoDefinitions()

	/**
	 * The search filters on the case type, with register and schema in the metadata block.
	 *
	 * @return void
	 */
	public function testTheSearchFiltersOnTheCaseType(): void {
		$objects = new RecordingObjectService(rows: []);

		$this->definitions(objectService: $objects, config: ['register' => '5', 'termijn_definitie_schema' => '9'])->allActiveFor(caseType: 'bezwaar');

		$this->assertSame(
			expected: [['caseType' => 'bezwaar', '@self' => ['register' => 5, 'schema' => 9]]],
			actual: $objects->searches
		);
	}//end testTheSearchFiltersOnTheCaseType()

	/**
	 * Only definitions valid today are kept, newest validity start first.
	 *
	 * @return void
	 */
	public function testOnlyCurrentDefinitionsAreKeptNewestFirst(): void {
		$objects = new RecordingObjectService(
			rows: [
				['id' => 'open-ended', 'validFrom' => '2020-01-01'],
				['id' => 'expired', 'validFrom' => '2019-01-01', 'validUntil' => '2021-01-01'],
				['id' => 'no-start'],
				['id' => 'future', 'validFrom' => '2999-01-01'],
				['id' => 'bounded', 'validFrom' => '2024-05-01', 'validUntil' => '2999-12-31'],
			]
		);

		$active = $this->definitions(objectService: $objects, config: ['register' => '5', 'termijn_definitie_schema' => '9'])
			->allActiveFor(caseType: 'bezwaar');

		$this->assertSame(expected: ['bounded', 'open-ended', 'no-start'], actual: array_column($active, 'id'));
	}//end testOnlyCurrentDefinitionsAreKeptNewestFirst()

	/**
	 * A failing search is no definitions, with a warning naming the case type.
	 *
	 * @return void
	 */
	public function testAFailingSearchIsNoDefinitions(): void {
		$objects = new RecordingObjectService(rows: [], fails: true);

		$active = $this->definitions(objectService: $objects, config: ['register' => 'zaken', 'termijn_definitie_schema' => 'termijn'])
			->allActiveFor(caseType: 'bezwaar');

		$this->assertSame(expected: [], actual: $active);
		$this->assertSame(
			expected: [['TermDefinitions.allActiveFor lookup failed', ['caseType' => 'bezwaar', 'error' => 'search failed']]],
			actual: $this->warnings
		);
	}//end testAFailingSearchIsNoDefinitions()

	/**
	 * The case type's own lead time wins and counts working days; without one and without a definition there is no clock.
	 *
	 * @return void
	 */
	public function testTheDeclaredLeadTimeWins(): void {
		$definition = ['id' => 'def-1', 'standardDurationDays' => 20, 'countingMode' => 'calendarDays'];

		$this->assertSame(
			expected: ['days' => 8, 'mode' => WorkingDayRoll::MODE_WORKING_DAYS, 'extra' => []],
			actual: TermDefinitions::plannedClock(declaredDays: 8, definition: $definition)
		);
		$this->assertSame(
			expected: ['days' => 0, 'mode' => WorkingDayRoll::MODE_WORKING_DAYS, 'extra' => []],
			actual: TermDefinitions::plannedClock(declaredDays: 0, definition: null)
		);
	}//end testTheDeclaredLeadTimeWins()

	/**
	 * Without a declared lead time the planned definition sets days, mode and the definition it came from.
	 *
	 * @return void
	 */
	public function testAPlannedDefinitionFillsIn(): void {
		$this->assertSame(
			expected: ['days' => 20, 'mode' => WorkingDayRoll::MODE_CALENDAR_DAYS, 'extra' => ['deadlineDefinition' => 'def-1']],
			actual: TermDefinitions::plannedClock(declaredDays: 0, definition: ['id' => ' def-1 ', 'standardDurationDays' => '20'])
		);
		$this->assertSame(
			expected: ['days' => 5, 'mode' => WorkingDayRoll::MODE_WORKING_DAYS, 'extra' => []],
			actual: TermDefinitions::plannedClock(declaredDays: 0, definition: ['id' => '  ', 'standardDurationDays' => 5, 'countingMode' => 'workingDays'])
		);
		$this->assertSame(
			expected: ['days' => 0, 'mode' => WorkingDayRoll::MODE_CALENDAR_DAYS, 'extra' => []],
			actual: TermDefinitions::plannedClock(declaredDays: 0, definition: [])
		);
	}//end testAPlannedDefinitionFillsIn()
}//end class

/**
 * An object service that answers a fixed set of rows and records each search.
 */
class RecordingObjectService {

	/**
	 * The queries and filters searched, in order.
	 *
	 * @var list<array<string, mixed>>
	 */
	public array $searches = [];

	/**
	 * Script the service.
	 *
	 * @param list<array<string, mixed>> $rows  The rows every search answers.
	 * @param bool                       $fails Whether every search throws.
	 */
	public function __construct(
		private readonly array $rows,
		private readonly bool $fails = false,
	) {
	}//end __construct()

	/**
	 * Search by numeric register and schema.
	 *
	 * @param array<string, mixed> $query The query.
	 *
	 * @return list<array<string, mixed>> The rows.
	 */
	public function searchObjects(array $query): array {
		if ($this->fails === true) {
			throw new RuntimeException(message: 'search failed');
		}

		$this->searches[] = $query;

		return $this->rows;
	}//end searchObjects()

	/**
	 * Search by register and schema slug.
	 *
	 * @param string               $register The register slug.
	 * @param string               $schema   The schema slug.
	 * @param array<string, mixed> $filters  The filters.
	 *
	 * @return list<array<string, mixed>> The rows.
	 */
	public function searchObjectsBySlug(string $register, string $schema, array $filters): array {
		if ($this->fails === true) {
			throw new RuntimeException(message: 'search failed');
		}

		$this->searches[] = $filters;

		return $this->rows;
	}//end searchObjectsBySlug()
}//end class
