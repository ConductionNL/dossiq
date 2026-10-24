<?php

/**
 * An organisation names its contact persons as OpenRegister person parties in a
 * configured role, one of them its main contact; only who may update the
 * organisation may change them, and a contact person needs no BSN.
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
 * @spec openspec/changes/organisation-contact-persons/specs/party-pages/spec.md#requirement-an-organisation-names-its-contact-persons-and-its-main-contact
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Settings;

use OCA\Dossiq\Service\Settings\RegisterFragmentMerger;
use PHPUnit\Framework\TestCase;

/**
 * The register fragment, the Zuiddrecht example set and the organisation page.
 *
 * @spec openspec/changes/organisation-contact-persons/specs/party-pages/spec.md#requirement-an-organisation-names-its-contact-persons-and-its-main-contact
 */
class OrganisationContactPersonsTest extends TestCase {

	/**
	 * A JSON file under the app root, decoded.
	 *
	 * @param string $path The path under the app root.
	 *
	 * @return array<string, mixed>
	 */
	private function json(string $path): array {
		$decoded = json_decode((string)file_get_contents(__DIR__.'/../../../'.$path), true);
		$this->assertIsArray(actual: $decoded);

		return $decoded;
	}//end json()

	/**
	 * One schema of the BRP/KvK fragment.
	 *
	 * @param string $slug The schema slug.
	 *
	 * @return array<string, mixed>
	 */
	private function schema(string $slug): array {
		return $this->json(path: 'lib/Settings/register.d/25-brp-kvk.json')['components']['schemas'][$slug];
	}//end schema()

	/**
	 * The Zuiddrecht example-set rows of one part, by key.
	 *
	 * @param string $part The part (`objects`, `parties`).
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function exampleRows(string $part): array {
		$rows = [];
		foreach (($this->json(path: 'lib/Settings/example-sets/zuiddrecht.json')[$part] ?? []) as $row) {
			$rows[$row['key']] = $row;
		}

		return $rows;
	}//end exampleRows()

	/**
	 * The organisation takes person parties in the four shipped roles, each
	 * labelled, as configuration a municipality extends.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/organisation-contact-persons/specs/party-pages/spec.md#requirement-the-roles-a-contact-person-can-hold-are-configuration
	 */
	public function testTheOrganisationTakesContactPersonsInConfiguredRoles(): void {
		$configuration = $this->schema(slug: 'kvkCompany')['configuration'];

		$this->assertSame(expected: ['person'], actual: array_column($configuration['partyKinds'], 'key'));
		$this->assertSame(
			expected: ['chair', 'secretary', 'treasurer', 'contact'],
			actual: $configuration['partyKinds'][0]['roles']
		);
		$this->assertSame(
			expected: ['chair' => 'Chair', 'secretary' => 'Secretary', 'treasurer' => 'Treasurer', 'contact' => 'Contact person'],
			actual: array_column($configuration['linkRoles'], 'label', 'key')
		);
	}//end testTheOrganisationTakesContactPersonsInConfiguredRoles()

	/**
	 * Changing the contact persons is the organisation's update right, which
	 * OpenRegister asks on every party write. The organisation keeps no block
	 * of its own, so the dossiq register's block decides: its staff and the
	 * background account update it, and an account outside them is refused
	 * every party write (and cannot even read the organisation).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/organisation-contact-persons/specs/party-pages/spec.md#requirement-only-who-may-update-the-organisation-may-change-its-contact-persons
	 */
	public function testOnlyWhoMayUpdateTheOrganisationChangesItsContactPersons(): void {
		$this->assertArrayNotHasKey(
			key: 'authorization',
			array: $this->schema(slug: 'kvkCompany'),
			message: 'a block of its own would replace the register\'s for organisations'
		);

		$root = dirname(__DIR__, 3);
		[$merged] = (new RegisterFragmentMerger())->merge(
			base: $this->json(path: 'lib/Settings/dossiq_register.json'),
			fragmentDir: $root.'/lib/Settings/register.d'
		);
		$register = $merged['components']['registers']['dossiq']['authorization'];
		$this->assertArrayNotHasKey(key: 'authorization', array: $merged['components']['schemas']['kvkCompany']);
		$this->assertSame(expected: ['dossiq-medewerkers', 'dossiq-background-service'], actual: $register['update']);
		$this->assertNotContains(needle: 'authenticated', haystack: $register['update']);
	}//end testOnlyWhoMayUpdateTheOrganisationChangesItsContactPersons()

	/**
	 * A contact person needs no BSN, carries their own contact addresses, and the
	 * party model reads those as the party's addresses.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/organisation-contact-persons/specs/party-pages/spec.md#requirement-a-contact-person-may-have-no-bsn
	 */
	public function testAContactPersonNeedsNoBsnAndCarriesOwnAddresses(): void {
		$person = $this->schema(slug: 'brpPerson');

		$this->assertSame(expected: ['name'], actual: $person['required']);
		$this->assertSame(
			expected: '^[0-9]{9}$',
			actual: $person['properties']['citizenServiceNumber']['pattern'],
			message: 'a BSN, when given, is still checked'
		);
		$this->assertSame(expected: 'contactAddresses', actual: $person['configuration']['x-openregister-party']['addressesProperty']);
		$this->assertSame(
			expected: ['kind', 'type', 'value'],
			actual: array_keys($person['properties']['contactAddresses']['items']['properties'])
		);
	}//end testAContactPersonNeedsNoBsnAndCarriesOwnAddresses()

	/**
	 * Buurtvereniging Oost: Marja Verbeek is Voorzitter and the main contact,
	 * reached at her own address; Kees de Wit is Secretaris without a BSN.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/organisation-contact-persons/specs/party-pages/spec.md#requirement-an-organisation-names-its-contact-persons-and-its-main-contact
	 */
	public function testBuurtverenigingOostHasMarjaAsMainContactAndASecretary(): void {
		$objects = $this->exampleRows(part: 'objects');
		$parties = $this->exampleRows(part: 'parties');

		$this->assertContains(
			needle: ['kind' => 'correspondence', 'type' => 'email', 'value' => '{{cast:marja.email}}'],
			haystack: $objects['persoon-marja']['data']['contactAddresses']
		);
		$this->assertArrayNotHasKey(key: 'citizenServiceNumber', array: $objects['persoon-kees-dewit']['data']);
		$this->assertSame(expected: 'brpPerson', actual: $objects['persoon-kees-dewit']['schema']);

		$onOrganisation = array_filter(
			$parties,
			static fn (array $row): bool => $row['data']['objectUuid'] === '{{object:bedrijf-buurtoost.id}}'
		);
		$roles = [];
		foreach ($onOrganisation as $row) {
			$roles[$row['data']['partyUuid']] = [$row['data']['role'], ($row['data']['primary'] ?? false)];
		}

		$this->assertSame(
			expected: [
				'{{object:persoon-marja.id}}'      => ['chair', true],
				'{{object:persoon-kees-dewit.id}}' => ['secretary', false],
			],
			actual: $roles
		);
	}//end testBuurtverenigingOostHasMarjaAsMainContactAndASecretary()

	/**
	 * The organisation page draws the main contact as a side card under the
	 * KvK card, and a Contact persons tab its foot link opens.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/organisation-contact-persons/specs/party-pages/spec.md#requirement-an-organisation-names-its-contact-persons-and-its-main-contact
	 */
	public function testTheOrganisationPageDrawsTheMainContactAndTheTab(): void {
		$page = null;
		foreach ($this->json(path: 'src/manifest.json')['pages'] as $candidate) {
			if (($candidate['id'] ?? '') === 'OrganisationDetail') {
				$page = $candidate['config'];
			}
		}

		$this->assertIsArray(actual: $page);
		$side = array_column($page['sideColumn'], null, 'id');
		$this->assertSame(expected: ['side-kvk', 'side-main-contact'], actual: array_slice(array_keys($side), 0, 2));
		$card = $side['side-main-contact'];
		$this->assertSame(expected: 'parties', actual: $card['type']);
		$this->assertSame(expected: 'Main contact', actual: $card['title']);
		$this->assertSame(expected: 'primary', actual: $card['content']['presentation']);
		$this->assertSame(expected: 'org-contact-persons', actual: $card['content']['link']['tab']);
		$this->assertSame(expected: 'All {total} contact persons', actual: $card['content']['link']['label']);

		$widgets = array_column($page['widgets'], null, 'id');
		$this->assertSame(expected: 'parties', actual: $widgets['org-contact-persons']['type']);
		$tabs = array_column($widgets['contact-panels']['content']['tabs'], null, 'widgetId');
		$this->assertSame(expected: 'Contact persons', actual: $tabs['org-contact-persons']['label']);
		$this->assertSame(expected: 'total', actual: $tabs['org-contact-persons']['countFrom']['field']);

		// A handler adds a contact person (somebody known, or somebody new without a BSN), in a role, maybe as the
		// main contact, and removes one; OpenRegister's party API asks the organisation's update right.
		$manage = $widgets['org-contact-persons']['content']['manage'];
		$this->assertSame(expected: 'person', actual: $manage['add']['kind']);
		$this->assertTrue(condition: $manage['add']['primary']);
		$this->assertSame(expected: ['dossiq', 'brpPerson'], actual: [$manage['add']['create']['register'], $manage['add']['create']['schema']]);
		$fields = array_column($manage['add']['create']['fields'], null, 'key');
		$this->assertArrayNotHasKey(key: 'citizenServiceNumber', array: $fields, message: 'a contact person needs no BSN');
		$this->assertSame(
			expected: ['path' => 'contactAddresses', 'kind' => 'correspondence', 'type' => 'email'],
			actual: $fields['email']['address']
		);
		$this->assertSame(expected: 'integer', actual: $fields['houseNumber']['type']);
		$this->assertSame(
			expected: ['name.givenNames', 'name.namePrefix', 'name.surname'],
			actual: $manage['add']['create']['compose']['displayName']
		);
		$this->assertSame(expected: ['dossiq', 'brpPerson'], actual: [$manage['add']['sources'][0]['register'], $manage['add']['sources'][0]['schema']]);
		$this->assertArrayHasKey(key: 'remove', array: $manage);
	}//end testTheOrganisationPageDrawsTheMainContactAndTheTab()
}//end class
