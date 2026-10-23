<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A case type's intake completes a received case: the answers as sent keep
 * their origin and the answers that landed on case fields, the party is
 * named, and the case type's own questions are answered once.
 *
 * @spec openspec/changes/case-intake-per-case-type/specs/case-intake-destination/spec.md#requirement-a-case-type-may-say-what-its-intake-does-to-a-received-case
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Intake;

use OCA\Dossiq\Service\Intake\CaseTypeIntake;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\Intake\CaseTypeIntake
 */
class CaseTypeIntakeTest extends TestCase {

	private const WOO_TYPE = [
		'portalIntake' => [
			'answersAt' => 'wooRequest',
			'origin' => 'portal',
			'copy' => ['onderwerp' => 'title', 'omschrijving' => 'description', 'bad key' => 'title'],
			'party' => true,
			'properties' => true,
			'dossierAnswer' => 'collectionId',
		],
	];

	/**
	 * A received Woo request gets its origin, its copied answers, its party and its properties.
	 *
	 * @return void
	 */
	public function testAReceivedRequestIsCompleted(): void {
		$intake = new CaseTypeIntake();
		$config = $intake->configOf(caseType: self::WOO_TYPE);
		$asked  = [];
		$case   = [
			'title' => 'Verlichting fietspad',
			'description' => 'Alle stukken',
			'portalSubject' => 'sanne.devries',
			'wooRequest' => ['verzoekerNaam' => 'Sanne de Vries', 'collectionId' => 'd-1'],
		];

		$changes = $intake->changesFor(
			case: $case,
			config: $config,
			propertiesOf: static function (array $answers) use (&$asked): array {
				$asked = $answers;
				return [['propertyDefinition' => 'pd-1', 'name' => 'verzoekerNaam', 'value' => 'Sanne de Vries']];
			}
		);

		$this->assertSame(
			expected: [
				'verzoekerNaam' => 'Sanne de Vries',
				'collectionId' => 'd-1',
				'origin' => 'portal',
				'onderwerp' => 'Verlichting fietspad',
				'omschrijving' => 'Alle stukken',
			],
			actual: $changes['wooRequest']
		);
		$this->assertSame(expected: 'subject:sanne.devries', actual: $changes['portalParty']);
		$entry = ['propertyDefinition' => 'pd-1', 'name' => 'verzoekerNaam', 'value' => 'Sanne de Vries'];
		$this->assertSame(expected: [$entry], actual: $changes['properties']);
		$this->assertSame(expected: $changes['wooRequest'], actual: $asked, message: 'the properties read the completed answers');
		$this->assertSame(expected: 'd-1', actual: $intake->dossierOf(case: $case, config: $config));
		$this->assertSame(expected: ['onderwerp' => 'title', 'omschrijving' => 'description'], actual: $config['copy']);
	}//end testAReceivedRequestIsCompleted()

	/**
	 * What the case already has is kept: no second party, no second entry, no new origin.
	 *
	 * @return void
	 */
	public function testWhatTheCaseHasIsKept(): void {
		$intake  = new CaseTypeIntake();
		$changes = $intake->changesFor(
			case: [
				'title' => 'T',
				'portalSubject' => 's',
				'portalParty' => 'kvk:1',
				'wooRequest' => ['origin' => 'pipelinq', 'onderwerp' => 'T', 'omschrijving' => 'x'],
				'properties' => [['propertyDefinition' => 'pd-1', 'name' => 'verzoekerNaam', 'value' => 'A']],
			],
			config: $intake->configOf(caseType: self::WOO_TYPE),
			propertiesOf: static fn (array $answers): array => [['propertyDefinition' => 'pd-1', 'name' => 'verzoekerNaam', 'value' => 'B']]
		);

		$this->assertSame(expected: [], actual: $changes);
	}//end testWhatTheCaseHasIsKept()

	/**
	 * A case type without an intake changes nothing.
	 *
	 * @return void
	 */
	public function testACaseTypeWithoutAnIntakeChangesNothing(): void {
		$intake = new CaseTypeIntake();
		$config = $intake->configOf(caseType: ['title' => 'Bezwaar']);

		$this->assertSame(expected: [], actual: $config);
		$changes = $intake->changesFor(case: ['portalSubject' => 's'], config: $config, propertiesOf: static fn (): array => []);
		$this->assertSame(expected: [], actual: $changes);
		$this->assertSame(expected: '', actual: $intake->dossierOf(case: ['collectionId' => 'd'], config: $config));
	}//end testACaseTypeWithoutAnIntakeChangesNothing()
}//end class
