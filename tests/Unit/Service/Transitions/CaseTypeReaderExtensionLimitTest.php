<?php

/**
 * A case type states how often its term may be extended.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/woo-case-screens-and-objections/specs/term-extension-limit/spec.md#requirement-a-case-type-states-how-often-its-term-may-be-extended-req-tel-001
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Transitions;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Transitions\CaseTypeReader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * `maxExtensions` is read as a whole number above zero, or as no limit.
 */
class CaseTypeReaderExtensionLimitTest extends TestCase {

	/**
	 * Stored values and the limit each one means.
	 *
	 * @return array<string, array{0: mixed, 1: int|null}>
	 */
	public static function storedLimits(): array {
		return [
			'one' => [1, 1],
			'two as a string' => ['2', 2],
			'not set' => [null, null],
			'empty' => ['', null],
			'zero is no limit, extensionAllowed says never' => [0, null],
			'negative' => [-1, null],
			'not a number' => ['once', null],
		];
	}//end storedLimits()

	/**
	 * The reader answers the limit the case type states.
	 *
	 * @param mixed    $stored   What the case type stores.
	 * @param int|null $expected The limit, or null for none.
	 *
	 * @return void
	 */
	#[DataProvider('storedLimits')]
	public function testTheLimitIsAWholeNumberAboveZeroOrNone(mixed $stored, ?int $expected): void {
		$objects = new class ($stored) {

			/**
			 * Constructor.
			 *
			 * @param mixed $stored The stored `maxExtensions`.
			 */
			public function __construct(private readonly mixed $stored) {
			}//end __construct()

			/**
			 * The case type row.
			 *
			 * @param string      $id       The row asked for.
			 * @param string|null $register The register.
			 * @param string|null $schema   The schema.
			 *
			 * @return array<string, mixed>
			 */
			public function find(string $id, ?string $register = null, ?string $schema = null): array {
				return ['title' => 'Woo-verzoek', 'extensionAllowed' => true, 'extensionPeriod' => 'P14D', 'maxExtensions' => $this->stored];
			}//end find()
		};

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($objects);
		$settings->method('getConfigValue')->willReturn('x');

		$read = (new CaseTypeReader($settings))->read(caseTypeId: 'ct-1');

		self::assertSame($expected, $read['maxExtensions']);
		self::assertTrue($read['extensionAllowed']);
	}//end testTheLimitIsAWholeNumberAboveZeroOrNone()
}//end class
