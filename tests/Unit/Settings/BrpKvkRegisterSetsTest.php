<?php

/**
 * BRP/KvK Register Sets Test (brp-kvk-register-sets)
 *
 * Verifies the ADR-037 fragment lib/Settings/register.d/25-brp-kvk.json:
 * schema shapes (Haal Centraal / KvK Zoeken naming, OR bsn format, ADR-011
 * titles+descriptions on every property), the pinned official fixture
 * seeds (personen-mock personas, 11-proef valid; KvK-published fictitious
 * companies incl the four pinned numbers), provenance descriptions on
 * every row, and the additive initiator projection fields on the case
 * schema (union-merge guard: required arrays untouched).
 *
 * @category Tests
 * @package  OCA\Procest\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/specs/brp-register/spec.md
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Procest\Repair\InitializeSettings
 */
class BrpKvkRegisterSetsTest extends TestCase {
	private const PINNED_KVK = ['69599084', '68750110', '69599068', '55344526'];

	/**
	 * @var array<string,mixed>
	 */
	private array $fragment;

	protected function setUp(): void {
		parent::setUp();
		$path = __DIR__ . '/../../../lib/Settings/register.d/25-brp-kvk.json';
		$this->assertFileExists($path);
		$this->fragment = json_decode((string)file_get_contents($path), true);
		$this->assertIsArray($this->fragment);

	}//end setUp()

	/**
	 * Both schemas exist, are attached to the procest register, and use
	 * the official API field naming.
	 *
	 * @return void
	 */
	public function testSchemasFollowOfficialApiNaming(): void {
		$schemas = $this->fragment['components']['schemas'];
		$this->assertArrayHasKey('brpPerson', $schemas);
		$this->assertArrayHasKey('kvkCompany', $schemas);
		$this->assertContains('brpPerson', $this->fragment['components']['registers']['procest']['schemas']);
		$this->assertContains('kvkCompany', $this->fragment['components']['registers']['procest']['schemas']);

		$person = $schemas['brpPerson']['properties'];
		// 'bsn' is not a registered OpenRegister string format (see the field's
		// own description); the fragment intentionally pattern-validates the
		// nine-digit BSN instead (ADR-011 — no procest-side validator).
		$this->assertSame('^[0-9]{9}$', $person['citizenServiceNumber']['pattern'], 'BSN must be pattern-validated as nine digits (ADR-011)');
		foreach (['name', 'birth', 'residence'] as $block) {
			$this->assertArrayHasKey($block, $person, "Haal Centraal block {$block} required");
		}

		$company = $schemas['kvkCompany']['properties'];
		foreach (['kvkNumber', 'tradeName', 'legalForm', 'address'] as $field) {
			$this->assertArrayHasKey($field, $company, "KvK Zoeken field {$field} required");
		}

		$this->assertSame('^[0-9]{8}$', $company['kvkNumber']['pattern']);
		$this->assertSame('schema:Person', $schemas['brpPerson']['x-schema-org']);
		$this->assertSame('schema:Organization', $schemas['kvkCompany']['x-schema-org']);

	}//end testSchemasFollowOfficialApiNaming()

	/**
	 * Every property — nested included — carries a title and description
	 * (ADR-011 / gate-28).
	 *
	 * @return void
	 */
	public function testEveryPropertyCarriesTitleAndDescription(): void {
		$walk = function (array $properties, string $path) use (&$walk): void {
			foreach ($properties as $name => $property) {
				$this->assertNotEmpty($property['title'] ?? '', "{$path}.{$name} missing title (ADR-011)");
				$this->assertNotEmpty($property['description'] ?? '', "{$path}.{$name} missing description (ADR-011)");
				if (($property['type'] ?? '') === 'object' && isset($property['properties'])) {
					$walk($property['properties'], "{$path}.{$name}");
				}
			}
		};

		foreach (['brpPerson', 'kvkCompany'] as $slug) {
			$walk($this->fragment['components']['schemas'][$slug]['properties'], $slug);
		}

	}//end testEveryPropertyCarriesTitleAndDescription()

	/**
	 * Ten personen-mock personas are seeded, every BSN passes the
	 * 11-proef (so OR's bsn format can never reject the import), and
	 * every row carries the fictitious-fixture provenance description.
	 *
	 * @return void
	 */
	public function testSeedPersonsArePinnedMockPersonas(): void {
		$persons = $this->seedRows(schema: 'brpPerson');
		$this->assertCount(10, $persons);

		foreach ($persons as $person) {
			$bsn = $person['citizenServiceNumber'];
			$this->assertTrue($this->elfproef(bsn: $bsn), "seed BSN {$bsn} must be 11-proef valid");
			$this->assertStringContainsString('personen-mock', $person['description'], 'row must name its fixture source');
			$this->assertNotEmpty($person['name']['surname']);
			$this->assertNotEmpty($person['birth']['date']);
			$this->assertNotEmpty($person['displayName']);
			$this->assertSame('procest', $person['@self']['register']);
		}

		$bsns = array_column($persons, 'citizenServiceNumber');
		$this->assertSame($bsns, array_unique($bsns), 'seed BSNs must be unique');

	}//end testSeedPersonsArePinnedMockPersonas()

	/**
	 * Ten KvK-published fictitious companies are seeded including the
	 * four pinned contract-lane fixtures, with provenance descriptions.
	 *
	 * @return void
	 */
	public function testSeedCompaniesIncludePinnedFixtures(): void {
		$companies = $this->seedRows(schema: 'kvkCompany');
		$this->assertCount(10, $companies);

		$numbers = array_column($companies, 'kvkNumber');
		foreach (self::PINNED_KVK as $pinned) {
			$this->assertContains($pinned, $numbers, "pinned fixture KVK {$pinned} must be seeded");
		}

		foreach ($companies as $company) {
			$this->assertMatchesRegularExpression('/^[0-9]{8}$/', $company['kvkNumber']);
			$this->assertNotEmpty($company['tradeName']);
			$this->assertNotEmpty($company['legalForm']);
			$this->assertStringContainsString('developers.kvk.nl', $company['description'], 'row must name its fixture source');
		}

		$this->assertSame($numbers, array_unique($numbers), 'seed KvK numbers must be unique');

	}//end testSeedCompaniesIncludePinnedFixtures()

	/**
	 * The case schema carries the three optional initiator projection
	 * fields with ADR-011 titles, initiatorType is the fixed enum, and
	 * none of them landed in the required array (additive guarantee).
	 *
	 * @return void
	 */
	public function testCaseSchemaInitiatorFieldsAreAdditive(): void {
		$register = json_decode(
			(string)file_get_contents(__DIR__ . '/../../../lib/Settings/procest_register.json'),
			true
		);
		$case = $register['components']['schemas']['case'];

		foreach (['initiatorType', 'initiatorSourceId', 'initiatorDisplayName'] as $field) {
			$this->assertArrayHasKey($field, $case['properties'], "case.{$field} must exist");
			$this->assertNotEmpty($case['properties'][$field]['title'] ?? '', "case.{$field} missing title (ADR-011)");
			$this->assertNotEmpty($case['properties'][$field]['description'] ?? '', "case.{$field} missing description (ADR-011)");
			$this->assertNotContains($field, ($case['required'] ?? []), "case.{$field} must stay optional (additive)");
		}

		$this->assertSame(
			['person', 'company', 'contact'],
			$case['properties']['initiatorType']['enum']
		);

	}//end testCaseSchemaInitiatorFieldsAreAdditive()

	/**
	 * The union-merged register (monolith + all fragments) still parses
	 * and the case schema's required array survives the deep-merge
	 * (union-merge corruption guard, V01/V04).
	 *
	 * @return void
	 */
	public function testMergedRegisterSurvivesFragmentUnion(): void {
		$required = json_decode(
			(string)file_get_contents(__DIR__ . '/../../../lib/Settings/procest_register.json'),
			true
		)['components']['schemas']['case']['required'];

		foreach (glob(__DIR__ . '/../../../lib/Settings/register.d/*.json') as $file) {
			$fragment = json_decode((string)file_get_contents($file), true);
			$this->assertIsArray($fragment, basename($file) . ' must be valid JSON');
			$fragmentCase = $fragment['components']['schemas']['case'] ?? null;
			if ($fragmentCase !== null && isset($fragmentCase['required'])) {
				$this->fail(basename($file) . ' redefines case.required — list-concat union-merge would corrupt it');
			}
		}

		$this->assertNotEmpty($required, 'case.required must be intact in the monolith');

	}//end testMergedRegisterSurvivesFragmentUnion()

	/**
	 * Seed rows for a schema.
	 *
	 * @param string $schema Schema slug.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function seedRows(string $schema): array {
		return array_values(array_filter(
			$this->fragment['components']['objects'],
			static fn (array $row): bool => ($row['@self']['schema'] ?? '') === $schema
		));

	}//end seedRows()

	/**
	 * Dutch BSN 11-proef.
	 *
	 * @param string $bsn Nine-digit BSN.
	 *
	 * @return bool
	 */
	private function elfproef(string $bsn): bool {
		if (preg_match('/^[0-9]{9}$/', $bsn) !== 1) {
			return false;
		}

		$weights = [9, 8, 7, 6, 5, 4, 3, 2, -1];
		$sum = 0;
		foreach (str_split($bsn) as $index => $digit) {
			$sum += ((int)$digit * $weights[$index]);
		}

		return ($sum % 11) === 0;
	}//end elfproef()
}//end class
