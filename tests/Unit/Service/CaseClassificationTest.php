<?php

/**
 * The classification that is an access rule, and the three ways it refuses.
 *
 * OpenCase's clause is the whole test: an unclassified case is unreachable
 * rather than merely untidy. So a case type that marks its classification an
 * access rule refuses an unclassified case outright, and the four facets it
 * declares are what the case records.
 *
 * The scheme is driven separately, because ADR-102 fails closed on config
 * absence. Naming a scheme this instance cannot resolve must refuse, and it
 * must say "this instance does not know the scheme" rather than "your
 * classification is wrong", which sends an administrator looking in the wrong
 * place.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/intake-triage-and-refusal/specs/semantic-case-intake/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\Intake\CaseClassification;
use OCA\Dossiq\Service\Intake\ClassificationSchemes;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the four facets, the access rule and the scheme.
 *
 * @covers \OCA\Dossiq\Service\Intake\CaseClassification
 * @covers \OCA\Dossiq\Service\Intake\ClassificationSchemes
 * @uses \OCA\Dossiq\Exception\RefusedException
 */
class CaseClassificationTest extends TestCase {

	/**
	 * Build a classification reader over an administered scheme list.
	 *
	 * @param string $administered The JSON the administrator wrote, or ''.
	 *
	 * @return CaseClassification The reader.
	 */
	private function classification(string $administered = ''): CaseClassification {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = '') use ($administered): string {
				if ($key === ClassificationSchemes::SCHEMES_KEY) {
					return $administered;
				}

				return $default;
			}
		);

		return new CaseClassification(schemes: new ClassificationSchemes(appConfig: $appConfig));
	}//end classification()

	/**
	 * An unclassified case is not created where the classification is the rule.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/intake-triage-and-refusal/specs/semantic-case-intake/spec.md#requirement-a-classification-that-is-an-access-rule-is-required-before-the-case-exists-req-triage-02
	 */
	public function testAnUnclassifiedCaseIsNotCreated(): void {
		$caseType = [
			'caseClassification' => [
				'scheme' => 'vertrouwelijkheidaanduiding',
				'classificationIsAccessRule' => true,
			],
		];

		try {
			$this->classification()->assertCreatable(case: ['title' => 'Bezwaar'], caseType: $caseType);
			$this->fail('The creation should have been refused.');
		} catch (RefusedException $e) {
			$this->assertSame(CaseClassification::RULE_UNCLASSIFIED, $e->getRule());
			$this->assertStringContainsString('classification', $e->getSentence());
		}
	}//end testAnUnclassifiedCaseIsNotCreated()

	/**
	 * A case type that does not make it the access rule creates without one.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/intake-triage-and-refusal/specs/semantic-case-intake/spec.md#requirement-a-classification-that-is-an-access-rule-is-required-before-the-case-exists-req-triage-02
	 */
	public function testACaseTypeThatDeclaresNoAccessRuleCreatesUnclassified(): void {
		$caseType = ['caseClassification' => ['facets' => ['sensitivity']]];

		$this->classification()->assertCreatable(case: ['title' => 'Melding'], caseType: $caseType);

		$this->assertFalse($this->classification()->classificationGatesCreation(caseType: $caseType));
	}//end testACaseTypeThatDeclaresNoAccessRuleCreatesUnclassified()

	/**
	 * An unresolvable scheme fails closed and names the scheme.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/intake-triage-and-refusal/specs/semantic-case-intake/spec.md#requirement-a-classification-that-is-an-access-rule-is-required-before-the-case-exists-req-triage-02
	 */
	public function testAnUnresolvableSchemeRefusesAndNamesTheScheme(): void {
		$caseType = [
			'caseClassification' => [
				'scheme' => 'tmlo-2019',
				'classificationIsAccessRule' => true,
			],
		];

		try {
			$this->classification()->assertCreatable(
				case: ['title' => 'Bezwaar', 'classification' => 'intern'],
				caseType: $caseType
			);
			$this->fail('The creation should have been refused.');
		} catch (RefusedException $e) {
			$this->assertSame(CaseClassification::RULE_SCHEME_UNRESOLVED, $e->getRule());
			$this->assertStringContainsString('tmlo-2019', $e->getSentence());
		}
	}//end testAnUnresolvableSchemeRefusesAndNamesTheScheme()

	/**
	 * An administered scheme resolves, and its values are accepted.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/intake-triage-and-refusal/specs/semantic-case-intake/spec.md#requirement-a-classification-that-is-an-access-rule-is-required-before-the-case-exists-req-triage-02
	 */
	public function testAnAdministeredSchemeResolves(): void {
		$administered = json_encode(['tmlo-2019' => ['openbaar', 'beperkt']]);
		$caseType = [
			'caseClassification' => [
				'scheme' => 'tmlo-2019',
				'classificationIsAccessRule' => true,
			],
		];

		$this->classification(administered: (string)$administered)->assertCreatable(
			case: ['title' => 'Bezwaar', 'classification' => 'beperkt'],
			caseType: $caseType
		);

		$this->assertTrue(true, 'The creation was not refused.');
	}//end testAnAdministeredSchemeResolves()

	/**
	 * A value outside the scheme is refused, and the sentence carries both.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/intake-triage-and-refusal/specs/semantic-case-intake/spec.md#requirement-a-classification-that-is-an-access-rule-is-required-before-the-case-exists-req-triage-02
	 */
	public function testAValueOutsideTheSchemeIsRefused(): void {
		$caseType = [
			'caseClassification' => [
				'scheme' => 'vertrouwelijkheidaanduiding',
				'classificationIsAccessRule' => true,
			],
		];

		try {
			$this->classification()->assertCreatable(
				case: ['title' => 'Bezwaar', 'classification' => 'top-secret'],
				caseType: $caseType
			);
			$this->fail('The creation should have been refused.');
		} catch (RefusedException $e) {
			$this->assertSame(CaseClassification::RULE_VALUE_OUTSIDE_SCHEME, $e->getRule());
			$this->assertStringContainsString('top-secret', $e->getSentence());
			$this->assertStringContainsString('vertrouwelijkheidaanduiding', $e->getSentence());
		}
	}//end testAValueOutsideTheSchemeIsRefused()

	/**
	 * The four facets are recorded when they are declared.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/intake-triage-and-refusal/specs/semantic-case-intake/spec.md#requirement-a-classification-that-is-an-access-rule-is-required-before-the-case-exists-req-triage-02
	 */
	public function testTheFourFacetsAreRecordedWhenDeclared(): void {
		$caseType = [
			'caseClassification' => [
				'facets' => ['insightLevel', 'classification', 'sensitivity', 'actionFacet'],
			],
		];
		$case = [
			'classification' => 'zaakvertrouwelijk',
			'sensitivity' => 'bijzondere-persoonsgegevens',
			'actionFacet' => 'beslissen',
			'insightLevel' => 'behandelaar',
		];

		$values = $this->classification()->facetValues(case: $case, caseType: $caseType);

		$this->assertSame(CaseClassification::FACETS, array_keys($values));
		$this->assertSame('zaakvertrouwelijk', $values['classification']);
		$this->assertSame('behandelaar', $values['insightLevel']);
	}//end testTheFourFacetsAreRecordedWhenDeclared()

	/**
	 * A facet the case type never declared is not read off the case.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/intake-triage-and-refusal/specs/semantic-case-intake/spec.md
	 */
	public function testAnUndeclaredFacetIsNotRead(): void {
		$values = $this->classification()->facetValues(
			case: ['sensitivity' => 'left-over-from-an-import'],
			caseType: ['caseClassification' => ['facets' => ['actionFacet']]]
		);

		$this->assertSame(['actionFacet'], array_keys($values));
	}//end testAnUndeclaredFacetIsNotRead()

	/**
	 * Marking the classification an access rule declares it as a facet too.
	 *
	 * Leaving it off the list while marking it the access rule is a
	 * contradiction, and the half that decides who can reach the case wins.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/intake-triage-and-refusal/specs/semantic-case-intake/spec.md
	 */
	public function testTheAccessRuleImpliesTheClassificationFacet(): void {
		$facets = $this->classification()->facetsFor(
			caseType: [
				'caseClassification' => [
					'classificationIsAccessRule' => true,
					'facets' => ['sensitivity'],
				],
			]
		);

		$this->assertSame(['classification', 'sensitivity'], $facets);
	}//end testTheAccessRuleImpliesTheClassificationFacet()

	/**
	 * Unreadable administered configuration leaves the shipped scheme standing.
	 *
	 * The fail-closed direction: the shipped scheme still resolves, and a case
	 * type naming a scheme that only lived in the broken JSON is refused.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/intake-triage-and-refusal/specs/semantic-case-intake/spec.md
	 */
	public function testBrokenConfigurationLeavesTheShippedSchemeStanding(): void {
		$classification = $this->classification(administered: '{not json');

		$classification->assertCreatable(
			case: ['classification' => 'intern'],
			caseType: [
				'caseClassification' => [
					'scheme' => 'vertrouwelijkheidaanduiding',
					'classificationIsAccessRule' => true,
				],
			]
		);

		$this->expectException(RefusedException::class);
		$classification->assertCreatable(
			case: ['classification' => 'intern'],
			caseType: [
				'caseClassification' => [
					'scheme' => 'only-in-the-broken-json',
					'classificationIsAccessRule' => true,
				],
			]
		);
	}//end testBrokenConfigurationLeavesTheShippedSchemeStanding()

	/**
	 * A scheme administered with no values accepts anything.
	 *
	 * An empty list is an administrator saying "free text here", not an empty
	 * vocabulary that refuses every value including the ones it means.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/intake-triage-and-refusal/specs/semantic-case-intake/spec.md
	 */
	public function testASchemeWithNoValuesAcceptsAnything(): void {
		$administered = (string)json_encode(['vrije-rubricering' => []]);

		$this->classification(administered: $administered)->assertCreatable(
			case: ['classification' => 'iets wat de gemeente zelf bedacht'],
			caseType: [
				'caseClassification' => [
					'scheme' => 'vrije-rubricering',
					'classificationIsAccessRule' => true,
				],
			]
		);

		$this->assertTrue(true, 'The creation was not refused.');
	}//end testASchemeWithNoValuesAcceptsAnything()
}//end class
