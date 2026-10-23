<?php

/**
 * The social-domain procedures are cases of their case types: a Participatiewet
 * application of Bijstandsaanvraag, a Wmo report of Wmo-melding.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Settings
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/social-domain-procedures-are-case-types/specs/dossiq-sociaal-domein-participatiewet/spec.md
 * @spec openspec/changes/social-domain-procedures-are-case-types/specs/dossiq-sociaal-domein-wmo/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Settings;

use OCA\Dossiq\Service\Import\RecordCaseMapping;
use OCA\Dossiq\Service\SociaalDomein\SociaalDomeinStore;
use OCA\Dossiq\Service\Settings\RegisterFragmentMerger;
use OCA\Dossiq\Tests\Support\MakesCaseDateNormaliser;
use PHPUnit\Framework\TestCase;

/**
 * Each package, and its record import over every shipped record of its procedure.
 *
 * @covers \OCA\Dossiq\Service\Import\RecordCaseMapping
 * @covers \OCA\Dossiq\Service\Import\RecordValues
 * @uses   \OCA\Dossiq\Service\CaseDateNormaliser
 * @uses   \OCA\Dossiq\Service\Settings\RegisterFragmentMerger
 * @uses   \OCA\Dossiq\Service\SociaalDomein\SociaalDomeinStore
 */
class SocialDomainCaseTypesTest extends TestCase {
	use MakesCaseDateNormaliser;

	private const PACKAGES = __DIR__ . '/../../../lib/Settings/packages/';

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
	 * A package's case-type file.
	 *
	 * @param string $package The package id.
	 *
	 * @return array<string, mixed>
	 */
	private function caseTypeFile(string $package): array {
		return $this->json(self::PACKAGES . $package . '/case-type.json');
	}//end caseTypeFile()

	/**
	 * A package's record import.
	 *
	 * @param string $package The package id.
	 *
	 * @return array<string, mixed>
	 */
	private function import(string $package): array {
		return $this->caseTypeFile($package)['caseType']['recordImports'][0];
	}//end import()

	/**
	 * The procedures: package, source schema, domain, the sensitive answers each record carries, the source's BSN field.
	 *
	 * @return array<string, array{0: string, 1: string, 2: string, 3: array<int, string>, 4: string}>
	 */
	public static function procedures(): array {
		return [
			'Participatiewet' => ['bijstandsaanvraag', 'participatiewetZaak', 'Participatiewet', ['huishoudsituatie', 'vermogenstoets', 'inkomenstoets'], 'bsn'],
			'Wmo' => ['wmo-melding', 'wmoZaak', 'Wmo', ['hulpvraag', 'huishoudsamenstelling'], 'bsn'],
			'Jeugdwet' => ['jeugdwet', 'jeugdwetZaak', 'Jeugdwet', ['hulpvraag', 'gezinsplan', 'mdoOverleggen', 'ondertoezichtstelling'], 'jeugdigeBsn'],
		];
	}//end procedures()

	/**
	 * The package installs everywhere, confidential, handled by the social domain, in the Participatiewet domain.
	 *
	 * @return void
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('procedures')]
	public function testThePackageShipsTheCaseTypeInItsDomain(string $package, string $source, string $domain, array $sensitive, string $bsnKey): void {
		self::assertSame('auto', $this->json(self::PACKAGES . $package . '/package.json')['install']);
		$type = $this->caseTypeFile($package)['caseType'];
		self::assertSame('vertrouwelijk', $type['confidentiality']);
		self::assertSame('dossiq-sociaal-domein', $type['handling']['defaultGroup']);
		self::assertSame($domain, (new \ReflectionClass(SociaalDomeinStore::class))->newInstanceWithoutConstructor()->domainOfCategory(category: $type['category']));
	}//end testThePackageShipsTheCaseTypeInItsDomain()

	/**
	 * Every status the old lifecycle knows lands on a status of the type.
	 *
	 * @return void
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('procedures')]
	public function testEveryOldStatusHasAStatus(string $package, string $source, string $domain, array $sensitive, string $bsnKey): void {
		$schema = $this->register()['components']['schemas'][$source];
		$statusIds = array_column($this->caseTypeFile($package)['statusTypes'], 'id');
		foreach ($schema['properties']['status']['enum'] as $old) {
			self::assertContains($this->import($package)['statuses'][$old]['status'] ?? null, $statusIds, $old);
		}
	}//end testEveryOldStatusHasAStatus()

	/**
	 * 🔴 No field the old record restricted lands where more people read it:
	 * each restricted source field maps onto subjectBsn or sensitiveProperties,
	 * and those carry the same group.
	 *
	 * @return void
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('procedures')]
	public function testARestrictedFieldStaysRestricted(string $package, string $source, string $domain, array $sensitive, string $bsnKey): void {
		$register = $this->register()['components']['schemas'];
		$restricted = array_keys(array_filter($register[$source]['properties'], static fn (array $prop): bool => isset($prop['authorization']) === true));
		self::assertNotSame([], $restricted);
		$targets = [];
		foreach ($this->import($package)['fields'] as $field) {
			$targets[(string)($field['from'] ?? '')] = (string)$field['to'];
		}

		foreach ($restricted as $name) {
			$to = $targets[$name] ?? null;
			self::assertContains($to, ['subjectBsn', 'sensitiveProperties'], $name . ' is not moved where only dossiq-sensitive reads it');
			self::assertSame([['group' => 'dossiq-sensitive']], $register['case']['properties'][$to]['authorization']['read']);
		}

		$definitions = array_column($this->caseTypeFile($package)['propertyDefinitions'], null, 'id');
		foreach ($this->import($package)['fields'] as $field) {
			if (isset($field['property']) === true) {
				$flagged = ($definitions[$field['property']['definition']]['sensitive'] ?? false) === true;
				self::assertSame($flagged, $field['to'] === 'sensitiveProperties', $field['property']['name']);
			}
		}
	}//end testARestrictedFieldStaysRestricted()

	/**
	 * Every shipped application maps onto a case, nothing restricted in the ordinary answers.
	 *
	 * @return void
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('procedures')]
	public function testEveryShippedApplicationMapsOntoACase(string $package, string $source, string $domain, array $sensitive, string $bsnKey): void {
		$type = $this->caseTypeFile($package)['caseType'];
		$rows = array_values(array_filter(
			$this->json(self::SETTINGS . '/register.d/50-sociaal-domein.json')['components']['objects'],
			static fn (array $object): bool => $object['@self']['schema'] === $source
		));
		self::assertCount(3, $rows);
		$mapping = new RecordCaseMapping(dates: $this->caseDates());
		foreach ($rows as $row) {
			$case = $mapping->map(import: $this->import($package), record: $row, recordId: 'id-' . $row['caseNumber'], caseTypeId: $type['id'])['case'];
			self::assertSame($row[$bsnKey], $case['subjectBsn']);
			self::assertSame($row['handlerId'], $case['assignee']);
			self::assertSame([['application' => 'dossiq', 'reference' => $row['caseNumber']]], $case['formerReferences']);
			$hidden = array_column($case['sensitiveProperties'] ?? [], 'name');
			self::assertNotSame([], $hidden, $source);
			self::assertSame([], array_diff($hidden, $sensitive), $source);
			$plain = array_column($case['properties'], 'name');
			self::assertContains('avgClassificatie', $plain);
			self::assertSame([], array_intersect($plain, $sensitive));
		}
	}//end testEveryShippedApplicationMapsOntoACase()

	/**
	 * The import joins duplicates by case number, moves consents and trajectories, and stamps the record.
	 *
	 * @return void
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('procedures')]
	public function testTheImportIsIdempotentAndMovesReferences(string $package, string $source, string $domain, array $sensitive, string $bsnKey): void {
		$import = $this->import($package);
		self::assertSame('caseNumber', $import['dedupeBy']);
		self::assertSame('caseNumber', $import['formerReference']['from']);
		self::assertContains(['schema' => 'toestemming', 'field' => 'caseId'], $import['references']);
		self::assertTrue($import['runOnUpgrade']);
		$schema = $this->register()['components']['schemas'][$source];
		self::assertArrayHasKey('migratedTo', $schema['properties']);
		self::assertArrayHasKey('migratedAt', $schema['properties']);
	}//end testTheImportIsIdempotentAndMovesReferences()

	/**
	 * A benefit application runs on its eight-week decision term (Ruben, 23
	 * Oct): the case type declares it, a term definition binds to it, and the
	 * import carries the term of a still-open application onto its case.
	 *
	 * @return void
	 */
	public function testABenefitApplicationRunsOnItsDecisionTerm(): void {
		$type = $this->caseTypeFile('bijstandsaanvraag')['caseType'];
		self::assertSame('P8W', $type['processingDeadline']);
		self::assertTrue($this->import('bijstandsaanvraag')['carriesTerm']);
		$definitions = array_column($this->json(self::SETTINGS . '/termijnbewaking_seed_data.json')['termijnDefinities'], null, 'caseType');
		self::assertSame(56, $definitions['bijstandsaanvraag']['standardDurationDays']);
		self::assertSame('running', $this->import('bijstandsaanvraag')['statuses']['decision-voorbereiding']['term']);
		self::assertSame('closed', $this->import('bijstandsaanvraag')['statuses']['social-assistance-active']['term']);
	}//end testABenefitApplicationRunsOnItsDecisionTerm()

	/**
	 * Each procedure's flow walks from the first status to every other one, and
	 * guards only fields its case type declares (or the result).
	 *
	 * @param string             $package   The package id.
	 * @param string             $source    The old record's schema.
	 * @param string             $domain    The domain.
	 * @param array<int, string> $sensitive The sensitive answers.
	 * @param string             $bsnKey    The source's BSN field.
	 *
	 * @return void
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('procedures')]
	public function testTheFlowReachesEveryStatusAndGuardsDeclaredFields(string $package, string $source, string $domain, array $sensitive, string $bsnKey): void {
		$file = $this->caseTypeFile($package);
		$flowFile = self::PACKAGES . $package . '/flows/' . $file['caseType']['identifier'] . '.json';
		$flow = $this->json($flowFile);
		self::assertSame($file['caseType']['workflowDefinition'], $flow['id']);
		$transitions = json_decode($flow['transitions'], true);
		$names = array_column($file['propertyDefinitions'], 'name');
		$reached = [$file['caseType']['initialStatus'] => true];
		$grew = true;
		while ($grew === true) {
			$grew = false;
			foreach ($transitions as $transition) {
				if (isset($reached[$transition['fromStatus']]) === true && isset($reached[$transition['toStatus']]) === false) {
					$reached[$transition['toStatus']] = true;
					$grew = true;
				}
			}
		}

		foreach ($file['statusTypes'] as $status) {
			self::assertArrayHasKey($status['id'], $reached, $package . ': ' . $status['name'] . ' cannot be reached');
		}

		foreach ($transitions as $transition) {
			foreach ($transition['guards'] as $guard) {
				if (($guard['type'] ?? '') === 'requiredField' && $guard['field'] !== 'result') {
					self::assertContains($guard['field'], $names, $package . ': ' . $transition['label']);
				}
			}
		}
	}//end testTheFlowReachesEveryStatusAndGuardsDeclaredFields()
}//end class
