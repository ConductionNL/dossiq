<?php

/**
 * A Participatiewet application is a case of the Bijstandsaanvraag case type.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Settings
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/social-domain-procedures-are-case-types/specs/dossiq-sociaal-domein-participatiewet/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Settings;

use OCA\Dossiq\Service\Import\RecordCaseMapping;
use OCA\Dossiq\Service\SociaalDomein\SociaalDomeinStore;
use OCA\Dossiq\Service\Settings\RegisterFragmentMerger;
use OCA\Dossiq\Tests\Support\MakesCaseDateNormaliser;
use PHPUnit\Framework\TestCase;

/**
 * The package, and its record import over every shipped participatiewetZaak.
 *
 * @covers \OCA\Dossiq\Service\Import\RecordCaseMapping
 * @covers \OCA\Dossiq\Service\Import\RecordValues
 * @uses   \OCA\Dossiq\Service\CaseDateNormaliser
 * @uses   \OCA\Dossiq\Service\Settings\RegisterFragmentMerger
 * @uses   \OCA\Dossiq\Service\SociaalDomein\SociaalDomeinStore
 */
class ParticipatiewetCaseTypeTest extends TestCase {
	use MakesCaseDateNormaliser;

	private const PACKAGE = __DIR__ . '/../../../lib/Settings/packages/bijstandsaanvraag';

	private const SETTINGS = __DIR__ . '/../../../lib/Settings';

	/**
	 * A JSON file, decoded.
	 *
	 * @param string $path The path.
	 *
	 * @return array<string, mixed>
	 */
	private function json(string $path): array {
		self::assertFileExists($path);
		return (array)json_decode((string)file_get_contents($path), true);
	}//end json()

	/**
	 * The shipped register, fragments merged.
	 *
	 * @return array<string, mixed>
	 */
	private function register(): array {
		[$merged] = (new RegisterFragmentMerger())->merge(
			base: $this->json(self::SETTINGS . '/dossiq_register.json'),
			fragmentDir: self::SETTINGS . '/register.d'
		);
		return $merged;
	}//end register()

	/**
	 * The record import over participatiewetZaak.
	 *
	 * @return array<string, mixed>
	 */
	private function import(): array {
		return $this->json(self::PACKAGE . '/case-type.json')['caseType']['recordImports'][0];
	}//end import()

	/**
	 * The package installs everywhere, confidential, handled by the social domain, in the Participatiewet domain.
	 *
	 * @return void
	 */
	public function testThePackageShipsTheCaseTypeInItsDomain(): void {
		self::assertSame('auto', $this->json(self::PACKAGE . '/package.json')['install']);
		$type = $this->json(self::PACKAGE . '/case-type.json')['caseType'];
		self::assertSame('vertrouwelijk', $type['confidentiality']);
		self::assertSame('dossiq-sociaal-domein', $type['handling']['defaultGroup']);
		self::assertSame('Participatiewet', (new \ReflectionClass(SociaalDomeinStore::class))->newInstanceWithoutConstructor()->domainOfCategory(category: $type['category']));
	}//end testThePackageShipsTheCaseTypeInItsDomain()

	/**
	 * Every status the old lifecycle knows lands on a status of the type.
	 *
	 * @return void
	 */
	public function testEveryOldStatusHasAStatus(): void {
		$schema = $this->register()['components']['schemas']['participatiewetZaak'];
		$statusIds = array_column($this->json(self::PACKAGE . '/case-type.json')['statusTypes'], 'id');
		foreach ($schema['properties']['status']['enum'] as $old) {
			self::assertContains($this->import()['statuses'][$old]['status'] ?? null, $statusIds, $old);
		}
	}//end testEveryOldStatusHasAStatus()

	/**
	 * 🔴 No field the old record restricted lands where more people read it:
	 * each restricted source field maps onto subjectBsn or sensitiveProperties,
	 * and those carry the same group.
	 *
	 * @return void
	 */
	public function testARestrictedFieldStaysRestricted(): void {
		$register = $this->register()['components']['schemas'];
		$restricted = array_keys(array_filter($register['participatiewetZaak']['properties'], static fn (array $prop): bool => isset($prop['authorization']) === true));
		self::assertNotSame([], $restricted);
		$targets = [];
		foreach ($this->import()['fields'] as $field) {
			$targets[(string)($field['from'] ?? '')] = (string)$field['to'];
		}

		foreach ($restricted as $name) {
			$to = $targets[$name] ?? null;
			self::assertContains($to, ['subjectBsn', 'sensitiveProperties'], $name . ' is not moved where only dossiq-sensitive reads it');
			self::assertSame([['group' => 'dossiq-sensitive']], $register['case']['properties'][$to]['authorization']['read']);
		}

		$definitions = array_column($this->json(self::PACKAGE . '/case-type.json')['propertyDefinitions'], null, 'id');
		foreach ($this->import()['fields'] as $field) {
			if (isset($field['property']) === true) {
				$sensitive = ($definitions[$field['property']['definition']]['sensitive'] ?? false) === true;
				self::assertSame($sensitive, $field['to'] === 'sensitiveProperties', $field['property']['name']);
			}
		}
	}//end testARestrictedFieldStaysRestricted()

	/**
	 * Every shipped application maps onto a case, nothing restricted in the ordinary answers.
	 *
	 * @return void
	 */
	public function testEveryShippedApplicationMapsOntoACase(): void {
		$type = $this->json(self::PACKAGE . '/case-type.json')['caseType'];
		$rows = array_values(array_filter(
			$this->json(self::SETTINGS . '/register.d/50-sociaal-domein.json')['components']['objects'],
			static fn (array $object): bool => $object['@self']['schema'] === 'participatiewetZaak'
		));
		self::assertCount(3, $rows);
		$mapping = new RecordCaseMapping(dates: $this->caseDates());
		foreach ($rows as $row) {
			$case = $mapping->map(import: $this->import(), record: $row, recordId: 'id-' . $row['caseNumber'], caseTypeId: $type['id'])['case'];
			self::assertSame($row['bsn'], $case['subjectBsn']);
			self::assertSame($row['handlerId'], $case['assignee']);
			self::assertSame([['application' => 'dossiq', 'reference' => $row['caseNumber']]], $case['formerReferences']);
			self::assertSame(['huishoudsituatie', 'vermogenstoets', 'inkomenstoets'], array_column($case['sensitiveProperties'], 'name'));
			$plain = array_column($case['properties'], 'name');
			self::assertContains('avgClassificatie', $plain);
			self::assertSame([], array_intersect($plain, ['huishoudsituatie', 'vermogenstoets', 'inkomenstoets']));
		}
	}//end testEveryShippedApplicationMapsOntoACase()

	/**
	 * The import joins duplicates by case number, moves consents and trajectories, and stamps the record.
	 *
	 * @return void
	 */
	public function testTheImportIsIdempotentAndMovesReferences(): void {
		$import = $this->import();
		self::assertSame('caseNumber', $import['dedupeBy']);
		self::assertSame('caseNumber', $import['formerReference']['from']);
		self::assertContains(['schema' => 'toestemming', 'field' => 'caseId'], $import['references']);
		self::assertTrue($import['runOnUpgrade']);
		$schema = $this->register()['components']['schemas']['participatiewetZaak'];
		self::assertArrayHasKey('migratedTo', $schema['properties']);
		self::assertArrayHasKey('migratedAt', $schema['properties']);
	}//end testTheImportIsIdempotentAndMovesReferences()
}//end class
