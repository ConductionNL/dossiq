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

	protected function setUp(): void {
		parent::setUp();
		$path = __DIR__ . '/../../../lib/Settings/dossiq_register.json';
		$this->assertFileExists($path);
		$this->register = json_decode((string)file_get_contents($path), true);
		$this->assertIsArray($this->register, 'register template must be valid JSON');
	}

	/**
	 * @return array<string,mixed>
	 */
	private function schema(string $slug): array {
		$schema = $this->register['components']['schemas'][$slug] ?? null;
		$this->assertIsArray($schema, "schema {$slug} must exist");

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
	 * @return array<string,mixed>
	 */
	private function dedup(string $slug): array {
		$schema = $this->schema($slug);
		$dedup = ($schema['configuration']['x-openregister-dedup'] ?? $schema['x-openregister-dedup'] ?? null);
		$this->assertIsArray($dedup, "{$slug} must declare dedup rules");

		return $dedup;
	}

	public function testAnnotatedSchemasCarryQualityAndDedup(): void {
		// The two party schemas score two records of one organisation, where a
		// single exact identifier is close to proof. `case` scores two filings,
		// where no field is proof and the cut-off is what makes one strong
		// signal enough to ask the question (duplicate-warning-at-intake D-1).
		$thresholds = ['case' => 0.3, 'supplier' => 0.7, 'partnerOrganization' => 0.7];

		foreach (self::ANNOTATED_SCHEMAS as $slug) {
			$schema = $this->schema($slug);
			$this->assertArrayHasKey('x-openregister-quality', $schema, "{$slug} must declare quality rules");

			$quality = $schema['x-openregister-quality'];
			$this->assertSame('qualityScore', $quality['field']);
			$this->assertSame('qualityStatus', $quality['statusField']);
			$this->assertSame(['good' => 0.8, 'fair' => 0.5], $quality['thresholds']);
			$this->assertNotEmpty($quality['rules']);

			$dedup = $this->dedup($slug);
			$this->assertSame($thresholds[$slug], $dedup['threshold']);
			$this->assertNotEmpty($dedup['matchRules']);
		}
	}

	public function testCaseDeclaresItsDedupInsideConfiguration(): void {
		// 🔴 THE PLACEMENT IS THE BUG THIS PINS. `SchemaAnnotationReconciler`
		// reads `configuration` and nothing else, so a block declared beside
		// `properties` is never carried onto an instance that imported the case
		// schema before the block existed. The dedup endpoint then answers an
		// empty match list there, which reads as "nothing looks like this case".
		$schema = $this->schema('case');
		$this->assertArrayHasKey('x-openregister-dedup', $schema['configuration']);
		$this->assertArrayNotHasKey('x-openregister-dedup', $schema);
	}

	public function testCaseDedupDeclaresWhoMayFileOverAWarning(): void {
		$dedup = $this->dedup('case');
		$this->assertSame('warn', $dedup['onCreate'], 'the per-case-type policy decides blocking, not the schema');
		$this->assertSame(['dossiq-coordinators'], $dedup['overrideGroups']);
		$this->assertSame(['dossiq-coordinators'], $dedup['dismissGroups']);
	}

	public function testTheCaseTypeDeclaresItsDuplicatePolicy(): void {
		$policy = $this->schema('caseType')['properties']['duplicatePolicy'] ?? null;
		$this->assertIsArray($policy, 'caseType must declare duplicatePolicy');
		$this->assertSame(['warn', 'block'], $policy['enum']);
		$this->assertSame('warn', $policy['default'], 'a case type that says nothing must keep today behaviour');
		$this->assertNotEmpty($policy['title'] ?? '');
		$this->assertNotEmpty($policy['description'] ?? '');
	}

	public function testCaseDedupGuardsDsoDoubleIntakeAndTheSecondFiling(): void {
		$dedup = $this->dedup('case');
		$this->assertSame(['caseType'], $dedup['blockingKeys'], 'case candidates must be blocked per zaaktype');

		$byField = [];
		foreach ($dedup['matchRules'] as $rule) {
			$byField[$rule['field']][] = $rule;
		}

		$this->assertSame('exact', $byField['permitApplicationRef'][0]['method'], 'DSO re-delivery must match on vergunningaanvraagRef');
		$this->assertSame('exact', $byField['requester'][0]['method'], 'the same applicant filing twice is the intake warning');
		$this->assertCount(2, $byField['title'], 'title must match normalized + levenshtein');

		// `identifier` is deliberately NOT a rule any more. The case number is
		// minted per case by the platform sequence, so two cases never carry the
		// same one; as an `exact` rule it could only ever score 0 and pull the
		// weighted average DOWN, which is the opposite of what a match rule is
		// for.
		$this->assertArrayNotHasKey('identifier', $byField);

		// Each single signal has to clear the cut-off on its own, and no signal
		// may clear it on a near miss. That is the whole of D-1 expressed in
		// the one vocabulary OpenRegister has: a weighted average.
		$total = array_sum(array_column($dedup['matchRules'], 'weight'));
		$this->assertSame(1.0, round($total, 4), 'the weights must sum to one, or the cut-off means nothing');
		$this->assertGreaterThanOrEqual($dedup['threshold'], $byField['requester'][0]['weight']);
		$this->assertGreaterThanOrEqual($dedup['threshold'], $byField['permitApplicationRef'][0]['weight']);
	}

	public function testSupplierDedupMatchesOrgMasterRules(): void {
		$dedup = $this->dedup('supplier');
		$methods = [];
		foreach ($dedup['matchRules'] as $rule) {
			$methods[$rule['field'] . ':' . $rule['method']] = $rule['weight'];
		}
		$this->assertSame(0.4, $methods['kvkNumber:exact']);
		$this->assertSame(0.3, $methods['iban:exact']);
		$this->assertArrayHasKey('legalName:normalized', $methods);
		$this->assertArrayHasKey('legalName:levenshtein', $methods);

		$formatRules = array_filter(
			$this->schema('supplier')['x-openregister-quality']['rules'],
			static fn (array $rule): bool => $rule['type'] === 'format' && $rule['field'] === 'kvkNumber'
		);
		$this->assertCount(1, $formatRules, 'supplier quality must format-check kvkNumber');
		$this->assertSame('^[0-9]{8}$', array_values($formatRules)[0]['pattern']);
	}

	public function testPartnerOrganizationDedupMatchesOinFirst(): void {
		$dedup = $this->dedup('partnerOrganization');
		$first = $dedup['matchRules'][0];
		$this->assertSame(['oin', 'exact', 0.5], [$first['field'], $first['method'], $first['weight']]);

		$emailRules = array_filter(
			$this->schema('partnerOrganization')['x-openregister-quality']['rules'],
			static fn (array $rule): bool => $rule['type'] === 'format' && $rule['field'] === 'contactEmail'
		);
		$this->assertCount(1, $emailRules, 'partnerOrganization quality must format-check contactEmail');
	}

	public function testDedupRuleFieldsExistOnTheSchema(): void {
		foreach (self::ANNOTATED_SCHEMAS as $slug) {
			$schema = $this->schema($slug);
			$properties = $schema['properties'] ?? [];
			foreach ($this->dedup($slug)['matchRules'] as $rule) {
				$this->assertArrayHasKey($rule['field'], $properties, "{$slug} dedup field {$rule['field']} must be a declared property");
			}
			foreach ($schema['x-openregister-quality']['rules'] as $rule) {
				$this->assertArrayHasKey($rule['field'], $properties, "{$slug} quality field {$rule['field']} must be a declared property");
			}
		}
	}

	public function testMaterialisedQualityFieldsAreDeclaredWithTitles(): void {
		foreach (self::ANNOTATED_SCHEMAS as $slug) {
			$properties = $this->schema($slug)['properties'] ?? [];
			foreach (['qualityScore', 'qualityStatus'] as $field) {
				$this->assertArrayHasKey($field, $properties, "{$slug} must declare {$field}");
				$this->assertNotEmpty($properties[$field]['title'] ?? '', "{$slug}.{$field} must carry a title (ADR-011)");
				$this->assertNotEmpty($properties[$field]['description'] ?? '', "{$slug}.{$field} must carry a description (ADR-011)");
			}
			$this->assertSame(['good', 'fair', 'poor'], $properties['qualityStatus']['enum']);
		}
	}

	public function testNoSurvivorshipIsDeclaredAnywhere(): void {
		foreach ($this->register['components']['schemas'] as $slug => $schema) {
			$this->assertArrayNotHasKey(
				'x-openregister-survivorship',
				$schema,
				"{$slug} must not declare survivorship — dossiq has no trust-tiered source-record schema"
			);
		}
	}
}
