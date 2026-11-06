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
 * @spec openspec/specs/dossiq-sociaal-domein-participatiewet/spec.md
 * @spec openspec/specs/dossiq-sociaal-domein-wmo/spec.md
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
		self::assertFileExists(filename: $path);
		return (array)json_decode((string)file_get_contents($path), true);
	}//end json()

	/**
	 * The shipped register, fragments merged.
	 *
	 * @return array<string, mixed>
	 */
	private function register(): array {
		[$merged] = (new RegisterFragmentMerger())->merge(
			base: $this->json(path: self::SETTINGS . '/dossiq_register.json'),
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
		return $this->json(path: self::PACKAGES . $package . '/case-type.json');
	}//end caseTypeFile()

	/**
	 * A package's record import.
	 *
	 * @param string $package The package id.
	 *
	 * @return array<string, mixed>
	 */
	private function import(string $package): array {
		return $this->caseTypeFile(package: $package)['caseType']['recordImports'][0];
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
	 * @param string             $package   The package id.
	 * @param string             $source    The schema the old records live in.
	 * @param string             $domain    The social domain the case type maps to.
	 * @param array<int, string> $sensitive The fields only dossiq-sensitive reads.
	 * @param string             $bsnKey    The old record's BSN field.
	 *
	 * @return void
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('procedures')]
	public function testThePackageShipsTheCaseTypeInItsDomain(
		string $package,
		string $source,
		string $domain,
		array $sensitive,
		string $bsnKey
	): void {
		self::assertSame(expected: 'auto', actual: $this->json(path: self::PACKAGES . $package . '/package.json')['install']);
		$type = $this->caseTypeFile(package: $package)['caseType'];
		self::assertSame(expected: 'vertrouwelijk', actual: $type['confidentiality']);
		self::assertSame(expected: 'dossiq-sociaal-domein', actual: $type['handling']['defaultGroup']);
		self::assertSame(expected: $domain, actual: (new \ReflectionClass(SociaalDomeinStore::class))->newInstanceWithoutConstructor()->domainOfCategory(category: $type['category']));
	}//end testThePackageShipsTheCaseTypeInItsDomain()

	/**
	 * Every status the old lifecycle knows lands on a status of the type.
	 *
	 * @param string             $package   The package id.
	 * @param string             $source    The schema the old records live in.
	 * @param string             $domain    The social domain the case type maps to.
	 * @param array<int, string> $sensitive The fields only dossiq-sensitive reads.
	 * @param string             $bsnKey    The old record's BSN field.
	 *
	 * @return void
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('procedures')]
	public function testEveryOldStatusHasAStatus(
		string $package,
		string $source,
		string $domain,
		array $sensitive,
		string $bsnKey
	): void {
		$schema = $this->register()['components']['schemas'][$source];
		$statusIds = array_column($this->caseTypeFile(package: $package)['statusTypes'], 'id');
		foreach ($schema['properties']['status']['enum'] as $old) {
			self::assertContains(needle: $this->import(package: $package)['statuses'][$old]['status'] ?? null, haystack: $statusIds, message: $old);
		}
	}//end testEveryOldStatusHasAStatus()

	/**
	 * 🔴 No field the old record restricted lands where more people read it:
	 * each restricted source field maps onto subjectBsn or sensitiveProperties,
	 * and those carry the same group.
	 *
	 * @param string             $package   The package id.
	 * @param string             $source    The schema the old records live in.
	 * @param string             $domain    The social domain the case type maps to.
	 * @param array<int, string> $sensitive The fields only dossiq-sensitive reads.
	 * @param string             $bsnKey    The old record's BSN field.
	 *
	 * @return void
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('procedures')]
	public function testARestrictedFieldStaysRestricted(
		string $package,
		string $source,
		string $domain,
		array $sensitive,
		string $bsnKey
	): void {
		$register = $this->register()['components']['schemas'];
		$restricted = array_keys(array_filter($register[$source]['properties'], static fn (array $prop): bool => isset($prop['authorization']) === true));
		self::assertNotSame(expected: [], actual: $restricted);
		$targets = [];
		foreach ($this->import(package: $package)['fields'] as $field) {
			$targets[(string)($field['from'] ?? '')] = (string)$field['to'];
		}

		foreach ($restricted as $name) {
			$to = $targets[$name] ?? null;
			self::assertContains(needle: $to, haystack: ['subjectBsn', 'sensitiveProperties'], message: $name . ' is not moved where only dossiq-sensitive reads it');
			self::assertSame(expected: [['group' => 'dossiq-sensitive']], actual: $register['case']['properties'][$to]['authorization']['read']);
		}

		$definitions = array_column($this->caseTypeFile(package: $package)['propertyDefinitions'], null, 'id');
		foreach ($this->import(package: $package)['fields'] as $field) {
			if (isset($field['property']) === true) {
				$flagged = ($definitions[$field['property']['definition']]['sensitive'] ?? false) === true;
				self::assertSame(expected: $flagged, actual: $field['to'] === 'sensitiveProperties', message: $field['property']['name']);
			}
		}
	}//end testARestrictedFieldStaysRestricted()

	/**
	 * Every shipped application maps onto a case, nothing restricted in the ordinary answers.
	 *
	 * @param string             $package   The package id.
	 * @param string             $source    The schema the old records live in.
	 * @param string             $domain    The social domain the case type maps to.
	 * @param array<int, string> $sensitive The fields only dossiq-sensitive reads.
	 * @param string             $bsnKey    The old record's BSN field.
	 *
	 * @return void
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('procedures')]
	public function testEveryShippedApplicationMapsOntoACase(
		string $package,
		string $source,
		string $domain,
		array $sensitive,
		string $bsnKey
	): void {
		$type = $this->caseTypeFile(package: $package)['caseType'];
		$rows = array_values(array_filter(
			$this->json(path: self::SETTINGS . '/register.d/50-sociaal-domein.json')['components']['objects'],
			static fn (array $object): bool => $object['@self']['schema'] === $source
		));
		self::assertCount(expectedCount: 3, haystack: $rows);
		$mapping = new RecordCaseMapping(dates: $this->caseDates());
		foreach ($rows as $row) {
			$case = $mapping->map(import: $this->import(package: $package), record: $row, recordId: 'id-' . $row['caseNumber'], caseTypeId: $type['id'])['case'];
			self::assertSame(expected: $row[$bsnKey], actual: $case['subjectBsn']);
			self::assertSame(expected: $row['handlerId'], actual: $case['assignee']);
			self::assertSame(expected: [['application' => 'dossiq', 'reference' => $row['caseNumber']]], actual: $case['formerReferences']);
			$hidden = array_column($case['sensitiveProperties'] ?? [], 'name');
			self::assertNotSame(expected: [], actual: $hidden, message: $source);
			self::assertSame(expected: [], actual: array_diff($hidden, $sensitive), message: $source);
			$plain = array_column($case['properties'], 'name');
			self::assertContains(needle: 'avgClassificatie', haystack: $plain);
			self::assertSame(expected: [], actual: array_intersect($plain, $sensitive));
		}
	}//end testEveryShippedApplicationMapsOntoACase()

	/**
	 * The import joins duplicates by case number, moves consents and trajectories, and stamps the record.
	 *
	 * @param string             $package   The package id.
	 * @param string             $source    The schema the old records live in.
	 * @param string             $domain    The social domain the case type maps to.
	 * @param array<int, string> $sensitive The fields only dossiq-sensitive reads.
	 * @param string             $bsnKey    The old record's BSN field.
	 *
	 * @return void
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('procedures')]
	public function testTheImportIsIdempotentAndMovesReferences(
		string $package,
		string $source,
		string $domain,
		array $sensitive,
		string $bsnKey
	): void {
		$import = $this->import(package: $package);
		self::assertSame(expected: 'caseNumber', actual: $import['dedupeBy']);
		self::assertSame(expected: 'caseNumber', actual: $import['formerReference']['from']);
		self::assertContains(needle: ['schema' => 'toestemming', 'field' => 'caseId'], haystack: $import['references']);
		self::assertTrue(condition: $import['runOnUpgrade']);
		$schema = $this->register()['components']['schemas'][$source];
		self::assertArrayHasKey(key: 'migratedTo', array: $schema['properties']);
		self::assertArrayHasKey(key: 'migratedAt', array: $schema['properties']);
	}//end testTheImportIsIdempotentAndMovesReferences()

	/**
	 * A benefit application runs on its eight-week decision term (Ruben, 23
	 * Oct): the case type declares it, a term definition binds to it, and the
	 * import carries the term of a still-open application onto its case.
	 *
	 * @return void
	 */
	public function testABenefitApplicationRunsOnItsDecisionTerm(): void {
		$type = $this->caseTypeFile(package: 'bijstandsaanvraag')['caseType'];
		self::assertSame(expected: 'P8W', actual: $type['processingDeadline']);
		self::assertTrue(condition: $this->import(package: 'bijstandsaanvraag')['carriesTerm']);
		$definitions = array_column($this->json(path: self::SETTINGS . '/termijnbewaking_seed_data.json')['termijnDefinities'], null, 'caseType');
		self::assertSame(expected: 56, actual: $definitions['bijstandsaanvraag']['standardDurationDays']);
		self::assertSame(expected: 'running', actual: $this->import(package: 'bijstandsaanvraag')['statuses']['decision-voorbereiding']['term']);
		self::assertSame(expected: 'closed', actual: $this->import(package: 'bijstandsaanvraag')['statuses']['social-assistance-active']['term']);
		self::assertTrue(condition: $this->import(package: 'jeugdwet')['carriesTerm'], message: 'a Jeugdwet report runs on its eight weeks too (Ruben, 23 Oct)');
		self::assertSame(expected: 56, actual: $definitions['melding-jeugdwet']['standardDurationDays']);
	}//end testABenefitApplicationRunsOnItsDecisionTerm()

	/**
	 * Board DqSociaalDomein draws the Melding Jeugdwet type pill teal; the case
	 * type names that hue, or its pill draws neutral.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/case-types/spec.md#requirement-the-jeugdwet-case-type-is-drawn-teal
	 */
	public function testTheJeugdwetCaseTypeIsDrawnTeal(): void {
		self::assertSame(expected: 'teal', actual: $this->caseTypeFile(package: 'jeugdwet')['caseType']['colour']);
	}//end testTheJeugdwetCaseTypeIsDrawnTeal()

	/**
	 * Board DqSociaalDomein draws the Jeugdwet main path only (design-system
	 * #678): the extension request and the withdrawal are off it.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/case-types/spec.md#requirement-a-case-type-marks-its-side-statuses
	 */
	public function testTheJeugdwetSideStatusesAreOffThePath(): void {
		$offPath = [];
		foreach ($this->caseTypeFile(package: 'jeugdwet')['statusTypes'] as $status) {
			if (($status['offPath'] ?? false) === true) {
				$offPath[] = $status['name'];
			}
		}

		self::assertSame(expected: ['Verlenging aangevraagd', 'Ingetrokken'], actual: $offPath);
	}//end testTheJeugdwetSideStatusesAreOffThePath()

	/**
	 * Every shipped package marks its side statuses (order 90 and up: a
	 * withdrawal, an inadmissible objection, a settlement) off the path, so
	 * the stages strip draws the main path the case boards draw.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/case-types/spec.md#requirement-a-case-type-marks-its-side-statuses
	 */
	public function testEveryPackageMarksItsSideStatusesOffThePath(): void {
		$onPath = [];
		foreach (glob(self::PACKAGES . '*/case-type.json') as $file) {
			$package = basename(dirname($file));
			foreach ((array)($this->caseTypeFile(package: $package)['statusTypes'] ?? []) as $status) {
				if ((int)($status['order'] ?? 0) >= 90 && ($status['offPath'] ?? false) !== true) {
					$onPath[] = $package . ': ' . $status['name'];
				}
			}
		}

		self::assertSame(expected: [], actual: $onPath);
	}//end testEveryPackageMarksItsSideStatusesOffThePath()

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
	public function testTheFlowReachesEveryStatusAndGuardsDeclaredFields(
		string $package,
		string $source,
		string $domain,
		array $sensitive,
		string $bsnKey
	): void {
		$file = $this->caseTypeFile(package: $package);
		$flowFile = self::PACKAGES . $package . '/flows/' . $file['caseType']['identifier'] . '.json';
		$flow = $this->json(path: $flowFile);
		self::assertSame(expected: $file['caseType']['workflowDefinition'], actual: $flow['id']);
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
			self::assertArrayHasKey(key: $status['id'], array: $reached, message: $package . ': ' . $status['name'] . ' cannot be reached');
		}

		foreach ($transitions as $transition) {
			foreach ($transition['guards'] as $guard) {
				if (($guard['type'] ?? '') === 'requiredField' && $guard['field'] !== 'result') {
					self::assertContains(needle: $guard['field'], haystack: $names, message: $package . ': ' . $transition['label']);
				}
			}
		}
	}//end testTheFlowReachesEveryStatusAndGuardsDeclaredFields()
}//end class
