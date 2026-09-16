<?php

/**
 * MDM Annotations Test (consume-or-mdm)
 *
 * Verifies dossiq's ADR-045 consumer posture: the case, supplier and
 * partnerOrganization schemas in the register template declare the
 * x-openregister-quality and x-openregister-dedup annotations exactly as
 * fixed in the change design, declare the OR-materialised qualityScore /
 * qualityStatus fields, and declare NO x-openregister-survivorship
 * (no trust-tiered source-record schema exists in dossiq).
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/specs/master-data-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Repair\InitializeSettings
 */
class MdmAnnotationsTest extends TestCase {
	private const ANNOTATED_SCHEMAS = ['case', 'supplier', 'partnerOrganization'];

	/**
	 * @var array<string,mixed>
	 */
	private array $register;

	/**
	 * Read the register template every assertion below reads from.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$path = __DIR__ . '/../../../lib/Settings/dossiq_register.json';
		$this->assertFileExists(filename: $path);
		$this->register = json_decode((string)file_get_contents($path), true);
		$this->assertIsArray(actual: $this->register, message: 'register template must be valid JSON');
	}

	/**
	 * One schema out of the register template.
	 *
	 * @param string $slug The schema slug.
	 *
	 * @return array<string,mixed>
	 */
	private function schema(string $slug): array {
		$schema = $this->register['components']['schemas'][$slug] ?? null;
		$this->assertIsArray(actual: $schema, message: "schema {$slug} must exist");

		return $schema;
	}

	/**
	 * The dedup block, wherever the schema declares it.
	 *
	 * OpenRegister reads this block off `Schema::getConfiguration()`. A
	 * top-level declaration reaches the same place, because `Schema::hydrate()`
	 * folds every top-level `x-openregister-*` key into the configuration. Both
	 * spellings are therefore live, and this reader accepts either so a schema
	 * moving its block does not look like a schema losing it.
	 *
	 * `case` declares it inside `configuration` on purpose: that is the only
	 * spelling `SchemaAnnotationReconciler` carries onto an instance that
	 * imported the schema before the block existed.
	 *
	 * @param string $slug The schema slug.
	 *
	 * @return array<string,mixed>
	 */
	private function dedup(string $slug): array {
		$schema = $this->schema(slug: $slug);
		$dedup = ($schema['configuration']['x-openregister-dedup'] ?? $schema['x-openregister-dedup'] ?? null);
		$this->assertIsArray(actual: $dedup, message: "{$slug} must declare dedup rules");

		return $dedup;
	}

	/**
	 * Each annotated schema declares both blocks, with its own cut-off.
	 *
	 * @return void
	 */
	public function testAnnotatedSchemasCarryQualityAndDedup(): void {
		// The two party schemas score two records of one organisation, where a
		// single exact identifier is close to proof. `case` scores two filings,
		// where no field is proof and the cut-off is what makes one strong
		// signal enough to ask the question (duplicate-warning-at-intake D-1).
		$thresholds = ['case' => 0.3, 'supplier' => 0.7, 'partnerOrganization' => 0.7];

		foreach (self::ANNOTATED_SCHEMAS as $slug) {
			$schema = $this->schema(slug: $slug);
			$this->assertArrayHasKey(key: 'x-openregister-quality', array: $schema, message: "{$slug} must declare quality rules");

			$quality = $schema['x-openregister-quality'];
			$this->assertSame(expected: 'qualityScore', actual: $quality['field']);
			$this->assertSame(expected: 'qualityStatus', actual: $quality['statusField']);
			$this->assertSame(expected: ['good' => 0.8, 'fair' => 0.5], actual: $quality['thresholds']);
			$this->assertNotEmpty(actual: $quality['rules']);

			$dedup = $this->dedup(slug: $slug);
			$this->assertSame(expected: $thresholds[$slug], actual: $dedup['threshold']);
			$this->assertNotEmpty(actual: $dedup['matchRules']);
		}
	}

	/**
	 * The case dedup block sits where the reconciler can carry it.
	 *
	 * @return void
	 */
	public function testCaseDeclaresItsDedupInsideConfiguration(): void {
		// 🔴 THE PLACEMENT IS THE BUG THIS PINS. `SchemaAnnotationReconciler`
		// reads `configuration` and nothing else, so a block declared beside
		// `properties` is never carried onto an instance that imported the case
		// schema before the block existed. The dedup endpoint then answers an
		// empty match list there, which reads as "nothing looks like this case".
		$schema = $this->schema(slug: 'case');
		$this->assertArrayHasKey(key: 'x-openregister-dedup', array: $schema['configuration']);
		$this->assertArrayNotHasKey(key: 'x-openregister-dedup', array: $schema);
	}

	/**
	 * The case schema names who may file over a warning, and who may rule a pair out.
	 *
	 * @return void
	 */
	public function testCaseDedupDeclaresWhoMayFileOverAWarning(): void {
		$dedup = $this->dedup(slug: 'case');
		$this->assertSame(expected: 'warn', actual: $dedup['onCreate'], message: 'the per-case-type policy decides blocking, not the schema');
		$this->assertSame(expected: ['dossiq-coordinators'], actual: $dedup['overrideGroups']);
		$this->assertSame(expected: ['dossiq-coordinators'], actual: $dedup['dismissGroups']);
	}

	/**
	 * A case type declares what to do about a case that already exists.
	 *
	 * @return void
	 */
	public function testTheCaseTypeDeclaresItsDuplicatePolicy(): void {
		$policy = $this->schema(slug: 'caseType')['properties']['duplicatePolicy'] ?? null;
		$this->assertIsArray(actual: $policy, message: 'caseType must declare duplicatePolicy');
		$this->assertSame(expected: ['warn', 'block'], actual: $policy['enum']);
		$this->assertSame(expected: 'warn', actual: $policy['default'], message: 'a case type that says nothing must keep today behaviour');
		$this->assertNotEmpty(actual: $policy['title'] ?? '');
		$this->assertNotEmpty(actual: $policy['description'] ?? '');
	}

	/**
	 * The case rules catch a DSO re-delivery and a second filing by the same applicant.
	 *
	 * @return void
	 */
	public function testCaseDedupGuardsDsoDoubleIntakeAndTheSecondFiling(): void {
		$dedup = $this->dedup(slug: 'case');
		$this->assertSame(expected: ['caseType'], actual: $dedup['blockingKeys'], message: 'case candidates must be blocked per zaaktype');

		$byField = [];
		foreach ($dedup['matchRules'] as $rule) {
			$byField[$rule['field']][] = $rule;
		}

		$this->assertSame(
			expected: 'exact',
			actual: $byField['permitApplicationRef'][0]['method'],
			message: 'DSO re-delivery must match on vergunningaanvraagRef'
		);
		$this->assertSame(expected: 'exact', actual: $byField['requester'][0]['method'], message: 'the same applicant filing twice is the intake warning');
		$this->assertCount(expectedCount: 2, haystack: $byField['title'], message: 'title must match normalized + levenshtein');

		// `identifier` is deliberately NOT a rule any more. The case number is
		// minted per case by the platform sequence, so two cases never carry the
		// same one; as an `exact` rule it could only ever score 0 and pull the
		// weighted average DOWN, which is the opposite of what a match rule is
		// for.
		$this->assertArrayNotHasKey(key: 'identifier', array: $byField);

		// Each single signal has to clear the cut-off on its own, and no signal
		// may clear it on a near miss. That is the whole of D-1 expressed in
		// the one vocabulary OpenRegister has: a weighted average.
		$total = array_sum(array_column($dedup['matchRules'], 'weight'));
		$this->assertSame(expected: 1.0, actual: round($total, 4), message: 'the weights must sum to one, or the cut-off means nothing');
		$this->assertGreaterThanOrEqual(expected: $dedup['threshold'], actual: $byField['requester'][0]['weight']);
		$this->assertGreaterThanOrEqual(expected: $dedup['threshold'], actual: $byField['permitApplicationRef'][0]['weight']);
	}

	/**
	 * The supplier rules match the organisation master data rules.
	 *
	 * @return void
	 */
	public function testSupplierDedupMatchesOrgMasterRules(): void {
		$dedup = $this->dedup(slug: 'supplier');
		$methods = [];
		foreach ($dedup['matchRules'] as $rule) {
			$methods[$rule['field'] . ':' . $rule['method']] = $rule['weight'];
		}
		$this->assertSame(expected: 0.4, actual: $methods['kvkNumber:exact']);
		$this->assertSame(expected: 0.3, actual: $methods['iban:exact']);
		$this->assertArrayHasKey(key: 'legalName:normalized', array: $methods);
		$this->assertArrayHasKey(key: 'legalName:levenshtein', array: $methods);

		$formatRules = array_filter(
			$this->schema(slug: 'supplier')['x-openregister-quality']['rules'],
			static fn (array $rule): bool => $rule['type'] === 'format' && $rule['field'] === 'kvkNumber'
		);
		$this->assertCount(expectedCount: 1, haystack: $formatRules, message: 'supplier quality must format-check kvkNumber');
		$this->assertSame(expected: '^[0-9]{8}$', actual: array_values($formatRules)[0]['pattern']);
	}

	/**
	 * The partner organisation rules put the OIN first.
	 *
	 * @return void
	 */
	public function testPartnerOrganizationDedupMatchesOinFirst(): void {
		$dedup = $this->dedup(slug: 'partnerOrganization');
		$first = $dedup['matchRules'][0];
		$this->assertSame(expected: ['oin', 'exact', 0.5], actual: [$first['field'], $first['method'], $first['weight']]);

		$emailRules = array_filter(
			$this->schema(slug: 'partnerOrganization')['x-openregister-quality']['rules'],
			static fn (array $rule): bool => $rule['type'] === 'format' && $rule['field'] === 'contactEmail'
		);
		$this->assertCount(expectedCount: 1, haystack: $emailRules, message: 'partnerOrganization quality must format-check contactEmail');
	}

	/**
	 * Every rule names a property the schema actually declares.
	 *
	 * @return void
	 */
	public function testDedupRuleFieldsExistOnTheSchema(): void {
		foreach (self::ANNOTATED_SCHEMAS as $slug) {
			$schema = $this->schema(slug: $slug);
			$properties = $schema['properties'] ?? [];
			foreach ($this->dedup(slug: $slug)['matchRules'] as $rule) {
				$this->assertArrayHasKey(key: $rule['field'], array: $properties, message: "{$slug} dedup field {$rule['field']} must be a declared property");
			}
			foreach ($schema['x-openregister-quality']['rules'] as $rule) {
				$this->assertArrayHasKey(key: $rule['field'], array: $properties, message: "{$slug} quality field {$rule['field']} must be a declared property");
			}
		}
	}

	/**
	 * The quality fields OpenRegister materialises are declared with their prose.
	 *
	 * @return void
	 */
	public function testMaterialisedQualityFieldsAreDeclaredWithTitles(): void {
		foreach (self::ANNOTATED_SCHEMAS as $slug) {
			$properties = $this->schema(slug: $slug)['properties'] ?? [];
			foreach (['qualityScore', 'qualityStatus'] as $field) {
				$this->assertArrayHasKey(key: $field, array: $properties, message: "{$slug} must declare {$field}");
				$this->assertNotEmpty(actual: $properties[$field]['title'] ?? '', message: "{$slug}.{$field} must carry a title (ADR-011)");
				$this->assertNotEmpty(actual: $properties[$field]['description'] ?? '', message: "{$slug}.{$field} must carry a description (ADR-011)");
			}
			$this->assertSame(expected: ['good', 'fair', 'poor'], actual: $properties['qualityStatus']['enum']);
		}
	}

	/**
	 * Dossiq declares no survivorship, having no trust-tiered source-record schema.
	 *
	 * @return void
	 */
	public function testNoSurvivorshipIsDeclaredAnywhere(): void {
		foreach ($this->register['components']['schemas'] as $slug => $schema) {
			$this->assertArrayNotHasKey(
				key: 'x-openregister-survivorship',
				array: $schema,
				message: "{$slug} must not declare survivorship — dossiq has no trust-tiered source-record schema"
			);
		}
	}
}
