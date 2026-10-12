<?php

/**
 * FreezeRegistry and FrozenRecordSuccessor unit tests.
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
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/frozen-record/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\FrozenRecord;

use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\DocumentSeries\DocumentSeriesNumberer;
use OCA\Dossiq\Service\FrozenRecord\FreezeRegistry;
use OCA\Dossiq\Service\FrozenRecord\FrozenRecordGuard;
use OCA\Dossiq\Service\FrozenRecord\FrozenRecordSuccessor;
use OCA\Dossiq\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Where the entries come from, and how a successor is prepared.
 *
 * @covers \OCA\Dossiq\Service\FrozenRecord\FreezeRegistry
 * @covers \OCA\Dossiq\Service\FrozenRecord\FrozenRecordSuccessor
 * @uses   \OCA\Dossiq\Service\FrozenRecord\FreezeDefinition
 * @uses   \OCA\Dossiq\Service\FrozenRecord\FrozenRecordGuard
 * @uses   \OCA\Dossiq\Exception\RefusedException
 */
class FreezeRegistryAndSuccessorTest extends TestCase {

	/**
	 * A registry over the given app-config values.
	 *
	 * @param array<string, string> $config App config.
	 * @param LoggerInterface|null $logger The logger.
	 *
	 * @return FreezeRegistry The registry.
	 */
	private function registry(array $config, ?LoggerInterface $logger = null): FreezeRegistry {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getConfigValue')->willReturnCallback(static fn (string $key): string => ($config[$key] ?? ''));

		return new FreezeRegistry(settingsService: $settings, logger: ($logger ?? $this->createMock(LoggerInterface::class)));
	}//end registry()

	/**
	 * An entry covers a record by schema slug, also as the tail of a path.
	 *
	 * @return void
	 */
	public function testFindsEntryBySchema(): void {
		$registry = $this->registry(config: [FreezeRegistry::CONFIG_KEY => (string)json_encode([FrozenRecordFixture::ENTRY])]);

		self::assertSame('fixtureRecord', $registry->forRecord(record: FrozenRecordFixture::record(status: 'draft'))?->schema);
		self::assertNotNull($registry->forRecord(record: ['@self' => ['schema' => 'register/fixtureRecord']]));
		self::assertNull($registry->forRecord(record: ['@self' => ['schema' => 'otherRecord']]));
		self::assertNull($registry->forRecord(record: ['body' => 'no envelope']));
	}//end testFindsEntryBySchema()

	/**
	 * A `config:` schema is resolved through app config.
	 *
	 * @return void
	 */
	public function testResolvesConfigReference(): void {
		$entry = array_merge(FrozenRecordFixture::ENTRY, ['schema' => 'config:fixture_schema']);
		$registry = $this->registry(config: [FreezeRegistry::CONFIG_KEY => (string)json_encode([$entry]), 'fixture_schema' => '42']);

		self::assertNotNull($registry->forRecord(record: ['@self' => ['schema' => '42']]));
		self::assertNull($registry->forRecord(record: ['@self' => ['schema' => 'config:fixture_schema']]));
	}//end testResolvesConfigReference()

	/**
	 * A broken entry is skipped and logged; the valid ones still apply.
	 *
	 * @return void
	 */
	public function testBrokenEntryIsSkippedAndLogged(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())->method('error');
		$registry = $this->registry(
			config: [FreezeRegistry::CONFIG_KEY => (string)json_encode([['schema' => 'broken'], FrozenRecordFixture::ENTRY])],
			logger: $logger
		);

		self::assertCount(1, $registry->definitions());
	}//end testBrokenEntryIsSkippedAndLogged()

	/**
	 * Nothing configured freezes nothing; malformed JSON freezes nothing and says so.
	 *
	 * @return void
	 */
	public function testNothingConfigured(): void {
		self::assertSame([], $this->registry(config: [])->definitions());

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())->method('error');
		self::assertSame([], $this->registry(config: [FreezeRegistry::CONFIG_KEY => '{nope'], logger: $logger)->definitions());
	}//end testNothingConfigured()

	/**
	 * A successor gets the series' next number and points back at its predecessor.
	 *
	 * @return void
	 */
	public function testSuccessorFields(): void {
		$numberer = $this->createMock(DocumentSeriesNumberer::class);
		$numberer->expects(self::once())->method('issue')
			->with('case-1', 'fixture', 'F', null)
			->willReturn('F-2026-000002');
		$successor = new FrozenRecordSuccessor(numberer: $numberer, guard: new FrozenRecordGuard());

		$fields = $successor->successorFields(
			definition: FrozenRecordFixture::definition(),
			predecessor: FrozenRecordFixture::record(status: 'issued'),
			caseId: 'case-1',
		);

		self::assertSame(['reference' => 'F-2026-000002', 'supersedes' => 'rec-1'], $fields);
		self::assertSame(['supersededBy' => 'rec-2'], $successor->predecessorFields(definition: FrozenRecordFixture::definition(), successorId: 'rec-2'));
	}//end testSuccessorFields()

	/**
	 * The refusals: not frozen, already replaced, no successor configured.
	 *
	 * @return void
	 */
	public function testSuccessorRefusals(): void {
		$numberer = $this->createMock(DocumentSeriesNumberer::class);
		$numberer->expects(self::never())->method('issue');
		$successor = new FrozenRecordSuccessor(numberer: $numberer, guard: new FrozenRecordGuard());

		$cases = [
			'record-not-frozen' => [FrozenRecordFixture::definition(), FrozenRecordFixture::record(status: 'draft'), 409],
			'record-already-superseded' => [FrozenRecordFixture::definition(), FrozenRecordFixture::record(status: 'issued', extra: ['supersededBy' => 'rec-0']), 409],
			'record-has-no-successor' => [FrozenRecordFixture::definition(override: ['successor' => null]), FrozenRecordFixture::record(status: 'issued'), 422],
		];

		foreach ($cases as $rule => [$definition, $predecessor, $status]) {
			try {
				$successor->successorFields(definition: $definition, predecessor: $predecessor, caseId: 'case-1');
				self::fail('Not refused: ' . $rule);
			} catch (RefusedException $refusal) {
				self::assertSame($rule, $refusal->getRule());
				self::assertSame($status, $refusal->getStatus());
			}
		}
	}//end testSuccessorRefusals()
}//end class
