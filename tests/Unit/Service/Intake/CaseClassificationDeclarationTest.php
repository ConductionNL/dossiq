<?php

/**
 * A case type's classification declaration as it is stored: absent, or with untidy facets.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Test
 * @package   OCA\Dossiq\Tests\Unit\Service\Intake
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/dossiq
 *
 * @spec openspec/changes/intake-triage-and-refusal/specs/semantic-case-intake/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Intake;

use OCA\Dossiq\Service\Intake\CaseClassification;
use OCA\Dossiq\Service\Intake\ClassificationSchemes;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\Intake\CaseClassification
 * @uses   \OCA\Dossiq\Service\Intake\ClassificationSchemes
 */
class CaseClassificationDeclarationTest extends TestCase {

	/**
	 * The service, with only the shipped scheme.
	 *
	 * @return CaseClassification
	 */
	private function classification(): CaseClassification {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('');

		return new CaseClassification(new ClassificationSchemes($appConfig));
	}//end classification()

	/**
	 * A declaration that is no object declares nothing, and the case is created unclassified.
	 *
	 * @return void
	 */
	public function testADeclarationThatIsNoObjectDeclaresNothing(): void {
		$classification = $this->classification();
		$caseType = ['caseClassification' => 'vertrouwelijkheidaanduiding'];

		self::assertSame(
			['scheme' => '', 'classificationIsAccessRule' => false, 'facets' => []],
			$classification->declarationFor(caseType: $caseType)
		);
		self::assertFalse($classification->classificationGatesCreation(caseType: $caseType));
		$classification->assertCreatable(case: [], caseType: $caseType);
		self::assertSame([], $classification->facetValues(case: ['classification' => 'geheim'], caseType: $caseType));
	}//end testADeclarationThatIsNoObjectDeclaresNothing()

	/**
	 * Facets that are no list are none, though an access-rule classification stays one; listed facets keep the canonical order and drop unknown names.
	 *
	 * @return void
	 */
	public function testFacetsAreReadInCanonicalOrder(): void {
		$classification = $this->classification();

		self::assertSame([], $classification->facetsFor(caseType: ['caseClassification' => ['facets' => 'sensitivity']]));
		self::assertSame(
			['classification'],
			$classification->facetsFor(caseType: ['caseClassification' => ['facets' => 'insightLevel', 'classificationIsAccessRule' => true]])
		);
		self::assertSame(
			['sensitivity', 'insightLevel'],
			$classification->facetsFor(caseType: ['caseClassification' => ['facets' => ['insightLevel', 'colour', 'sensitivity']]])
		);
	}//end testFacetsAreReadInCanonicalOrder()
}//end class
