<?php

/**
 * SearchesConfiguredSchema — a configured schema's rows, or null.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Support;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesConfiguredSchema;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;

/**
 * Rows, unconfigured and failing searches.
 *
 * @covers \OCA\Dossiq\Service\Support\SearchesConfiguredSchema
 */
final class SearchesConfiguredSchemaTest extends TestCase {

	/**
	 * A class using the trait with a canned search.
	 *
	 * @param object|null                           $objectService The object service.
	 * @param string                                $schema        The schema under `x_schema`.
	 * @param array<int, array<string, mixed>>|null $rows          The search answer, null to throw.
	 *
	 * @return object
	 */
	private function subject(?object $objectService, string $schema, ?array $rows): object {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($objectService);
		$settings->method('getConfigValue')->willReturnMap([['register', '', 'reg'], ['x_schema', '', $schema]]);

		return new class($settings, $rows) {
			use SearchesConfiguredSchema {
				configuredRows as public;
			}

			/**
			 * The searches made, as [register, schema, filters].
			 *
			 * @var array<int, array<int, mixed>>
			 */
			public array $searches = [];

			/**
			 * Constructor.
			 *
			 * @param SettingsService                       $settingsService The settings.
			 * @param array<int, array<string, mixed>>|null $rows            The search answer.
			 */
			public function __construct(private readonly SettingsService $settingsService, private readonly ?array $rows) {
			}

			/**
			 * The canned search.
			 *
			 * @param object               $objectService Unused.
			 * @param string               $register      The register.
			 * @param string               $schema        The schema.
			 * @param array<string, mixed> $filters       The filters.
			 *
			 * @return array<int, array<string, mixed>>
			 */
			private function searchObjectsAsArrays(object $objectService, string $register, string $schema, array $filters): array {
				$this->searches[] = [$register, $schema, $filters];
				if ($this->rows === null) {
					throw new RuntimeException('down');
				}

				return $this->rows;
			}
		};
	}//end subject()

	/**
	 * The configured schema is searched with the filters.
	 *
	 * @return void
	 */
	public function testSearchesTheSchema(): void {
		$subject = $this->subject(new stdClass(), 'sch', [['id' => 'a']]);

		self::assertSame([['id' => 'a']], $subject->configuredRows('x_schema', ['f' => 1]));
		self::assertSame([['reg', 'sch', ['f' => 1]]], $subject->searches);
	}//end testSearchesTheSchema()

	/**
	 * Unconfigured or failing answers null.
	 *
	 * @return void
	 */
	public function testAnswersNullWhenUnreadable(): void {
		self::assertNull($this->subject(null, 'sch', [])->configuredRows('x_schema', []));
		self::assertNull($this->subject(new stdClass(), '', [])->configuredRows('x_schema', []));
		self::assertNull($this->subject(new stdClass(), 'sch', null)->configuredRows('x_schema', []));
	}//end testAnswersNullWhenUnreadable()
}//end class
