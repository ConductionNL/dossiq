<?php

/**
 * ReadsFinalStatusIds — the ids of the final status types.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Support;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\ReadsFinalStatusIds;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;

/**
 * Final status ids with and without a schema, rows or a working search.
 *
 * @covers \OCA\Dossiq\Service\Support\ReadsFinalStatusIds
 */
final class ReadsFinalStatusIdsTest extends TestCase {

	/**
	 * A class using the trait with a canned search answer.
	 *
	 * @param string                                  $schema The configured status type schema.
	 * @param array<int, array<string, mixed>>|null   $rows   The search answer, null to fail.
	 *
	 * @return object
	 */
	private function subject(string $schema, ?array $rows): object {
		$settingsService = $this->createMock(SettingsService::class);
		$settingsService->method('getConfigValue')->willReturnMap([['status_type_schema', '', $schema]]);

		return new class($settingsService, $rows) {
			use ReadsFinalStatusIds;

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
			 * @param object $objectService Unused.
			 * @param string $register      Unused.
			 * @param string $schema        Unused.
			 *
			 * @return array<int, array<string, mixed>>
			 */
			private function searchObjectsAsArrays(object $objectService, string $register, string $schema): array {
				if ($this->rows === null) {
					throw new RuntimeException('down');
				}

				return $this->rows;
			}

			/**
			 * The trait's answer.
			 *
			 * @return array<int, string>
			 */
			public function ids(): array {
				return $this->finalStatusIds(objectService: new stdClass(), register: 'r');
			}
		};
	}//end subject()

	/**
	 * Only final rows count, by id or else uuid, in the accepted spellings.
	 *
	 * @return void
	 */
	public function testKeepsTheFinalRows(): void {
		$rows = [
			['id' => 'a', 'isFinal' => true],
			['uuid' => 'b', 'isFinal' => 'true'],
			['id' => 'c', 'isFinal' => 1],
			['id' => 'd', 'isFinal' => false],
			['id' => 'e'],
			['isFinal' => true],
		];

		self::assertSame(['a', 'b', 'c'], $this->subject('statusType', $rows)->ids());
	}//end testKeepsTheFinalRows()

	/**
	 * No schema or a failing search answers no ids.
	 *
	 * @return void
	 */
	public function testAnswersNothingWithoutSchemaOrSearch(): void {
		self::assertSame([], $this->subject('', [['id' => 'a', 'isFinal' => true]])->ids());
		self::assertSame([], $this->subject('statusType', null)->ids());
	}//end testAnswersNothingWithoutSchemaOrSearch()
}//end class
