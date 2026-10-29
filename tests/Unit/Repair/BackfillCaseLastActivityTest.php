<?php

/**
 * The lastActivityAt backfill: which cases it dates, from what, and that it runs once.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/case-last-activity-at/specs/case-last-activity-at/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Repair;

use DateTimeImmutable;
use OCA\Dossiq\Repair\BackfillCaseLastActivity;
use OCA\Dossiq\Service\SettingsService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for the lastActivityAt backfill.
 */
final class BackfillCaseLastActivityTest extends TestCase {

	/**
	 * The app config the step reads and writes, by key.
	 *
	 * @var array<string, string>
	 */
	private array $config = [];

	/**
	 * A store that answers the step's calls and keeps what it patches.
	 *
	 * @param array<string, array<string, mixed>> $cases  Cases by id.
	 * @param array<int, string>                  $refuse Case ids whose write fails.
	 *
	 * @return object The fake.
	 */
	private function store(array $cases, array $refuse = []): object {
		return new class($cases, $refuse) {
			/**
			 * @var array<int, array{id: string, data: array<string, mixed>}>
			 */
			public array $patches = [];

			/**
			 * @param array<string, array<string, mixed>> $rows   Cases by id.
			 * @param array<int, string>                  $refuse Case ids whose write fails.
			 */
			public function __construct(public array $rows, private array $refuse) {
			}

			/**
			 * @param callable $operation The operation.
			 *
			 * @return mixed
			 */
			public function runAsSystem(callable $operation): mixed {
				return $operation();
			}

			/**
			 * @param array<string, mixed> $query         The query.
			 * @param bool                 $_rbac         Ignored.
			 * @param bool                 $_multitenancy Ignored.
			 *
			 * @return array<int, array<string, mixed>>
			 */
			public function searchObjects(array $query, bool $_rbac = true, bool $_multitenancy = true): array {
				return array_slice(array_values($this->rows), (int)($query['_offset'] ?? 0), (int)($query['_limit'] ?? 100));
			}

			/**
			 * @param string               $objectId The id.
			 * @param array<string, mixed> $data     The changes.
			 * @param int|string           $register The register.
			 * @param int|string           $schema   The schema.
			 *
			 * @return array<string, mixed>
			 *
			 * @throws RuntimeException When the case is one that refuses.
			 */
			public function patchObject(string $objectId, array $data, int|string $register, int|string $schema): array {
				if (in_array($objectId, $this->refuse, true) === true) {
					throw new RuntimeException('refused');
				}

				$this->patches[] = ['id' => $objectId, 'data' => $data];
				$this->rows[$objectId] = array_merge($this->rows[$objectId], $data);

				return $this->rows[$objectId];
			}
		};
	}//end store()

	/**
	 * Run the step over a store and return what it reported.
	 *
	 * @param object $store The fake store.
	 *
	 * @return array<int, string> The lines written to the upgrade output.
	 */
	private function runOver(object $store): array {
		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getObjectService')->willReturn($store);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => [
				'register' => '23',
				'case_schema' => '5',
			][$key] ?? $default
		);

		$appConfig = $this->createMock(originalClassName: IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => ($this->config[$key] ?? $default)
		);
		$appConfig->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				$this->config[$key] = $value;
				return true;
			}
		);

		$time = $this->createMock(originalClassName: ITimeFactory::class);
		$time->method('now')->willReturn(new DateTimeImmutable('2026-10-29T12:00:00+00:00'));

		$lines = [];
		$output = $this->createMock(originalClassName: IOutput::class);
		$output->method('info')->willReturnCallback(
			static function (string $line) use (&$lines): void {
				$lines[] = $line;
			}
		);

		$step = new BackfillCaseLastActivity(
			settingsService: $settings,
			appConfig: $appConfig,
			time: $time,
			logger: $this->createMock(originalClassName: LoggerInterface::class)
		);
		$step->run($output);

		return $lines;
	}//end runOver()

	/**
	 * A case without lastActivityAt gets the newest activity date it carries,
	 * else its creation date; a case with one is untouched.
	 *
	 * @return void
	 */
	public function testACaseWithoutADateGetsItsNewestActivityAndACaseWithOneIsUntouched(): void {
		$store = $this->store(
			cases: [
				'c-moved' => [
					'id' => 'c-moved',
					'currentStatusEnteredAt' => '2026-09-01T10:00:00+00:00',
					'firstResponseAt' => '2026-09-03T08:30:00+02:00',
					'@self' => ['id' => 'c-moved', 'created' => '2026-08-01T09:00:00+00:00', 'updated' => '2026-10-28T02:00:00+00:00'],
				],
				'c-bare' => [
					'id' => 'c-bare',
					'@self' => ['id' => 'c-bare', 'created' => '2026-08-15T09:00:00+00:00', 'updated' => '2026-10-28T02:00:00+00:00'],
				],
				'c-future' => [
					'id' => 'c-future',
					'waitingSince' => '2027-01-01T00:00:00+00:00',
					'@self' => ['id' => 'c-future', 'created' => '2026-08-20T09:00:00+00:00'],
				],
				'c-dated' => [
					'id' => 'c-dated',
					'lastActivityAt' => '2026-10-20T10:00:00+00:00',
					'currentStatusEnteredAt' => '2026-10-25T10:00:00+00:00',
					'@self' => ['id' => 'c-dated', 'created' => '2026-08-01T09:00:00+00:00'],
				],
			]
		);

		$lines = $this->runOver(store: $store);

		$this->assertSame(
			expected: [
				['id' => 'c-moved', 'data' => ['lastActivityAt' => '2026-09-03T06:30:00+00:00']],
				['id' => 'c-bare', 'data' => ['lastActivityAt' => '2026-08-15T09:00:00+00:00']],
				['id' => 'c-future', 'data' => ['lastActivityAt' => '2026-08-20T09:00:00+00:00']],
			],
			actual: $store->patches
		);
		$this->assertSame(expected: '2026-10-20T10:00:00+00:00', actual: $store->rows['c-dated']['lastActivityAt']);
		$this->assertSame(expected: ['lastActivityAt backfill: 3 dated, 0 failed, 1 already dated.'], actual: $lines);
	}//end testACaseWithoutADateGetsItsNewestActivityAndACaseWithOneIsUntouched()

	/**
	 * After a run without failures the step does not run again.
	 *
	 * @return void
	 */
	public function testItRunsOnce(): void {
		$store = $this->store(cases: ['c-bare' => ['id' => 'c-bare', '@self' => ['id' => 'c-bare', 'created' => '2026-08-15T09:00:00+00:00']]]);
		$this->runOver(store: $store);
		$store->rows['c-new'] = ['id' => 'c-new', '@self' => ['id' => 'c-new', 'created' => '2026-10-01T09:00:00+00:00']];

		$this->assertSame(expected: [], actual: $this->runOver(store: $store));
		$this->assertCount(expectedCount: 1, haystack: $store->patches);
	}//end testItRunsOnce()

	/**
	 * A case whose write fails keeps the step open for the next upgrade.
	 *
	 * @return void
	 */
	public function testAFailedCaseIsTriedAgainNextTime(): void {
		$cases = ['c-bare' => ['id' => 'c-bare', '@self' => ['id' => 'c-bare', 'created' => '2026-08-15T09:00:00+00:00']]];

		$this->assertSame(
			expected: ['lastActivityAt backfill: 0 dated, 1 failed, 0 already dated.'],
			actual: $this->runOver(store: $this->store(cases: $cases, refuse: ['c-bare']))
		);

		$store = $this->store(cases: $cases);
		$this->runOver(store: $store);
		$this->assertCount(expectedCount: 1, haystack: $store->patches);
	}//end testAFailedCaseIsTriedAgainNextTime()
}//end class
