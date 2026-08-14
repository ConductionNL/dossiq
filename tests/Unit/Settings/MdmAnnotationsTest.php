<?php

/**
 * MDM Annotations Test (consume-or-mdm)
 *
 * Verifies procest's ADR-045 consumer posture: the case, supplier and
 * partnerOrganization schemas in the register template declare the
 * x-openregister-quality and x-openregister-dedup annotations exactly as
 * fixed in the change design, declare the OR-materialised qualityScore /
 * qualityStatus fields, and declare NO x-openregister-survivorship
 * (no trust-tiered source-record schema exists in procest).
 *
 * @category Tests
 * @package  OCA\Procest\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/specs/master-data-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Procest\Repair\InitializeSettings
 */
class MdmAnnotationsTest extends TestCase {
	private const ANNOTATED_SCHEMAS = ['case', 'supplier', 'partnerOrganization'];

	/**
	 * @var array<string,mixed>
	 */
	private array $register;

	protected function setUp(): void {
		parent::setUp();
		$path = __DIR__ . '/../../../lib/Settings/procest_register.json';
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

	public function testAnnotatedSchemasCarryQualityAndDedup(): void {
		foreach (self::ANNOTATED_SCHEMAS as $slug) {
			$schema = $this->schema($slug);
			$this->assertArrayHasKey('x-openregister-quality', $schema, "{$slug} must declare quality rules");
			$this->assertArrayHasKey('x-openregister-dedup', $schema, "{$slug} must declare dedup rules");

			$quality = $schema['x-openregister-quality'];
			$this->assertSame('qualityScore', $quality['field']);
			$this->assertSame('qualityStatus', $quality['statusField']);
			$this->assertSame(['good' => 0.8, 'fair' => 0.5], $quality['thresholds']);
			$this->assertNotEmpty($quality['rules']);

			$dedup = $schema['x-openregister-dedup'];
			$this->assertSame(0.7, $dedup['threshold']);
			$this->assertNotEmpty($dedup['matchRules']);
		}
	}

	public function testCaseDedupGuardsDsoDoubleIntake(): void {
		$dedup = $this->schema('case')['x-openregister-dedup'];
		$this->assertSame(['caseType'], $dedup['blockingKeys'], 'case candidates must be blocked per zaaktype');

		$byField = [];
		foreach ($dedup['matchRules'] as $rule) {
			$byField[$rule['field']][] = $rule;
		}
		$this->assertSame('exact', $byField['identifier'][0]['method']);
		$this->assertSame(0.4, $byField['identifier'][0]['weight']);
		$this->assertSame('exact', $byField['permitApplicationRef'][0]['method'], 'DSO re-delivery must match on vergunningaanvraagRef');
		$this->assertCount(2, $byField['title'], 'title must match normalized + levenshtein');
	}

	public function testSupplierDedupMatchesOrgMasterRules(): void {
		$dedup = $this->schema('supplier')['x-openregister-dedup'];
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
		$dedup = $this->schema('partnerOrganization')['x-openregister-dedup'];
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
			foreach ($schema['x-openregister-dedup']['matchRules'] as $rule) {
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
				"{$slug} must not declare survivorship — procest has no trust-tiered source-record schema"
			);
		}
	}
}
