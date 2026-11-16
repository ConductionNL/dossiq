<?php

/**
 * ReadsRegisterRows — the register rows matching an id.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Support;

use OCA\Dossiq\Kernel\AppIdentity;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\ReadsRegisterRows;
use PHPUnit\Framework\TestCase;

/**
 * The query asked, and no query without OpenRegister or an id.
 *
 * @covers \OCA\Dossiq\Service\Support\ReadsRegisterRows
 */
final class ReadsRegisterRowsTest extends TestCase {

	/**
	 * A class using the trait over settings answering the object service.
	 *
	 * @param object|null $objectService The object service.
	 *
	 * @return object
	 */
	private function subject(?object $objectService): object {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($objectService);

		return new class($settings) {
			use ReadsRegisterRows {
				registerRows as public;
			}

			private const LIMIT = 7;

			/**
			 * Constructor.
			 *
			 * @param SettingsService $settings The settings.
			 */
			public function __construct(private readonly SettingsService $settings) {
			}
		};
	}//end subject()

	/**
	 * The register slug leads the filters and the limit caps the read.
	 *
	 * @return void
	 */
	public function testAsksTheRegister(): void {
		$objectService = new class() {
			/**
			 * The query asked.
			 *
			 * @var array<string, mixed>
			 */
			public array $asked = [];

			/**
			 * The canned rows.
			 *
			 * @param array<string, mixed> $query The query.
			 *
			 * @return array<int, array<string, string>>
			 */
			public function findAll(array $query): array {
				$this->asked = $query;
				return [['id' => 'r1']];
			}
		};

		self::assertSame([['id' => 'r1']], $this->subject($objectService)->registerRows('c1', ['schema' => 's', 'caseId' => 'c1']));
		self::assertSame(
			['filters' => ['register' => AppIdentity::REGISTER_SLUG, 'schema' => 's', 'caseId' => 'c1'], 'limit' => 7],
			$objectService->asked
		);
	}//end testAsksTheRegister()

	/**
	 * Without OpenRegister or an id nothing is read.
	 *
	 * @return void
	 */
	public function testReadsNothingWithoutServiceOrId(): void {
		self::assertSame([], $this->subject(null)->registerRows('c1', ['schema' => 's']));
		self::assertSame([], $this->subject(new \stdClass())->registerRows('', ['schema' => 's']));
	}//end testReadsNothingWithoutServiceOrId()
}//end class
