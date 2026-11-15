<?php

/**
 * The Bezwaar case type is seeded with the Woo request case type.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/woo-case-screens-and-objections/specs/bezwaar-beroep-workflow/spec.md#requirement-the-bezwaar-case-type-exists-where-the-woo-case-type-does-req-wcs-006
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Settings;

use OCA\Dossiq\Service\Settings\RegisterFragmentMerger;
use OCA\Dossiq\Tests\Support\RealSchemaValidator;
use PHPUnit\Framework\TestCase;

/**
 * Reads the merged register the importer reads.
 */
class WooBezwaarCaseTypeSeedTest extends TestCase {

	private const BEZWAAR = 'b3c1a000-0000-4000-a000-00000000be2a';

	/**
	 * The seeded objects of the merged register, by schema.
	 *
	 * @param string $schema The schema slug.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function seeded(string $schema): array {
		$dir = dirname(__DIR__, 3) . '/lib/Settings';
		$base = json_decode((string)file_get_contents($dir . '/dossiq_register.json'), true);
		[$merged] = (new RegisterFragmentMerger())->merge(base: $base, fragmentDir: $dir . '/register.d');

		return array_values(
			array_filter(
				(array)($merged['components']['objects'] ?? []),
				static fn (array $o): bool => (($o['@self']['schema'] ?? '') === $schema)
			)
		);
	}//end seeded()

	/**
	 * The case type with this uuid, or [].
	 *
	 * @param string $uuid The uuid.
	 *
	 * @return array<string, mixed>
	 */
	private function caseType(string $uuid): array {
		foreach ($this->seeded('caseType') as $caseType) {
			if (($caseType['uuid'] ?? '') === $uuid) {
				return $caseType;
			}
		}

		return [];
	}//end caseType()

	/**
	 * Bezwaar is seeded beside Woo, and Woo's remedy opens it.
	 *
	 * @return void
	 */
	public function testTheBezwaarCaseTypeIsSeededWithTheWooCaseType(): void {
		$bezwaar = $this->caseType(self::BEZWAAR);
		$woo = $this->caseType('3c0f5a00-0000-4000-a000-00000000a001');

		self::assertSame('Bezwaar', $bezwaar['title'] ?? null);
		self::assertNotSame([], $woo);
		self::assertSame(self::BEZWAAR, $woo['remedy']['caseType'] ?? null);
		self::assertSame(42, $woo['remedy']['termDays'] ?? null);
		self::assertGreaterThanOrEqual(
			8,
			count(array_filter($this->seeded('statusType'), static fn (array $s): bool => ($s['caseType'] ?? '') === self::BEZWAAR))
		);
	}//end testTheBezwaarCaseTypeIsSeededWithTheWooCaseType()

	/**
	 * Beroep is not seeded on install: it ships in the bezwaar-beroep package,
	 * installed on request. That package ships Bezwaar too, with its roles and
	 * its flow, under the uuid of the seeded case type and the uuids of the
	 * seeded statuses, so installing it completes that one and adds no second.
	 *
	 * @return void
	 */
	public function testThePackageCompletesTheSeededBezwaarAndBeroepStaysOnRequest(): void {
		$dir = dirname(__DIR__, 3) . '/lib/Settings/packages/bezwaar-beroep';
		$manifest = json_decode((string)file_get_contents($dir . '/package.json'), true);
		$package = json_decode((string)file_get_contents($dir . '/case-type.json'), true);

		self::assertSame('on-request', $manifest['install']);
		self::assertSame(['bezwaar', 'beroep'], array_column((array)$package['caseType'], 'identifier'));
		self::assertSame(self::BEZWAAR, $package['caseType'][0]['id']);
		self::assertSame([], $this->caseType('b3c1a000-0000-4000-a000-00000000be30'));

		$ofBezwaar = static fn (array $s): bool => ($s['caseType'] ?? '') === self::BEZWAAR;
		$seeded = array_column(array_filter($this->seeded('statusType'), $ofBezwaar), 'name', 'uuid');
		$packaged = array_column(array_filter((array)$package['statusTypes'], $ofBezwaar), 'name', 'id');
		ksort($seeded);
		ksort($packaged);

		self::assertNotSame([], $packaged);
		self::assertSame($seeded, $packaged);
	}//end testThePackageCompletesTheSeededBezwaarAndBeroepStaysOnRequest()

	/**
	 * Six weeks, one extension of six weeks.
	 *
	 * @return void
	 */
	public function testTheBezwaarTermIsSixWeeksRolled(): void {
		$bezwaar = $this->caseType(self::BEZWAAR);

		self::assertSame('P6W', $bezwaar['processingDeadline']);
		self::assertTrue($bezwaar['extensionAllowed']);
		self::assertSame('P6W', $bezwaar['extensionPeriod']);
	}//end testTheBezwaarTermIsSixWeeksRolled()

	/**
	 * Every seeded Bezwaar object and the remedy writes are valid against their real schemas.
	 *
	 * @return void
	 */
	public function testTheSeedAndTheRemedyWritesAreValid(): void {
		$validator = new RealSchemaValidator();
		$seeded = array_merge($this->seeded('caseType'), $this->seeded('statusType'), $this->seeded('resultType'));
		foreach ($seeded as $object) {
			$uuid = (string)($object['uuid'] ?? '');
			if ($uuid !== self::BEZWAAR && str_starts_with($uuid, 'b3c1a000-0000-4000-a000-0000000') === false && $uuid !== '3c0f5a00-0000-4000-a000-00000000a001') {
				continue;
			}

			$payload = $object;
			unset($payload['@self']);
			self::assertSame([], $validator->errors($object['@self']['schema'], $payload), $uuid);
		}

		self::assertSame(
			[],
			$validator->errors('case', ['title' => 'Bezwaar: X', 'caseType' => self::BEZWAAR, 'startDate' => '2026-12-01'])
		);
		self::assertSame(
			[],
			$validator->errors('objection', ['case' => '3c0f5a00-0000-4000-a000-00000000bbbb', 'contestedDecision' => '3c0f5a00-0000-4000-a000-00000000cccc', 'receivedDate' => '2026-12-01', 'receivedChannel' => 'brief', 'grounds' => 'Onevenredig', 'isTimely' => true, 'registeredBy' => 'admin'])
		);
	}//end testTheSeedAndTheRemedyWritesAreValid()
}//end class
