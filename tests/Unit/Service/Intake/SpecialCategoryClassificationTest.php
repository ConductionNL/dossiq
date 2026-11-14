<?php

/**
 * Special Category Classification Test
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Intake
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/social-domain-consent-and-safeguards/specs/dossiq-sociaal-domein-avg-consent/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Intake;

use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\Intake\SpecialCategoryClassification;
use OCA\Dossiq\Tests\Support\RealSchemaValidator;
use PHPUnit\Framework\TestCase;

/**
 * REQ-SDC-SAFE-01: a case type that requires its special categories of personal data classified
 * refuses a case without them, and keeps them within the categories it lists.
 *
 * @covers \OCA\Dossiq\Service\Intake\SpecialCategoryClassification
 * @uses   \OCA\Dossiq\Exception\RefusedException
 */
class SpecialCategoryClassificationTest extends TestCase {

	private SpecialCategoryClassification $classification;

	protected function setUp(): void {
		$this->classification = new SpecialCategoryClassification();
	}//end setUp()

	/**
	 * The refusal of a case, or null when it is let through.
	 *
	 * @param array<string, mixed> $case     The case.
	 * @param array<string, mixed> $caseType The case type.
	 *
	 * @return RefusedException|null
	 */
	private function refusal(array $case, array $caseType): ?RefusedException {
		try {
			$this->classification->assertCreatable(case: $case, caseType: $caseType);
		} catch (RefusedException $e) {
			return $e;
		}

		return null;
	}//end refusal()

	/**
	 * Scenario "Intake asks for the classification": a Wmo case without one is refused, naming the field.
	 *
	 * @return void
	 */
	public function testACaseWithoutItsClassificationIsRefused(): void {
		$wmo = ['requiresSpecialCategory' => true, 'specialDataCategories' => ['health']];

		foreach ([[], ['gdprClassification' => []], ['gdprClassification' => ['', ' ']], ['gdprClassification' => 7]] as $case) {
			$refusal = $this->refusal(case: $case, caseType: $wmo);
			$this->assertNotNull($refusal, json_encode($case));
			$this->assertSame(SpecialCategoryClassification::RULE_REQUIRED, $refusal->getRule());
			$this->assertSame(RefusedException::STATUS_UNPROCESSABLE, $refusal->getStatus());
			$this->assertStringContainsString('gdprClassification', $refusal->getSentence());
		}
	}//end testACaseWithoutItsClassificationIsRefused()

	/**
	 * A category the case type does not list is refused; one it lists, or one name, is let through.
	 *
	 * @return void
	 */
	public function testTheClassificationStaysWithinTheCaseTypesCategories(): void {
		$wmo = ['requiresSpecialCategory' => true, 'specialDataCategories' => ['health', 'criminal']];

		$refusal = $this->refusal(case: ['gdprClassification' => ['health', 'religion']], caseType: $wmo);
		$this->assertNotNull($refusal);
		$this->assertSame(SpecialCategoryClassification::RULE_OUTSIDE, $refusal->getRule());
		$this->assertSame('This case type does not process religion; choose from health, criminal (field gdprClassification).', $refusal->getSentence());

		$this->assertNull($this->refusal(case: ['gdprClassification' => ['health', 'criminal']], caseType: $wmo));
		$this->assertNull($this->refusal(case: ['gdprClassification' => 'health'], caseType: $wmo));
		$this->assertNull($this->refusal(case: ['gdprClassification' => ['religion']], caseType: ['requiresSpecialCategory' => true]), 'a case type that lists no categories accepts any');
	}//end testTheClassificationStaysWithinTheCaseTypesCategories()

	/**
	 * A case type that does not set the flag asks nothing.
	 *
	 * @return void
	 */
	public function testACaseTypeWithoutTheFlagAsksNothing(): void {
		$this->assertNull($this->refusal(case: [], caseType: []));
		$this->assertNull($this->refusal(case: [], caseType: ['requiresSpecialCategory' => 'yes', 'specialDataCategories' => ['health']]));
	}//end testACaseTypeWithoutTheFlagAsksNothing()

	/**
	 * The case schema declares the field with the categories of the case type's list, so
	 * OpenRegister keeps what the intake checked; the social packages that set the flag list them.
	 *
	 * @return void
	 */
	public function testTheCaseSchemaDeclaresTheClassification(): void {
		$real = new RealSchemaValidator();
		$field = $real->schemas['case']['properties']['gdprClassification'];
		$listed = $real->schemas['caseType']['properties']['specialDataCategories']['items']['enum'];

		$this->assertSame('array', $field['type']);
		$this->assertSame($listed, $field['items']['enum']);
	}//end testTheCaseSchemaDeclaresTheClassification()
}//end class
