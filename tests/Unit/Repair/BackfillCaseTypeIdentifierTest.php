<?php

/**
 * The caseTypeIdentifier backfill: which cases it writes, and what one bad row costs.
 *
 * The store here is a fake that keeps what it is asked to patch. It does NOT
 * compute anything, which OpenRegister does on a real save. So these tests
 * say what the step ASKS for and how it counts; that OpenRegister accepts the
 * write is checked on a live instance, and the PR says so.
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
 * @spec openspec/changes/woo-five-steps/specs/case-type-version-chain/spec.md#requirement-a-case-carries-the-identifier-its-case-type-chain-shares
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Repair;

use OCA\Dossiq\Repair\BackfillCaseTypeIdentifier;
use OCA\Dossiq\Service\SettingsService;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the caseTypeIdentifier backfill: existing cases get the
 * identifier their case type's version chain shares.
 */
final class BackfillCaseTypeIdentifierTest extends TestCase {
	private const WOO_V1 = 'aaaaaaaa-0000-4000-a000-000000000001';

	private const WOO_V2 = 'aaaaaaaa-0000-4000-a000-000000000002';

	private const UNNAMED = 'aaaaaaaa-0000-4000-a000-000000000003';

	/**
	 * A store that answers the step's calls and keeps what it patches.
	 *
	 * @param array<string, array<string, mixed>> $cases Cases by id.
	 *
	 * @return object The fake.
	 */
	private function store(array $cases): object {
		$caseTypes = [
			self::WOO_V1 => ['id' => self::WOO_V1, 'title' => 'Woo-verzoek', 'identifier' => 'woo-verzoek', 'version' => 1],
			self::WOO_V2 => ['id' => self::WOO_V2, 'title' => 'Woo-verzoek', 'identifier' => 'woo-verzoek', 'version' => 2],
			self::UNNAMED => ['id' => self::UNNAMED, 'title' => 'Zonder identifier'],
		];

		return new class(['5' => $cases, '7' => $caseTypes]) {
			/**
			 * @var array<int, array{schema: string, id: string, data: array<string, mixed>}>
			 */
			public array $patches = [];

			/**
			 * @var int How often the operation was run in system context.
			 */
			public int $systemRuns = 0;

			/**
			 * @param array<string, array<string, array<string, mixed>>> $rows Rows by schema, then id.
			 */
			public function __construct(public array $rows) {
			}

			/**
			 * @param callable $operation The operation.
			 *
			 * @return mixed
			 */
			public function runAsSystem(callable $operation): mixed {
				$this->systemRuns++;

				return $operation();
			}

			/**
			 * @param array<string, mixed> $query The query.
			 * @param bool                 $_rbac Ignored.
			 * @param bool                 $_multitenancy Ignored.
			 *
			 * @return array<int, array<string, mixed>>
			 */
			public function searchObjects(array $query, bool $_rbac = true, bool $_multitenancy = true): array {
				$all = array_values($this->rows[(string)$query['@self']['schema']] ?? []);

				return array_slice($all, (int)($query['_offset'] ?? 0), (int)($query['_limit'] ?? 100));
			}

			/**
			 * @param string               $objectId The id.
			 * @param array<string, mixed> $data The changes.
			 * @param int|string           $register The register.
			 * @param int|string           $schema The schema.
			 *
			 * @return array<string, mixed>
			 */
			public function patchObject(string $objectId, array $data, int|string $register, int|string $schema): array {
				$this->patches[] = ['schema' => (string)$schema, 'id' => $objectId, 'data' => $data];
				$this->rows[(string)$schema][$objectId] = array_merge($this->rows[(string)$schema][$objectId], $data);

				return $this->rows[(string)$schema][$objectId];
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
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($store);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => [
				'register' => '23',
				'case_schema' => '5',
				'case_type_schema' => '7',
			][$key] ?? $default
		);

		$lines = [];
		$output = $this->createMock(IOutput::class);
		$output->method('info')->willReturnCallback(
			static function (string $line) use (&$lines): void {
				$lines[] = $line;
			}
		);

		(new BackfillCaseTypeIdentifier($settings, $this->createMock(LoggerInterface::class)))->run($output);

		return $lines;
	}//end runOver()

	/**
	 * Cases on either Woo version get the shared identifier; a case already
	 * right, one on a type without an identifier and one without a type are left.
	 *
	 * @return void
	 */
	public function testEveryVersionOfAChainGetsTheSharedIdentifier(): void {
		$store = $this->store(
			cases: [
				'c-v1' => ['id' => 'c-v1', 'caseType' => self::WOO_V1],
				'c-v2' => ['id' => 'c-v2', 'caseType' => self::WOO_V2],
				'c-right' => ['id' => 'c-right', 'caseType' => self::WOO_V2, 'caseTypeIdentifier' => 'woo-verzoek'],
				'c-unnamed' => ['id' => 'c-unnamed', 'caseType' => self::UNNAMED],
				'c-none' => ['id' => 'c-none'],
			]
		);

		$lines = $this->runOver(store: $store);

		$this->assertSame(
			[
				['schema' => '5', 'id' => 'c-v1', 'data' => ['caseTypeIdentifier' => 'woo-verzoek']],
				['schema' => '5', 'id' => 'c-v2', 'data' => ['caseTypeIdentifier' => 'woo-verzoek']],
			],
			$store->patches
		);
		$this->assertSame(1, $store->systemRuns);
		$this->assertSame(
			['caseTypeIdentifier backfill: 2 filled, 0 written but not confirmed, 0 failed, 3 already right or without an identifier.'],
			$lines
		);
	}//end testEveryVersionOfAChainGetsTheSharedIdentifier()

	/**
	 * Without OpenRegister the step says so and does nothing.
	 *
	 * @return void
	 */
	public function testWithoutOpenRegisterItSkips(): void {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn(null);
		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('info')
			->with('OpenRegister unavailable, skipping the caseTypeIdentifier backfill.');

		(new BackfillCaseTypeIdentifier($settings, $this->createMock(LoggerInterface::class)))->run($output);
	}//end testWithoutOpenRegisterItSkips()
}//end class
