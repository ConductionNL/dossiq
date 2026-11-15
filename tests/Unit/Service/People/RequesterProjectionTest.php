<?php

/**
 * The three projected requester fields, read from the person or company set.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\People
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/initiator-display/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\People;

use OCA\Dossiq\Service\People\RequesterProjection;
use OCA\Dossiq\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Tests for RequesterProjection.
 *
 * @covers \OCA\Dossiq\Service\People\RequesterProjection
 * @uses \OCA\Dossiq\Service\Support\TrustedRevealRead
 */
class RequesterProjectionTest extends TestCase {

	/**
	 * The object service double the last projection was built over.
	 *
	 * @var object
	 */
	private object $objects;

	/**
	 * A projection over these rows per schema; a schema mapped to an exception throws it.
	 *
	 * @param array<string, mixed> $rows     Rows (or a Throwable) per schema slug.
	 * @param string               $register The configured register.
	 * @param bool                 $noOr     Whether OpenRegister is absent.
	 *
	 * @return RequesterProjection The service under test.
	 */
	private function projection(array $rows, string $register = 'dossiq', bool $noOr = false): RequesterProjection {
		$this->objects = new class ($rows) {
			/**
			 * The calls made, as [register, schema, filters].
			 *
			 * @var array<int, array{0: string, 1: string, 2: array<string, mixed>}>
			 */
			public array $calls = [];

			/**
			 * @param array<string, mixed> $rows Rows per schema.
			 */
			public function __construct(private array $rows) {
			}

			/**
			 * @param string               $register The register slug.
			 * @param string               $schema   The schema slug.
			 * @param array<string, mixed> $filters  The filters.
			 *
			 * @return array<int, array<string, mixed>>
			 */
			public function searchObjectsBySlug(string $register, string $schema, array $filters = []): array {
				$this->calls[] = [$register, $schema, $filters];
				$answer = ($this->rows[$schema] ?? []);
				if ($answer instanceof \Throwable) {
					throw $answer;
				}

				return $answer;
			}
		};

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($noOr === true ? null : $this->objects);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => ($key === 'register' ? $register : $default)
		);

		return new RequesterProjection(settingsService: $settings);
	}//end projection()

	/**
	 * A person is projected with its BSN and display name.
	 *
	 * @return void
	 */
	public function testAPersonIsProjectedWithItsDisplayName(): void {
		$projection = $this->projection(rows: [
			'brpPerson' => [['displayName' => '  J. de Vries ', 'citizenServiceNumber' => '123456782']],
		]);

		self::assertSame(
			['initiatorType' => 'person', 'initiatorSourceId' => '123456782', 'initiatorDisplayName' => 'J. de Vries'],
			$projection->forRequester(uuid: 'p-1')
		);
		self::assertSame(['dossiq', 'brpPerson', ['_ids' => ['p-1'], '_limit' => 1]], $this->objects->calls[0]);
		self::assertCount(1, $this->objects->calls, 'A person found in the first set does not ask the company set.');
	}//end testAPersonIsProjectedWithItsDisplayName()

	/**
	 * Without a display name the structured name parts are joined, empty parts dropped.
	 *
	 * @return void
	 */
	public function testThePersonNameIsBuiltFromItsPartsWhenNoDisplayName(): void {
		$projection = $this->projection(rows: [
			'brpPerson' => [[
				'displayName' => ' ',
				'name' => ['givenNames' => ' Jan ', 'namePrefix' => '', 'surname' => 'Vries'],
			]],
		]);

		self::assertSame(
			['initiatorType' => 'person', 'initiatorSourceId' => '', 'initiatorDisplayName' => 'Jan Vries'],
			$projection->forRequester(uuid: 'p-1')
		);
	}//end testThePersonNameIsBuiltFromItsPartsWhenNoDisplayName()

	/**
	 * A name that is not an array leaves the display name empty.
	 *
	 * @return void
	 */
	public function testANonArrayNameGivesAnEmptyDisplayName(): void {
		$projection = $this->projection(rows: ['brpPerson' => [['name' => 'Jan', 'citizenServiceNumber' => 999]]]);

		self::assertSame(
			['initiatorType' => 'person', 'initiatorSourceId' => '999', 'initiatorDisplayName' => ''],
			$projection->forRequester(uuid: 'p-1')
		);
	}//end testANonArrayNameGivesAnEmptyDisplayName()

	/**
	 * A requester that is no person is looked up as a company.
	 *
	 * @return void
	 */
	public function testACompanyIsProjectedWithItsKvkNumberAndTradeName(): void {
		$projection = $this->projection(rows: [
			'brpPerson' => [],
			'kvkCompany' => [['kvkNumber' => '12345678', 'tradeName' => 'Bakkerij Jansen']],
		]);

		self::assertSame(
			['initiatorType' => 'company', 'initiatorSourceId' => '12345678', 'initiatorDisplayName' => 'Bakkerij Jansen'],
			$projection->forRequester(uuid: 'c-1')
		);
		self::assertSame(['brpPerson', 'kvkCompany'], array_column($this->objects->calls, 1));
	}//end testACompanyIsProjectedWithItsKvkNumberAndTradeName()

	/**
	 * A set that cannot be read is skipped, and the next set still answers.
	 *
	 * @return void
	 */
	public function testAnUnreadableSetIsSkipped(): void {
		$projection = $this->projection(rows: [
			'brpPerson' => new RuntimeException('no access'),
			'kvkCompany' => [['kvkNumber' => '87654321']],
		]);

		self::assertSame(
			['initiatorType' => 'company', 'initiatorSourceId' => '87654321', 'initiatorDisplayName' => ''],
			$projection->forRequester(uuid: 'c-1')
		);
	}//end testAnUnreadableSetIsSkipped()

	/**
	 * A requester in neither set projects nothing.
	 *
	 * @return void
	 */
	public function testARequesterInNeitherSetProjectsNothing(): void {
		$projection = $this->projection(rows: ['brpPerson' => new RuntimeException('down'), 'kvkCompany' => []]);

		self::assertSame([], $projection->forRequester(uuid: 'x-1'));
		self::assertCount(2, $this->objects->calls);
	}//end testARequesterInNeitherSetProjectsNothing()

	/**
	 * A blank uuid, an absent OpenRegister or an unconfigured register asks nothing.
	 *
	 * @return void
	 */
	public function testNothingIsAskedWithoutAUuidOrARegister(): void {
		$rows = ['brpPerson' => [['displayName' => 'X']]];

		self::assertSame([], $this->projection(rows: $rows)->forRequester(uuid: '  '));
		self::assertSame([], $this->objects->calls);

		self::assertSame([], $this->projection(rows: $rows, register: '')->forRequester(uuid: 'p-1'));
		self::assertSame([], $this->objects->calls);

		self::assertSame([], $this->projection(rows: $rows, noOr: true)->forRequester(uuid: 'p-1'));
		self::assertSame([], $this->objects->calls);
	}//end testNothingIsAskedWithoutAUuidOrARegister()
}//end class
