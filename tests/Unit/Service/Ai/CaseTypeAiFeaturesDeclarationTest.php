<?php

/**
 * The case type's AI feature declaration exists in the register, says only
 * where each feature appears, and holds no provider, model or residency.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\Ai
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
 * @spec openspec/changes/ai-features-on-the-case-consume-hermiq/specs/ai-features-on-the-case/spec.md#requirement-a-case-type-declares-which-ai-features-are-on-and-where-they-appear-req-aic-01
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Ai;

use OCA\Dossiq\Service\Ai\CaseTypeAiFeatures;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the caseType.aiFeatures register declaration.
 */
class CaseTypeAiFeaturesDeclarationTest extends TestCase {

	/**
	 * The caseType schema as the base register plus every fragment declare it.
	 *
	 * @return array<string, mixed> The merged caseType properties.
	 */
	private function caseTypeProperties(): array {
		$root = dirname(__DIR__, 4) . '/lib/Settings';
		$properties = [];
		foreach (array_merge([$root . '/dossiq_register.json'], (glob($root . '/register.d/*.json') ?: [])) as $file) {
			$decoded = json_decode((string)file_get_contents($file), true);
			$properties = array_merge($properties, ($decoded['components']['schemas']['caseType']['properties'] ?? []));
		}

		return $properties;
	}//end caseTypeProperties()

	public function testTheDeclarationIsAPropertyOfTheCaseType(): void {
		$properties = $this->caseTypeProperties();

		$this->assertArrayHasKey(CaseTypeAiFeatures::DECLARATION, $properties);
		$declaration = $properties[CaseTypeAiFeatures::DECLARATION];
		$this->assertSame('object', $declaration['type']);
		$this->assertSame('string', $declaration['additionalProperties']['type']);
		$this->assertSame(CaseTypeAiFeatures::SURFACES, $declaration['additionalProperties']['enum']);
	}//end testTheDeclarationIsAPropertyOfTheCaseType()

	/**
	 * @spec openspec/changes/ai-features-on-the-case-consume-hermiq/specs/ai-features-on-the-case/spec.md#scenario-dossiq-offers-no-provider-choice
	 */
	public function testTheCaseTypeCarriesNoProviderModelOrResidency(): void {
		foreach (array_keys($this->caseTypeProperties()) as $key) {
			$lower = strtolower((string)$key);
			$this->assertStringNotContainsString('provider', $lower, 'caseType carries ' . $key);
			$this->assertStringNotContainsString('residency', $lower, 'caseType carries ' . $key);
			// `handlingModel` is the Awb handling model, not an AI model.
			$this->assertNotContains($lower, ['model', 'aimodel', 'llmmodel'], 'caseType carries ' . $key);
		}

		$declaration = $this->caseTypeProperties()[CaseTypeAiFeatures::DECLARATION];
		$this->assertArrayNotHasKey('properties', $declaration, 'the declaration holds surfaces only');
	}//end testTheCaseTypeCarriesNoProviderModelOrResidency()

	public function testAValueTheSchemaAllowsIsOneTheReaderKeeps(): void {
		$reader = new CaseTypeAiFeatures();
		$enum = $this->caseTypeProperties()[CaseTypeAiFeatures::DECLARATION]['additionalProperties']['enum'];
		$declared = $reader->declared(caseType: [CaseTypeAiFeatures::DECLARATION => array_combine(['a', 'b', 'c'], $enum)]);

		$this->assertSame(['a' => 'case', 'b' => 'intake', 'c' => 'none'], $declared);
	}//end testAValueTheSchemaAllowsIsOneTheReaderKeeps()
}//end class
