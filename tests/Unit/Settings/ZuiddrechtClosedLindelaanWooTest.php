<?php

/**
 * Example data for DqArchiveren: a second, closed Lindelaan Woo request that
 * is ready to archive, beside the open 2026-0110 the other boards draw.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec exclude example-set data integrity; no requirement covers the demo fragment
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * The closed Lindelaan Woo request.
 */
class ZuiddrechtClosedLindelaanWooTest extends TestCase {

	private const CASE_KEY = 'woo-fietspad-afgehandeld';

	/**
	 * The fragment's objects by key.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function objects(): array {
		$part = json_decode((string)file_get_contents(__DIR__.'/../../../lib/Settings/example-sets/zuiddrecht.d/63-closed-lindelaan-woo.json'), true);
		$this->assertIsArray(actual: $part);

		return array_column($part['objects'], null, 'key');
	}//end objects()

	/**
	 * Sanne's request came in through Mijn Zuiddrecht with DigiD, was decided and
	 * published yesterday, and waits to be archived.
	 *
	 * @return void
	 */
	public function testTheCaseIsClosedAndWaitsForTheArchive(): void {
		$case = $this->objects()[self::CASE_KEY];
		$data = $case['data'];

		$this->assertSame(expected: ['identifier' => 'woo-verzoek', 'version' => 2], actual: $case['lookups']['caseType']['where']);
		$this->assertSame(expected: 'Afgehandeld', actual: $case['lookups']['status']['where']['name']);
		$this->assertSame(expected: 'Verlichting fietspad Lindelaan', actual: $data['title']);
		$this->assertSame(expected: '{{cast:sanne.displayName}}', actual: $data['initiatorDisplayName']);
		$this->assertSame(expected: 'digid', actual: $data['intakeIdentification']);
		$this->assertSame(expected: '{{cast:pieter.uid}}', actual: $data['assignee']);
		$this->assertSame(expected: 'zuiddrecht-woo-en-informatie', actual: $data['assignedGroup']);
		$this->assertSame(expected: '{{date:-1}}', actual: $data['endDate']);
		$this->assertSame(expected: 'published', actual: $data['wooPublicationStatus']);
		$this->assertSame(expected: 'vernietigen', actual: $data['archiveNomination']);
		$this->assertSame(expected: 'nog_te_archiveren', actual: $data['archiveStatus']);
	}//end testTheCaseIsClosedAndWaitsForTheArchive()

	/**
	 * The case names Sanne as a portal request does, without a requester row.
	 * The Woo type blocks a duplicate on requester and title, so with Sanne's
	 * person record this case and the open 2026-0110 (same title, same
	 * requester) would refuse each other; without it the pair stays under the
	 * threshold.
	 *
	 * @return void
	 */
	public function testTheCaseHasNoRequesterRowToMatchTheOpenRequestOn(): void {
		$data = $this->objects()[self::CASE_KEY]['data'];

		$this->assertArrayNotHasKey(key: 'requester', array: $data);
		$this->assertSame(expected: '{{cast:sanne.uid}}', actual: $data['portalSubject']);
	}//end testTheCaseHasNoRequesterRowToMatchTheOpenRequestOn()

	/**
	 * Seven final documents, all assessed: four public, two partly, one not public on 5.2.1.
	 *
	 * @return void
	 */
	public function testSevenDocumentsAreFinalAndAssessed(): void {
		$objects     = $this->objects();
		$classes     = [];
		$withheldOn  = [];
		$documentIds = [];
		foreach ($objects as $object) {
			if ($object['schema'] === 'informatieobject') {
				$this->assertSame(expected: 'final', actual: $object['data']['status']);
				$documentIds[] = '{{object:'.$object['key'].'.id}}';
			}

			if ($object['schema'] === 'wooDocumentAssessment') {
				$this->assertSame(expected: '{{object:'.self::CASE_KEY.'.id}}', actual: $object['data']['caseRef']);
				$classes[] = $object['data']['classification'];
				if ($object['data']['classification'] === 'niet_openbaar') {
					$withheldOn = $object['data']['weigeringsgronden'];
				}
			}
		}

		$this->assertCount(expectedCount: 7, haystack: $documentIds);
		$this->assertSame(expected: ['deels_openbaar' => 2, 'niet_openbaar' => 1, 'openbaar' => 4], actual: $this->counted(values: $classes));
		$this->assertSame(expected: ['5.2.1'], actual: $withheldOn);

		$decision = $objects[self::CASE_KEY.'-besluit']['data'];
		$this->assertSame(expected: ['openbaar' => 4, 'deels_openbaar' => 2, 'niet_openbaar' => 1], actual: $decision['wooSummary']);
		$this->assertSame(expected: '{{date:-1}}', actual: $decision['decisionDate']);
		$this->assertSame(expected: 'Deels openbaar gemaakt', actual: $objects[self::CASE_KEY.'-resultaat']['lookups']['resultType']['where']['name']);
	}//end testSevenDocumentsAreFinalAndAssessed()

	/**
	 * The case names its result and decision once both exist, so the archive
	 * dialog draws "Deels openbaar gemaakt" and ticks Resultaat and Besluit.
	 *
	 * @return void
	 */
	public function testTheCaseNamesItsResultAndDecision(): void {
		$part    = json_decode((string)file_get_contents(__DIR__.'/../../../lib/Settings/example-sets/zuiddrecht.d/63-closed-lindelaan-woo.json'), true);
		$changes = array_column(($part['changes'] ?? []), null, 'key');
		$change  = ($changes[self::CASE_KEY.'-uitkomst'] ?? []);

		$this->assertSame(expected: self::CASE_KEY, actual: ($change['object'] ?? null));
		$this->assertSame(
			expected: [
				'result'    => '{{object:'.self::CASE_KEY.'-resultaat.id}}',
				'decisions' => ['{{object:'.self::CASE_KEY.'-besluit.id}}'],
			],
			actual: ($change['set'] ?? null)
		);
	}//end testTheCaseNamesItsResultAndDecision()

	/**
	 * The values counted, sorted by value.
	 *
	 * @param array<int, string> $values The values.
	 *
	 * @return array<string, int>
	 */
	private function counted(array $values): array {
		$counts = array_count_values($values);
		ksort($counts);

		return $counts;
	}//end counted()
}//end class
