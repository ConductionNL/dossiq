<?php

/**
 * Which slug CaseTypeSlugResolver reads off a stored case type, and when it
 * answers none.
 *
 * Pins the order of the three places a slug lives (identifier, then the
 * metadata slug, then the body slug, each trimmed), every empty answer (no
 * OpenRegister, no register or schema, no such case type, a failed read with
 * its warning) and that a resolved uuid is read only once.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Service\CaseTypeSlugResolver;
use OCA\Dossiq\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Service\CaseTypeSlugResolver
 */
class CaseTypeSlugResolverReadTest extends TestCase {

	private const UUID = '0b8d6c1e-2f3a-4b5c-8d9e-0f1a2b3c4d5e';

	/**
	 * How many times the store was asked.
	 *
	 * @var integer
	 */
	public int $reads = 0;

	/**
	 * The warnings logged.
	 *
	 * @var array<int, array<int, mixed>>
	 */
	private array $warnings = [];

	/**
	 * The three places, in their order, trimmed.
	 *
	 * @return void
	 */
	public function testTheSlugIsReadFromItsThreePlacesInOrder(): void {
		self::assertSame('woo', $this->resolver(['identifier' => ' woo ', '@self' => ['slug' => 'meta'], 'slug' => 'body'])->toSlug(self::UUID));
		self::assertSame('meta', $this->resolver(['identifier' => ' ', '@self' => ['slug' => ' meta '], 'slug' => 'body'])->toSlug(self::UUID));
		self::assertSame('body', $this->resolver(['@self' => ['slug' => ''], 'slug' => ' body '])->toSlug(self::UUID));
		self::assertSame('body', $this->resolver(['@self' => 'not-a-map', 'slug' => 'body'])->toSlug(self::UUID));
		self::assertSame('', $this->resolver([])->toSlug(self::UUID));

		$resolver = $this->resolver(['identifier' => 'once']);
		$this->reads = 0;
		$resolver->toSlug(self::UUID);
		$resolver->toSlug(self::UUID);
		self::assertSame(1, $this->reads);
		self::assertSame([], $this->warnings);
	}//end testTheSlugIsReadFromItsThreePlacesInOrder()

	/**
	 * Every way to read no slug, and the one that warns.
	 *
	 * @return void
	 */
	public function testEveryWayToReadNoSlug(): void {
		self::assertSame('', $this->resolver(null)->toSlug(self::UUID));
		self::assertSame('', $this->resolver(['identifier' => 'x'], ['no-or' => '1'])->toSlug(self::UUID));
		self::assertSame('', $this->resolver(['identifier' => 'x'], ['register' => ''])->toSlug(self::UUID));
		self::assertSame('', $this->resolver(['identifier' => 'x'], ['case_type_schema' => ''])->toSlug(self::UUID));
		self::assertSame([], $this->warnings);

		self::assertSame('', $this->resolver(['throw' => true])->toSlug(self::UUID));
		self::assertSame(
			[['Dossiq: could not read the case type behind a case, so its statutory term cannot be resolved', ['caseType' => self::UUID, 'error' => 'store down']]],
			$this->warnings
		);
	}//end testEveryWayToReadNoSlug()

	/**
	 * A resolver over a store holding this case type (null: none).
	 *
	 * @param array<string, mixed>|null $caseType The stored case type; `throw` makes the read fail.
	 * @param array<string, string>     $settings Overrides of the settings.
	 *
	 * @return CaseTypeSlugResolver
	 */
	private function resolver(?array $caseType, array $settings = []): CaseTypeSlugResolver {
		$store = new class($this, $caseType) {
			/**
			 * @param CaseTypeSlugResolverReadTest $test     The test that counts the reads.
			 * @param array<string, mixed>|null    $caseType The case type.
			 */
			public function __construct(private readonly CaseTypeSlugResolverReadTest $test, private readonly ?array $caseType) {
			}

			/**
			 * @param string     $id       The id.
			 * @param int|string $register The register.
			 * @param int|string $schema   The schema.
			 *
			 * @return array<string, mixed>|null
			 */
			public function find(string $id, int|string $register, int|string $schema): ?array {
				$this->test->reads++;
				if (($this->caseType['throw'] ?? false) === true) {
					throw new RuntimeException('store down');
				}

				return $this->caseType;
			}
		};

		$values = array_merge(['register' => 'dossiq', 'case_type_schema' => 'caseType'], $settings);
		$settingsService = $this->createMock(SettingsService::class);
		$settingsService->method('getObjectService')->willReturn(isset($values['no-or']) === true ? null : $store);
		$settingsService->method('getConfigValue')->willReturnCallback(static fn (string $key, string $default = ''): string => ($values[$key] ?? $default));

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function (string $message, array $context = []): void {
				$this->warnings[] = [$message, $context];
			}
		);

		return new CaseTypeSlugResolver($settingsService, $logger);
	}//end resolver()
}//end class
