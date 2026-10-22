<?php

/**
 * Example data for DqBesluit: a Woo case whose seven documents are all marked
 * relevant and assessed, with its decision letter on the case, so "Besluit
 * vastleggen" opens on the full form: the decision date, the reasons and the
 * letter "Besluit Woo-verzoek <number>.pdf" with Andere brief.
 *
 * The Lindelaan case keeps 4 of 7 assessed for DqZaakDocumenten; one case
 * cannot draw both boards, so DqBesluit is measured on this one.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Settings
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
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * The Woo case DqBesluit is measured on.
 */
class ZuiddrechtWooCaseReadyToDecideTest extends TestCase {

	private const CASE_KEY = 'woo-fietspad-besluitklaar';

	/**
	 * The set's objects by key.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function objects(): array {
		$set = json_decode((string)file_get_contents(__DIR__.'/../../../lib/Settings/example-sets/zuiddrecht.json'), true);

		return array_column($set['objects'], null, 'key');
	}//end objects()

	/**
	 * The objects of one schema that point at the case through a field.
	 *
	 * @param string $schema The schema.
	 * @param string $field  The field naming the case.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function onCase(string $schema, string $field): array {
		return array_values(
			array_filter(
				$this->objects(),
				static fn (array $o): bool => $o['schema'] === $schema && ($o['data'][$field] ?? '') === '{{object:'.self::CASE_KEY.'.id}}'
			)
		);
	}//end onCase()

	/**
	 * A Woo request in the Besluit status, not yet published.
	 *
	 * @return void
	 */
	public function testTheCaseIsAWooRequestInTheDecisionStatus(): void {
		$case = $this->objects()[self::CASE_KEY];
		$this->assertSame(expected: 'case', actual: $case['schema']);
		$this->assertSame(expected: 'woo-verzoek', actual: $case['lookups']['caseType']['where']['identifier']);
		$this->assertSame(expected: 'Besluit', actual: $case['lookups']['status']['where']['name']);
		$this->assertArrayNotHasKey(key: 'wooPublicationStatus', array: $case['data']);
	}//end testTheCaseIsAWooRequestInTheDecisionStatus()

	/**
	 * Seven documents, each joined, marked in scope and assessed: 4 public, 2 in part, 1 not.
	 *
	 * @return void
	 */
	public function testSevenDocumentsAreAllAssessed(): void {
		$objects     = $this->objects();
		$assessments = $this->onCase(schema: 'wooDocumentAssessment', field: 'caseRef');
		$this->assertCount(expectedCount: 7, haystack: $assessments);

		$classes = array_count_values(array_column(array_column($assessments, 'data'), 'classification'));
		ksort($classes);
		$this->assertSame(expected: ['deels_openbaar' => 2, 'niet_openbaar' => 1, 'openbaar' => 4], actual: $classes);

		$joined   = array_column(array_column($this->onCase(schema: 'zaakinformatieobject', field: 'case'), 'data'), 'informatieobject');
		$inScope  = [];
		foreach ($this->onCase(schema: 'documentReview', field: 'case') as $review) {
			$inScope[$review['data']['documentRef']] = $review['data']['relevance'];
		}

		foreach ($assessments as $assessment) {
			$document = $assessment['data']['documentRef'];
			$this->assertContains(needle: $document, haystack: $joined, message: $document.' is on the case');
			$this->assertSame(expected: 'in-scope', actual: ($inScope[$document] ?? 'unmarked'), message: $document.' is marked relevant');
			$this->assertArrayHasKey(key: substr($document, strlen('{{object:'), -strlen('.id}}')), array: $objects);
		}
	}//end testSevenDocumentsAreAllAssessed()

	/**
	 * The decision letter is on the case as a Besluit document named after the case number,
	 * and marked out of scope, so it holds nothing up.
	 *
	 * @return void
	 */
	public function testTheDecisionLetterIsOnTheCase(): void {
		$letter = $this->objects()[self::CASE_KEY.'-besluitbrief'];
		$this->assertSame(expected: 'informatieobject', actual: $letter['schema']);
		$this->assertSame(expected: 'Besluit', actual: $letter['data']['informatieobjecttype']);
		$this->assertSame(expected: 'Besluit Woo-verzoek {{object:'.self::CASE_KEY.'.identifier}}.pdf', actual: $letter['data']['fileName']);

		$joined = array_column(array_column($this->onCase(schema: 'zaakinformatieobject', field: 'case'), 'data'), 'informatieobject');
		$this->assertContains(needle: '{{object:'.self::CASE_KEY.'-besluitbrief.id}}', haystack: $joined);

		$reviews = [];
		foreach ($this->onCase(schema: 'documentReview', field: 'case') as $review) {
			$reviews[$review['data']['documentRef']] = $review['data']['relevance'];
		}

		$this->assertSame(expected: 'out-of-scope', actual: $reviews['{{object:'.self::CASE_KEY.'-besluitbrief.id}}']);
	}//end testTheDecisionLetterIsOnTheCase()
}//end class
