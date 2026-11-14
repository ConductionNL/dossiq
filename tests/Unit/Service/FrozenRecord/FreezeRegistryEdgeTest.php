<?php

/**
 * FreezeRegistry edge tests: entries are read once per request, a schema
 * matches only as a whole path segment, and an unresolved reference or an
 * envelope without a usable schema matches nothing.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\FrozenRecord
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/frozen-record/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\FrozenRecord;

use OCA\Dossiq\Service\FrozenRecord\FreezeRegistry;
use OCA\Dossiq\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Which record an entry covers, and how often the configuration is read.
 *
 * @covers \OCA\Dossiq\Service\FrozenRecord\FreezeRegistry
 * @uses   \OCA\Dossiq\Service\FrozenRecord\FreezeDefinition
 */
class FreezeRegistryEdgeTest extends TestCase {

	/**
	 * Keys read from app config, in order.
	 *
	 * @var array<int, string>
	 */
	private array $reads = [];

	/**
	 * A registry over the given app-config values that records every read.
	 *
	 * @param array<string, string> $config App config.
	 * @param LoggerInterface|null  $logger The logger.
	 *
	 * @return FreezeRegistry The registry.
	 */
	private function registry(array $config, ?LoggerInterface $logger = null): FreezeRegistry {
		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getConfigValue')->willReturnCallback(
			function (string $key) use ($config): string {
				$this->reads[] = $key;
				return ($config[$key] ?? '');
			}
		);

		return new FreezeRegistry(settingsService: $settings, logger: ($logger ?? $this->createMock(originalClassName: LoggerInterface::class)));
	}//end registry()

	/**
	 * The entries as stored app config.
	 *
	 * @param array<int, array<string, mixed>> $entries The entries.
	 *
	 * @return string The JSON.
	 */
	private static function stored(array $entries): string {
		return (string)json_encode(value: $entries);
	}//end stored()

	/**
	 * The entries are parsed once; later lookups reuse them.
	 *
	 * @return void
	 */
	public function testTheEntriesAreReadOncePerRequest(): void {
		$registry = $this->registry(config: [FreezeRegistry::CONFIG_KEY => self::stored(entries: [FrozenRecordFixture::ENTRY])]);

		$first = $registry->definitions();
		$registry->forRecord(record: ['@self' => ['schema' => 'fixtureRecord']]);
		$registry->forRecord(record: ['@self' => ['schema' => 'other']]);

		$this->assertSame(expected: $first, actual: $registry->definitions());
		$this->assertSame(expected: [FreezeRegistry::CONFIG_KEY], actual: $this->reads);
	}//end testTheEntriesAreReadOncePerRequest()

	/**
	 * A schema matches exactly or as the last path segment, never as the tail
	 * of a longer name.
	 *
	 * @return void
	 */
	public function testASchemaMatchesOnlyAsAWholeSegment(): void {
		$registry = $this->registry(config: [FreezeRegistry::CONFIG_KEY => self::stored(entries: [FrozenRecordFixture::ENTRY])]);

		$this->assertNotNull(actual: $registry->forRecord(record: ['@self' => ['schema' => '/api/registers/5/fixtureRecord']]));
		$this->assertNull(actual: $registry->forRecord(record: ['@self' => ['schema' => 'myfixtureRecord']]));
		$this->assertNull(actual: $registry->forRecord(record: ['@self' => ['schema' => 'fixtureRecord/1']]));
	}//end testASchemaMatchesOnlyAsAWholeSegment()

	/**
	 * The first entry that covers a record wins.
	 *
	 * @return void
	 */
	public function testTheFirstCoveringEntryWins(): void {
		$second   = (['frozenStatuses' => ['closed']] + FrozenRecordFixture::ENTRY);
		$registry = $this->registry(config: [FreezeRegistry::CONFIG_KEY => self::stored(entries: [FrozenRecordFixture::ENTRY, $second])]);

		$this->assertSame(
			expected: ['issued', 'archived'],
			actual: $registry->forRecord(record: ['@self' => ['schema' => 'fixtureRecord']])?->frozenStatuses
		);
	}//end testTheFirstCoveringEntryWins()

	/**
	 * A `config:` reference that resolves to nothing covers no record, and
	 * the entries after it still apply.
	 *
	 * @return void
	 */
	public function testAnUnresolvedReferenceCoversNothing(): void {
		$unresolved = (['schema' => 'config:missing_schema'] + FrozenRecordFixture::ENTRY);
		$registry   = $this->registry(
			config: [FreezeRegistry::CONFIG_KEY => self::stored(entries: [$unresolved, FrozenRecordFixture::ENTRY]), 'missing_schema' => '   ']
		);

		$this->assertNull(actual: $registry->forRecord(record: ['@self' => ['schema' => '/']]));
		$this->assertSame(
			expected: 'fixtureRecord',
			actual: $registry->forRecord(record: ['@self' => ['schema' => 'fixtureRecord']])?->schema
		);
		$this->assertContains(needle: 'missing_schema', haystack: $this->reads);
	}//end testAnUnresolvedReferenceCoversNothing()

	/**
	 * An envelope whose schema is missing, empty, or not a scalar covers no
	 * record and reads no configuration; a numeric schema is matched as text.
	 *
	 * @return void
	 */
	public function testOnlyAScalarSchemaIsLookedUp(): void {
		$entry    = (['schema' => '42'] + FrozenRecordFixture::ENTRY);
		$registry = $this->registry(config: [FreezeRegistry::CONFIG_KEY => self::stored(entries: [$entry])]);

		$this->assertNull(actual: $registry->forRecord(record: ['@self' => 'not an envelope']));
		$this->assertNull(actual: $registry->forRecord(record: ['@self' => ['schema' => ['42']]]));
		$this->assertNull(actual: $registry->forRecord(record: ['@self' => ['schema' => '']]));
		$this->assertSame(expected: [], actual: $this->reads);

		$this->assertNotNull(actual: $registry->forRecord(record: ['@self' => ['schema' => 42]]));
	}//end testOnlyAScalarSchemaIsLookedUp()

	/**
	 * A skipped entry is logged with its position and the reason it is invalid.
	 *
	 * @return void
	 */
	public function testASkippedEntryIsLoggedWithItsIndexAndReason(): void {
		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->expects($this->once())
			->method('error')
			->with(
				'Dossiq frozen records: a configured entry is invalid and was skipped',
				['index' => 1, 'reason' => 'A freeze entry must name the schema it guards.']
			);

		$registry = $this->registry(
			config: [FreezeRegistry::CONFIG_KEY => self::stored(entries: [FrozenRecordFixture::ENTRY, ['frozenStatuses' => ['issued']]])],
			logger: $logger
		);

		$this->assertCount(expectedCount: 1, haystack: $registry->definitions());
	}//end testASkippedEntryIsLoggedWithItsIndexAndReason()
}//end class
