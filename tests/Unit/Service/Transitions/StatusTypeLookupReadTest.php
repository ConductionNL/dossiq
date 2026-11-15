<?php

/**
 * StatusTypeLookup store-read tests
 *
 * Verifies how a single statusType row is read from the store when the store
 * is unavailable, unconfigured, throwing, answers an entity, or answers
 * something that is not a row, and that the row helpers short-circuit on a
 * blank case type without asking the resolver.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Transitions
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
 * @spec openspec/specs/status-transition-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Transitions;

use OCA\Dossiq\Service\CaseTypeResolver;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Transitions\StatusTypeLookup;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Service\Transitions\StatusTypeLookup
 *
 * @uses \OCA\Dossiq\Service\Support\LanguageMapText
 */
class StatusTypeLookupReadTest extends TestCase {

	/**
	 * A lookup whose store answers `$found` (or throws it) for every find.
	 *
	 * @param mixed                 $found    What `find()` returns, or a Throwable it throws.
	 * @param array<string, string> $config   The settings values.
	 * @param bool                  $noStore  Whether the object service is unavailable.
	 * @param CaseTypeResolver|null $resolver The resolver, or a silent mock.
	 *
	 * @return StatusTypeLookup
	 */
	private function lookup(
		mixed $found = [],
		array $config = ['register' => 'dossiq', 'status_type_schema' => 'statusType'],
		bool $noStore = false,
		?CaseTypeResolver $resolver = null,
	): StatusTypeLookup {
		$objectService = new class($found) {
			public function __construct(private mixed $found) {
			}

			public function find(string $id, string $register, string $schema): mixed {
				if ($this->found instanceof \Throwable) {
					throw $this->found;
				}

				return $this->found;
			}
		};

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($noStore === true ? null : $objectService);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key): string => ($config[$key] ?? '')
		);

		return new StatusTypeLookup($settings, ($resolver ?? $this->createMock(CaseTypeResolver::class)));
	}//end lookup()

	/**
	 * A row read from the store is returned whole, from the configured schema.
	 *
	 * @return void
	 */
	public function testARowIsReadFromTheConfiguredSchema(): void {
		$row = ['id' => 's-1', 'name' => 'Ontvangen', 'publicLabel' => 'Ontvangen door de gemeente'];
		$lookup = $this->lookup(found: $row);

		self::assertSame($row, $lookup->rowFor(statusTypeId: 's-1'));
		self::assertSame('Ontvangen door de gemeente', $lookup->announcedLabelOf(statusTypeId: 's-1'));
	}//end testARowIsReadFromTheConfiguredSchema()

	/**
	 * An entity the store answers with is serialised into its row.
	 *
	 * @return void
	 */
	public function testAnEntityIsSerialisedIntoItsRow(): void {
		$entity = new class implements \JsonSerializable {
			public function jsonSerialize(): array {
				return ['id' => 's-2', 'title' => 'In behandeling'];
			}
		};

		$lookup = $this->lookup(found: $entity);

		self::assertSame(['id' => 's-2', 'title' => 'In behandeling'], $lookup->rowFor(statusTypeId: 's-2'));
		self::assertSame('In behandeling', $lookup->nameFor(statusTypeId: 's-2'));
	}//end testAnEntityIsSerialisedIntoItsRow()

	/**
	 * Something that is not a row reads as no row.
	 *
	 * @return void
	 */
	public function testANonRowAnswerIsNoRow(): void {
		self::assertSame([], $this->lookup(found: 'not-a-row')->rowFor(statusTypeId: 's-3'));
		self::assertSame([], $this->lookup(found: new \stdClass())->rowFor(statusTypeId: 's-3'));
	}//end testANonRowAnswerIsNoRow()

	/**
	 * A store that throws reads as no row, and the announced label is empty.
	 *
	 * @return void
	 */
	public function testAThrowingStoreIsNoRow(): void {
		$lookup = $this->lookup(found: new RuntimeException('unreadable'));

		self::assertSame([], $lookup->rowFor(statusTypeId: 's-4'));
		self::assertSame('', $lookup->announcedLabelOf(statusTypeId: 's-4'));
	}//end testAThrowingStoreIsNoRow()

	/**
	 * No object service means no row.
	 *
	 * @return void
	 */
	public function testNoObjectServiceIsNoRow(): void {
		self::assertSame([], $this->lookup(found: ['id' => 'x'], noStore: true)->rowFor(statusTypeId: 's-5'));
	}//end testNoObjectServiceIsNoRow()

	/**
	 * An unconfigured register or schema means no row, and the store is not asked.
	 *
	 * @return void
	 */
	public function testAnUnconfiguredRegisterOrSchemaIsNoRow(): void {
		self::assertSame(
			[],
			$this->lookup(found: ['id' => 'x'], config: ['status_type_schema' => 'statusType'])->rowFor(statusTypeId: 's-6')
		);
		self::assertSame(
			[],
			$this->lookup(found: ['id' => 'x'], config: ['register' => 'dossiq'])->rowFor(statusTypeId: 's-6')
		);
	}//end testAnUnconfiguredRegisterOrSchemaIsNoRow()

	/**
	 * A blank case type has no statuses and no rows, and the resolver is not asked.
	 *
	 * @return void
	 */
	public function testABlankCaseTypeHasNoStatusesOrRows(): void {
		$resolver = $this->createMock(CaseTypeResolver::class);
		$resolver->expects($this->never())->method('statusTypesFor');

		$lookup = $this->lookup(resolver: $resolver);

		self::assertSame([], $lookup->statusesOf(caseTypeId: '  '));
		self::assertSame([], $lookup->rowsOf(caseTypeId: ' '));
	}//end testABlankCaseTypeHasNoStatusesOrRows()

	/**
	 * The rows of a case type are the resolver's, inherited ones included.
	 *
	 * @return void
	 */
	public function testRowsOfAreTheResolversRows(): void {
		$rows = [
			['id' => 'p-1', 'name' => 'Ontvangen', 'isFinal' => false],
			['id' => 'c-9', 'name' => 'Afgehandeld', 'isFinal' => true],
		];
		$resolver = $this->createMock(CaseTypeResolver::class);
		$resolver->expects($this->once())
			->method('statusTypesFor')
			->with('ct-child')
			->willReturn($rows);

		self::assertSame($rows, $this->lookup(resolver: $resolver)->rowsOf(caseTypeId: 'ct-child'));
	}//end testRowsOfAreTheResolversRows()
}//end class
