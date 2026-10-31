<?php

/**
 * Example data for the dossiq case type boards (DqZaaktype, DqZaaktypeOverzicht,
 * DqZaaktypeVelden, DqZaaktypeStatussen, DqZaaktypeRegels): versions 4 and 5 of
 * Omgevingsvergunning regulier carry their statuses, tasks, variants, messages,
 * rights, rules and privacy facts.
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

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What versions 4 and 5 of Omgevingsvergunning regulier carry.
 */
class ZuiddrechtRegulierV4V5CompleteTest extends TestCase {

	/**
	 * The fragment, read once.
	 *
	 * @var array<string, mixed>|null
	 */
	private static ?array $set = null;

	/**
	 * The Zuiddrecht fragment.
	 *
	 * @return array<string, mixed>
	 */
	private function set(): array {
		if (self::$set === null) {
			self::$set = json_decode((string)file_get_contents(__DIR__.'/../../../lib/Settings/example-sets/zuiddrecht.json'), true);
		}

		return self::$set;
	}//end set()

	/**
	 * The two versions the boards draw, by the key of their case type object.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function versions(): array {
		return [
			'version 4' => ['zaaktype-omgevingsvergunning-regulier-v4'],
			'version 5' => ['zaaktype-omgevingsvergunning-regulier'],
		];
	}//end versions()

	/**
	 * A case type as the install leaves it: its data with every change on it applied in order.
	 *
	 * @param string $key The case type object's key.
	 *
	 * @return array<string, mixed>
	 */
	private function effective(string $key): array {
		$set  = $this->set();
		$data = array_column($set['objects'], null, 'key')[$key]['data'];
		foreach ($set['changes'] as $change) {
			if (($change['object'] ?? null) === $key) {
				$data = array_merge($data, $change['set']);
			}
		}

		return $data;
	}//end effective()

	/**
	 * The objects of one schema that belong to a case type, in fragment order.
	 *
	 * @param string $key    The case type object's key.
	 * @param string $schema The schema.
	 *
	 * @return array<string, array<string, mixed>> The objects' data, by key.
	 */
	private function rowsOf(string $key, string $schema): array {
		$rows = [];
		foreach ($this->set()['objects'] as $object) {
			if ($object['schema'] === $schema && ($object['data']['caseType'] ?? null) === '{{object:'.$key.'.id}}') {
				$rows[$object['key']] = $object['data'];
			}
		}

		return $rows;
	}//end rowsOf()

	/**
	 * The key of the row of one schema with this name, on this case type.
	 *
	 * @param string $key    The case type object's key.
	 * @param string $schema The schema.
	 * @param string $name   The row's name.
	 *
	 * @return string
	 */
	private function keyOf(string $key, string $schema, string $name): string {
		foreach ($this->rowsOf(key: $key, schema: $schema) as $rowKey => $row) {
			if ($row['name'] === $name) {
				return $rowKey;
			}
		}

		$this->fail(message: "$key has no $schema named $name");
	}//end keyOf()

	/**
	 * Six statuses in board order: one start, one that waits on the applicant, two final.
	 *
	 * @param string $key The case type object's key.
	 *
	 * @return void
	 */
	#[DataProvider('versions')]
	public function testSixStatuses(string $key): void {
		$statuses = array_values($this->rowsOf(key: $key, schema: 'statusType'));

		$this->assertSame(
			expected: ['Ontvangen', 'Aanvullende gegevens gevraagd', 'In behandeling', 'Besluit', 'Verleend', 'Geweigerd'],
			actual: array_column($statuses, 'name')
		);
		$this->assertSame(expected: [1, 2, 3, 4, 5, 6], actual: array_column($statuses, 'order'));
		$this->assertSame(expected: ['blue', 'orange', 'purple', 'teal', 'green', 'red'], actual: array_column($statuses, 'colour'));
		$this->assertSame(expected: 'intake', actual: $statuses[0]['role']);
		$this->assertSame(expected: 'applicant', actual: $statuses[1]['waitingOn']);
		$this->assertSame(
			expected: [false, false, false, false, true, true],
			actual: array_map(static fn (array $status): bool => ($status['isFinal'] ?? false) === true, $statuses)
		);
	}//end testSixStatuses()

	/**
	 * The active workflow runs six tasks, two in each of Ontvangen, In behandeling and Besluit.
	 *
	 * @param string $key The case type object's key.
	 *
	 * @return void
	 */
	#[DataProvider('versions')]
	public function testSixTasksUnderTheirStatuses(string $key): void {
		$templates = array_values($this->rowsOf(key: $key, schema: 'workflowTemplate'));
		$this->assertCount(expectedCount: 1, haystack: $templates);
		$this->assertTrue(condition: $templates[0]['isActive']);

		$steps = $templates[0]['steps'];
		$this->assertSame(
			expected: [
				'Volledigheid toetsen',
				'Ontvangstbevestiging',
				'Toets aan het omgevingsplan',
				'Welstandsadvies vragen',
				'Besluit opstellen',
				'Besluit verzenden',
			],
			actual: array_column($steps, 'title')
		);

		$status = fn (string $name): string => '{{object:'.$this->keyOf(key: $key, schema: 'statusType', name: $name).'.id}}';
		$this->assertSame(
			expected: [
				$status('Ontvangen'),
				$status('Ontvangen'),
				$status('In behandeling'),
				$status('In behandeling'),
				$status('Besluit'),
				$status('Besluit'),
			],
			actual: array_column($steps, 'status')
		);
		$this->assertSame(expected: 5, actual: $steps[0]['task']['leadTimeDays']);
		$this->assertSame(expected: 15, actual: $steps[2]['task']['leadTimeDays']);
		$this->assertSame(expected: count($steps), actual: count(array_unique(array_column($steps, 'id'))));

		$intaker = '{{object:'.$this->keyOf(key: $key, schema: 'roleType', name: 'Intaker').'.id}}';
		$this->assertSame(expected: $intaker, actual: $steps[0]['routingRule']['roleType']);

		$object = array_column($this->set()['objects'], null, 'key')[array_key_first($this->rowsOf(key: $key, schema: 'workflowTemplate'))];
		$this->assertContains(needle: 'steps', haystack: $object['jsonFields']);
		$this->assertContains(needle: 'transitions', haystack: $object['jsonFields']);
	}//end testSixTasksUnderTheirStatuses()

	/**
	 * The acts available in every phase, the two variants, the terms and the facts beside the page.
	 *
	 * @param string $key The case type object's key.
	 *
	 * @return void
	 */
	#[DataProvider('versions')]
	public function testActsVariantsAndFacts(string $key): void {
		$type = $this->effective(key: $key);

		$this->assertSame(
			expected: ['Termijn opschorten', 'Aanvullende gegevens vragen', 'Zaak overdragen'],
			actual: array_column($type['alwaysAvailableActs'], 'label')
		);
		$this->assertSame(expected: ['Regulier', 'Met welstand'], actual: array_column($type['variants'], 'name'));
		$this->assertSame(expected: [true, false], actual: array_column($type['variants'], 'isDefault'));
		$this->assertSame(expected: 'VTH', actual: $type['category']);
		$this->assertSame(expected: 'zuiddrecht-vergunningen', actual: $type['handling']['defaultGroup']);
		$this->assertSame(expected: ['zuiddrecht-toezicht-en-handhaving'], actual: $type['handling']['teams']);
		$this->assertSame(expected: [1, true, 28], actual: [$type['maxExtensions'], $type['penaltyOnLateDecision'], $type['maxSuspensionDays']]);
	}//end testActsVariantsAndFacts()

	/**
	 * The privacy facts: purpose, basis, categories and how long the personal data keeps its purpose.
	 *
	 * @param string $key The case type object's key.
	 *
	 * @return void
	 */
	#[DataProvider('versions')]
	public function testPrivacyFacts(string $key): void {
		$type = $this->effective(key: $key);

		$this->assertTrue(condition: $type['processesPersonalData']);
		$this->assertSame(expected: 'Beslissen op een aanvraag omgevingsvergunning', actual: $type['purpose']);
		$this->assertSame(expected: 'legal_obligation', actual: $type['legalBasis']);
		$this->assertSame(expected: ['naw', 'bsn'], actual: $type['personalDataCategories']);
		$this->assertSame(expected: 240, actual: $type['lawfulPurposeRetention']);
	}//end testPrivacyFacts()

	/**
	 * Four messages at their moments and channels, and the refusal message rule 3 sends.
	 *
	 * @param string $key The case type object's key.
	 *
	 * @return void
	 */
	#[DataProvider('versions')]
	public function testMessagesAtTheirMoments(string $key): void {
		$moments = [];
		foreach ($this->rowsOf(key: $key, schema: 'emailTemplate') as $template) {
			$this->assertTrue(condition: $template['isActive'], message: $template['name']);
			$moments[$template['name']] = [($template['moment'] ?? null), ($template['channel'] ?? null)];
		}

		$this->assertSame(
			expected: [
				'Ontvangstbevestiging met zaaknummer' => ['case-received', 'mailbox-then-email'],
				'Nieuwe uiterste datum en reden'      => ['term-extended', 'mailbox-then-email'],
				'Wat er ontbreekt en tot wanneer'     => ['information-requested', 'mailbox-then-email'],
				'Het besluit als bijlage'             => ['decision-made', 'mailbox'],
				'Weigering met bezwaarclausule'       => ['decision-made', 'mailbox'],
			],
			actual: $moments
		);
	}//end testMessagesAtTheirMoments()

	/**
	 * Three rules, each naming only what this version itself declares.
	 *
	 * @param string $key The case type object's key.
	 *
	 * @return void
	 */
	#[DataProvider('versions')]
	public function testThreeRules(string $key): void {
		$rules = $this->effective(key: $key)['rules'];
		$this->assertSame(
			expected: ['Toets door constructeur', 'Met welstand', 'Weigering met bezwaarclausule'],
			actual: array_column($rules, 'name')
		);

		$this->assertSame(
			expected: ['kind' => 'fieldCompare', 'field' => 'bouwkosten', 'op' => 'gt', 'value' => 100000],
			actual: $rules[0]['conditions'][0]
		);
		$this->assertSame(expected: ['action' => 'addTask', 'title' => 'Toets door constructeur'], actual: $rules[0]['actions'][0]);
		$this->assertSame(expected: [['action' => 'chooseVariant', 'variant' => 'Met welstand']], actual: $rules[1]['actions']);
		$this->assertContains(needle: $rules[1]['actions'][0]['variant'], haystack: array_column($this->effective(key: $key)['variants'], 'name'));

		$this->assertSame(
			expected: ['event' => 'statusEntered', 'status' => '{{object:'.$this->keyOf(key: $key, schema: 'statusType', name: 'Geweigerd').'.id}}'],
			actual: $rules[2]['when']
		);
		$this->assertSame(
			expected: [
				[
					'action'        => 'sendMessage',
					'emailTemplate' => '{{object:'.$this->keyOf(key: $key, schema: 'emailTemplate', name: 'Weigering met bezwaarclausule').'.id}}',
				],
			],
			actual: $rules[2]['actions']
		);
	}//end testThreeRules()

	/**
	 * Rights per role, and field rules that name this version's own fields and statuses.
	 *
	 * @param string $key The case type object's key.
	 *
	 * @return void
	 */
	#[DataProvider('versions')]
	public function testRightsAndFieldRules(string $key): void {
		$type   = $this->effective(key: $key);
		$rights = array_column($type['rightsMatrix'], null, 'role');

		$this->assertSame(expected: ['Intaker', 'Vergunningverlener', 'Toezichthouder'], actual: array_keys($rights));
		$this->assertSame(expected: '$ownTeam', actual: $rights['Intaker']['department']);
		$this->assertSame(
			expected: ['{{object:'.$this->keyOf(key: $key, schema: 'statusType', name: 'Ontvangen').'.id}}'],
			actual: $rights['Intaker']['statuses']
		);
		$this->assertSame(expected: ['read', 'add'], actual: $rights['Intaker']['documentActions']);
		$this->assertSame(expected: '*', actual: $rights['Vergunningverlener']['department']);
		$this->assertArrayNotHasKey(key: 'statuses', array: $rights['Vergunningverlener']);
		$this->assertSame(expected: ['read', 'add', 'delete'], actual: $rights['Vergunningverlener']['documentActions']);
		$this->assertSame(expected: ['read'], actual: $rights['Toezichthouder']['actions']);
		$this->assertSame(expected: ['read'], actual: $rights['Toezichthouder']['documentActions']);

		$fields   = array_column($this->rowsOf(key: $key, schema: 'propertyDefinition'), 'name');
		$statuses = array_map(
			static fn (string $rowKey): string => '{{object:'.$rowKey.'.id}}',
			array_keys($this->rowsOf(key: $key, schema: 'statusType'))
		);
		$this->assertNotEmpty(actual: $type['fieldRoleRules']);
		foreach ($type['fieldRoleRules'] as $rule) {
			$this->assertContains(needle: $rule['field'], haystack: $fields);
			$this->assertNotEmpty(actual: $rule['groups']);
			foreach ($rule['statuses'] as $status) {
				$this->assertContains(needle: $status, haystack: $statuses);
			}
		}
	}//end testRightsAndFieldRules()

	/**
	 * Three results: a granted permit is kept, a refused or withdrawn one destroyed after its period.
	 *
	 * @param string $key The case type object's key.
	 *
	 * @return void
	 */
	#[DataProvider('versions')]
	public function testThreeResults(string $key): void {
		$results = [];
		foreach ($this->rowsOf(key: $key, schema: 'resultType') as $result) {
			$results[$result['name']] = [$result['archivalAction'], ($result['archivalPeriod'] ?? null)];
		}

		$this->assertSame(
			expected: ['Verleend' => ['bewaren', null], 'Geweigerd' => ['vernietigen', 'P10Y'], 'Ingetrokken' => ['vernietigen', 'P5Y']],
			actual: $results
		);
	}//end testThreeResults()

	/**
	 * Soort bouwwerk offers six kinds on both versions.
	 *
	 * @param string $key The case type object's key.
	 *
	 * @return void
	 */
	#[DataProvider('versions')]
	public function testSoortBouwwerkOffersSixKinds(string $key): void {
		$field = $this->rowsOf(key: $key, schema: 'propertyDefinition')[$this->keyOf(key: $key, schema: 'propertyDefinition', name: 'soortBouwwerk')];

		$this->assertSame(
			expected: ['Woning', 'Bijgebouw', 'Dakkapel', 'Bedrijfspand', 'Erfafscheiding', 'Overig'],
			actual: $field['enumValues']
		);
	}//end testSoortBouwwerkOffersSixKinds()

	/**
	 * Version 5 carries its own fields, document types and decision types, as the Statussen board draws them.
	 *
	 * @return void
	 */
	public function testVersionFiveCarriesItsOwnTypes(): void {
		$key = 'zaaktype-omgevingsvergunning-regulier';

		$this->assertSame(
			expected: ['bouwkosten', 'soortBouwwerk', 'welstandsgebied', 'situatietekening'],
			actual: array_values(array_column($this->rowsOf(key: $key, schema: 'propertyDefinition'), 'name'))
		);

		$documents = [];
		foreach ($this->rowsOf(key: $key, schema: 'documentType') as $document) {
			$documents[$document['name']] = [$document['category'], $document['confidentiality'], $document['isRequired']];
		}

		$this->assertSame(
			expected: [
				'Aanvraagformulier' => ['Aanvraag', 'zaakvertrouwelijk', true],
				'Bouwtekening'      => ['Bijlage', 'openbaar', true],
				'Welstandsadvies'   => ['Advies', 'intern', false],
				'Besluit'           => ['Besluit', 'openbaar', true],
			],
			actual: $documents
		);

		$decisions = array_column($this->rowsOf(key: $key, schema: 'decisionType'), 'publicationRequired', 'name');
		$this->assertSame(expected: ['Omgevingsvergunning verleend' => true, 'Weigering omgevingsvergunning' => false], actual: $decisions);
		$this->assertSame(
			expected: array_map(
				static fn (string $rowKey): string => '{{object:'.$rowKey.'.id}}',
				array_keys($this->rowsOf(key: $key, schema: 'decisionType'))
			),
			actual: $this->effective(key: $key)['decisionTypes']
		);
	}//end testVersionFiveCarriesItsOwnTypes()

	/**
	 * Version 4 is published by a change, so its record history says so; version 5 stays a draft.
	 *
	 * @return void
	 */
	public function testVersionFourIsPublishedByAChange(): void {
		$objects = array_column($this->set()['objects'], null, 'key');
		$changes = array_column($this->set()['changes'], null, 'key');

		$this->assertArrayNotHasKey(key: 'isDraft', array: $objects['zaaktype-omgevingsvergunning-regulier-v4']['data']);
		$this->assertSame(expected: ['isDraft' => false], actual: $changes['zaaktype-omgevingsvergunning-regulier-v4-gepubliceerd']['set']);
		$this->assertArrayHasKey(key: 'variants', array: $changes['zaaktype-omgevingsvergunning-regulier-v4-variant-met-welstand']['set']);

		$order = array_keys($changes);
		$this->assertLessThan(
			expected: array_search('zaaktype-omgevingsvergunning-regulier-v4-gepubliceerd', $order, true),
			actual: array_search('zaaktype-omgevingsvergunning-regulier-v4-variant-met-welstand', $order, true)
		);
		$this->assertTrue(condition: $this->effective(key: 'zaaktype-omgevingsvergunning-regulier')['isDraft']);
	}//end testVersionFourIsPublishedByAChange()

	/**
	 * The Vergunningverlener decides on this case type up to EUR 50,000 under an active mandate.
	 *
	 * @return void
	 */
	public function testTheVergunningverlenerDecidesUpToFiftyThousand(): void {
		$mandates = array_values(array_filter(
			$this->set()['objects'],
			static fn (array $object): bool => $object['schema'] === 'mandate' && $object['data']['mandateeRole'] === 'Vergunningverlener'
		));

		$this->assertCount(expectedCount: 1, haystack: $mandates);
		$mandate = $mandates[0]['data'];
		$this->assertSame(expected: 'active', actual: $mandate['status']);
		$this->assertSame(expected: ['omgevingsvergunning-regulier'], actual: $mandate['terms']['caseTypes']);
		$this->assertSame(expected: 5000000, actual: $mandate['terms']['plafondCents']);
		$this->assertStringStartsWith(prefix: '{{object:', string: $mandate['mandateDecision']);
	}//end testTheVergunningverlenerDecidesUpToFiftyThousand()
}//end class
