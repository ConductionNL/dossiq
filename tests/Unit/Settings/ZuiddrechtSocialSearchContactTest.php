<?php

/**
 * Example data for three boards: the jeugdwet example case as DqSociaalDomein
 * draws it, the saved search with its count alert on DqZoekresultaten, and the
 * portal resident's contact record on DqContact.
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
 *
 * @spec openspec/specs/example-set-social-search-contact/spec.md#requirement-the-example-data-carries-what-the-social-search-and-contact-boards-draw
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * The jeugdwet example case, the saved search and Sanne's contact record.
 *
 * @spec openspec/specs/example-set-social-search-contact/spec.md#requirement-the-example-data-carries-what-the-social-search-and-contact-boards-draw
 */
class ZuiddrechtSocialSearchContactTest extends TestCase {

	/**
	 * A JSON file of the app's settings.
	 *
	 * @param string $path The path under lib/Settings.
	 *
	 * @return array<string, mixed>
	 */
	private function settings(string $path): array {
		$decoded = json_decode((string)file_get_contents(__DIR__.'/../../../lib/Settings/'.$path), true);
		$this->assertIsArray(actual: $decoded);

		return $decoded;
	}//end settings()

	/**
	 * DqSociaalDomein: the legal basis, who reported, the regisseur and a deadline about five weeks after receipt.
	 *
	 * @return void
	 */
	public function testTheJeugdwetCaseCarriesWhatTheBoardDraws(): void {
		$case = $this->settings(path: 'packages/jeugdwet/examples/20-case.json')['rows'][0];

		$this->assertSame(expected: 'Melding Jeugdwet gezin Oosthaven', actual: $case['title']);
		$this->assertSame(expected: 'Jeugdwet artikel 2.3', actual: $case['legalBasis']);
		$this->assertSame(expected: 'Huisarts, met toestemming van de ouders', actual: $case['initiatorDisplayName']);
		$this->assertSame(expected: 'fatma.kaya', actual: $case['assignee']);
		$received = (int)preg_replace(pattern: '/\D+/', replacement: '', subject: (string)$case['startDate']);
		$due      = (int)preg_replace(pattern: '/\D+/', replacement: '', subject: (string)$case['deadline']);
		$this->assertSame(expected: '{{date:-15}}', actual: $case['startDate']);
		$this->assertSame(expected: 35, actual: ($received + $due));

		$people = $this->settings(path: 'packages/jeugdwet/examples/40-people.json')['rows'];
		$this->assertContains(needle: $case['assignee'], haystack: array_column($people, 'participant'));
	}//end testTheJeugdwetCaseCarriesWhatTheBoardDraws()

	/**
	 * DqZoekresultaten: a public saved search for "Lindelaan" on the search page with the count alert at 12,
 * telling its owner, the one recipient a saver may name without being an administrator. Pieter owns it: the
	 * board's reader turns its alert on and off, and only the owner may.
	 *
	 * @return void
	 */
	public function testTheSavedSearchHasItsAlert(): void {
		$views = array_column($this->settings(path: 'example-sets/zuiddrecht.json')['views'], 'data', 'key');

		$this->assertArrayHasKey(key: 'zoek-lindelaan', array: $views);
		$view = $views['zoek-lindelaan'];
		$this->assertSame(expected: 'Lindelaan', actual: $view['query']['search']);
		// DqZoekresultaten's saved-search card names it as the board does.
		$this->assertSame(expected: 'Meldingen verlichting', actual: $view['name']);
		$this->assertSame(expected: 'source:search', actual: $view['query']['scope']);
		$this->assertSame(expected: 'gte', actual: $view['alert']['operator']);
		$this->assertSame(expected: 12, actual: $view['alert']['threshold']);
		$this->assertTrue(condition: $view['isPublic']);
		$this->assertSame(expected: '{{cast:pieter.uid}}', actual: $view['owner']);
		$this->assertSame(expected: ['user:'.$view['owner']], actual: $view['alert']['recipients']);
	}//end testTheSavedSearchHasItsAlert()

	/**
	 * DqContact: Sanne de Vries is a contact, every field from the cast.
	 *
	 * @return void
	 */
	public function testSanneIsAContact(): void {
		$objects = $this->settings(path: 'example-sets/zuiddrecht.json')['objects'];
		$sanne   = array_values(array_filter($objects, static fn (array $object): bool => $object['key'] === 'persoon-sanne'))[0];

		$this->assertSame(expected: 'brpPerson', actual: $sanne['schema']);
		$this->assertSame(expected: '{{cast:sanne.bsn}}', actual: $sanne['data']['citizenServiceNumber']);
		$this->assertSame(expected: '{{cast:sanne.address.street}}', actual: $sanne['data']['residence']['street']);
		$this->assertStringNotContainsString(needle: 'linda', haystack: (string)json_encode($sanne));
	}//end testSanneIsAContact()
}//end class
