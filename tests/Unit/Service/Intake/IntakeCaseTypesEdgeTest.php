<?php

/**
 * The edges of IntakeCaseTypes: a blank setting, a uuid reference, a store
 * that is missing or not configured, a search that throws, and references that
 * are not strings.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Test
 * @package   OCA\Dossiq\Tests\Unit\Service\Intake
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/dossiq
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Intake;

use OCA\Dossiq\Service\Intake\IntakeCaseTypes;
use OCA\Dossiq\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Service\Intake\IntakeCaseTypes
 */
class IntakeCaseTypesEdgeTest extends TestCase {

	private const UUID = '0a1b2c3d-4e5f-4a6b-8c7d-9e0f1a2b3c4d';

	/**
	 * Searches the fake object service received.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $queries = [];

	/**
	 * Log lines as [message, context].
	 *
	 * @var array<int, array{0: string, 1: array<string, mixed>}>
	 */
	private array $logged = [];

	/**
	 * A blank setting falls back to the shipped map.
	 *
	 * @return void
	 */
	public function testABlankSettingMeansTheShippedMap(): void {
		$resolver = $this->resolver(settings: [IntakeCaseTypes::SETTING => '   '], byIdentity: ['bezwaar' => 'uuid-bezwaar', 'klacht-behandeling' => 'uuid-klacht']);

		self::assertSame(['createBezwaar' => 'uuid-bezwaar', 'createKlacht' => 'uuid-klacht'], $resolver->caseTypes());
	}//end testABlankSettingMeansTheShippedMap()

	/**
	 * A uuid reference is used as it is, without a search.
	 *
	 * @return void
	 */
	public function testAUuidReferenceIsUsedWithoutASearch(): void {
		$resolver = $this->resolver(settings: [IntakeCaseTypes::SETTING => '{"createX": " ' . strtoupper(self::UUID) . ' "}'], byIdentity: []);

		self::assertSame(strtoupper(self::UUID), $resolver->caseTypeFor(path: 'createX'));
		self::assertSame([], $this->queries);
		self::assertSame([], $this->logged);
	}//end testAUuidReferenceIsUsedWithoutASearch()

	/**
	 * Without an object service, register or case type schema nothing resolves and nothing is searched.
	 *
	 * @return void
	 */
	public function testAnUnconfiguredStoreResolvesNothing(): void {
		$cases = [
			'no register' => [['register' => ''], true],
			'no schema' => [['case_type_schema' => ''], true],
			'no object service' => [[], false],
		];
		foreach ($cases as $label => [$settings, $withStore]) {
			$this->queries = [];
			$this->logged = [];
			$resolver = $this->resolver(settings: $settings + [IntakeCaseTypes::SETTING => '{"createBezwaar": "bezwaar"}'], byIdentity: ['bezwaar' => 'uuid-bezwaar'], withStore: $withStore);

			self::assertSame('', $resolver->caseTypeFor(path: 'createBezwaar'), $label);
			self::assertSame([], $this->queries, $label);
			self::assertSame('Dossiq intake: no case type answers to the configured reference', $this->logged[0][0] ?? null, $label);
		}
	}//end testAnUnconfiguredStoreResolvesNothing()

	/**
	 * A search that throws is logged with the reference and resolves to nothing, once.
	 *
	 * @return void
	 */
	public function testAThrowingSearchIsLoggedAndResolvesNothing(): void {
		$resolver = $this->resolver(settings: [IntakeCaseTypes::SETTING => '{"createBezwaar": "bezwaar", "other": "bezwaar"}'], byIdentity: [], throws: true);

		self::assertSame([], $resolver->caseTypes());
		self::assertSame(
			['Dossiq intake: the case type could not be looked up', ['reference' => 'bezwaar', 'exception' => 'store down']],
			$this->logged[0]
		);
		self::assertCount(1, $this->queries, 'the failed lookup is remembered for the second path');
		self::assertCount(3, $this->logged, 'one failure, then one empty answer per path');
	}//end testAThrowingSearchIsLoggedAndResolvesNothing()

	/**
	 * References that are not strings are ignored; an unknown path resolves to nothing without logging.
	 *
	 * @return void
	 */
	public function testNonStringReferencesAndUnknownPaths(): void {
		$resolver = $this->resolver(settings: [IntakeCaseTypes::SETTING => '{"a": 5, "b": null, "c": ["x"], "d": "bezwaar"}'], byIdentity: ['bezwaar' => 'uuid-bezwaar']);

		self::assertSame(['d' => 'uuid-bezwaar'], $resolver->caseTypes());
		self::assertSame('', $resolver->caseTypeFor(path: 'a'));
		self::assertSame('', $resolver->caseTypeFor(path: 'nope'));
		self::assertSame([], $this->logged);
	}//end testNonStringReferencesAndUnknownPaths()

	/**
	 * A found row without an `id` is read by its `@self.id`.
	 *
	 * @return void
	 */
	public function testARowIsReadByItsSelfId(): void {
		$resolver = $this->resolver(settings: [IntakeCaseTypes::SETTING => '{"p": "bezwaar"}'], byIdentity: ['bezwaar' => 'self-uuid'], selfOnly: true);

		self::assertSame('self-uuid', $resolver->caseTypeFor(path: 'p'));
	}//end testARowIsReadByItsSelfId()

	/**
	 * A resolver over settings and a fake object service.
	 *
	 * @param array<string, string> $settings   App settings.
	 * @param array<string, string> $byIdentity Identifier to uuid.
	 * @param bool                  $withStore  Whether an object service is available.
	 * @param bool                  $throws     Whether the search throws.
	 * @param bool                  $selfOnly   Whether rows carry their id in `@self` only.
	 *
	 * @return IntakeCaseTypes
	 */
	private function resolver(array $settings, array $byIdentity, bool $withStore = true, bool $throws = false, bool $selfOnly = false): IntakeCaseTypes {
		$settings = array_merge(['register' => '3', 'case_type_schema' => '7'], $settings);
		$settingsService = $this->createMock(SettingsService::class);
		$settingsService->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => ($settings[$key] ?? $default)
		);

		$objects = null;
		if ($withStore === true) {
			$objects = new class ($byIdentity, $this->queries, $throws, $selfOnly) {
				/**
				 * @param array<string, string>            $byIdentity Identifier to uuid.
				 * @param array<int, array<string, mixed>> $queries    Where the queries are recorded.
				 * @param bool                             $throws     Whether the search throws.
				 * @param bool                             $selfOnly   Whether rows carry only `@self.id`.
				 */
				public function __construct(private array $byIdentity, private array &$queries, private bool $throws, private bool $selfOnly) {
				}

				/**
				 * @param array<string, mixed> $query         The query.
				 * @param bool                 $_rbac         Whether RBAC applies.
				 * @param bool                 $_multitenancy Whether multitenancy applies.
				 *
				 * @return array<int, array<string, mixed>> The rows.
				 */
				public function searchObjects(array $query, bool $_rbac = true, bool $_multitenancy = true): array {
					$this->queries[] = $query;
					if ($this->throws === true) {
						throw new RuntimeException('store down');
					}

					$uuid = ($this->byIdentity[(string)($query['identifier'] ?? '')] ?? null);
					if ($uuid === null) {
						return [];
					}

					if ($this->selfOnly === true) {
						return [['@self' => ['id' => $uuid]]];
					}

					return [['id' => $uuid]];
				}
			};
		}

		$settingsService->method('getObjectService')->willReturn($objects);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function (string $message, array $context = []): void {
				$this->logged[] = [$message, $context];
			}
		);

		return new IntakeCaseTypes(settingsService: $settingsService, logger: $logger);
	}//end resolver()
}//end class
