<?php

/**
 * Golden tests for CaseRebindImpact::compute().
 *
 * Pins the whole impact of one rich rebind (same, converted and remapped
 * ports, both kinds of drop with their candidates, required fields by flag
 * and by landing status) and the two remap refusals the other tests leave
 * out, with their sentences.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/zaaktype-versioning/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Cases;

use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\Cases\CaseAnswerReader;
use OCA\Dossiq\Service\Cases\CaseRebindImpact;
use OCA\Dossiq\Service\CaseTypeResolver;
use OCA\Dossiq\Service\CaseTypeStore;
use OCA\Dossiq\Service\SettingsService;
use PHPUnit\Framework\TestCase;

/**
 * Golden tests for CaseRebindImpact.
 *
 * @covers \OCA\Dossiq\Service\Cases\CaseRebindImpact
 *
 * @uses \OCA\Dossiq\Service\Cases\RebindValueConverter
 * @uses \OCA\Dossiq\Service\CaseTypeStore
 * @uses \OCA\Dossiq\Service\Cases\CaseAnswerReader
 * @uses \OCA\Dossiq\Exception\RefusedException
 * @uses \OCA\Dossiq\Service\CaseDateNormaliser
 */
class CaseRebindImpactGoldenTest extends TestCase {

	use BuildsRebindConverter;

	/**
	 * The impact over the two case types of this file.
	 *
	 * @return CaseRebindImpact The impact.
	 */
	private function impact(): CaseRebindImpact {
		$source = [
			['id' => 'src-naam', 'name' => 'naam'],
			['id' => 'src-aantal', 'name' => 'Aantal'],
			['id' => 'src-datum', 'name' => 'datum', 'propertyType' => 'date'],
			['id' => 'src-soort', 'name' => 'soort'],
			['id' => 'src-oud', 'name' => 'oud'],
		];
		$target = [
			['id' => 'dst-naam', 'name' => 'naam'],
			['id' => 'dst-aantal', 'name' => 'aantal', 'propertyType' => 'integer'],
			['id' => 'dst-start', 'name' => ' startdatum ', 'propertyType' => 'date'],
			['id' => 'dst-soort', 'name' => 'soort', 'propertyType' => 'integer'],
			['id' => 'dst-toelichting', 'name' => 'toelichting', 'description' => ' Waarom ', 'isRequired' => true],
			['id' => 'dst-besluit', 'name' => 'besluit', 'requiredAtStatus' => 'st-besluit', 'enumValues' => ['ja', 'nee']],
			['id' => 'dst-vrij', 'name' => 'vrij'],
		];
		$resolver = $this->createMock(CaseTypeResolver::class);
		$resolver->method('propertyDefinitionsFor')->willReturnCallback(
			static fn (string $caseTypeId): array => ['ct-src' => $source, 'ct-dst' => $target][$caseTypeId] ?? []
		);
		$store = new CaseTypeStore($this->createMock(SettingsService::class));

		return new CaseRebindImpact($store, $resolver, $this->rebindConverter(), new CaseAnswerReader($store, $this->rebindConverter()));
	}//end impact()

	/**
	 * The case on the source type.
	 *
	 * @return array<string, mixed> The case.
	 */
	private static function case(): array {
		return [
			'id' => 'case-1',
			'caseType' => 'ct-src',
			'properties' => [
				['propertyDefinition' => 'src-naam', 'name' => 'naam', 'value' => 'Eik'],
				['propertyDefinition' => 'src-aantal', 'name' => 'Aantal', 'value' => '12'],
				['propertyDefinition' => 'src-datum', 'name' => 'datum', 'value' => '2026-06-01'],
				['propertyDefinition' => 'src-soort', 'name' => 'soort', 'value' => 'groot'],
				['propertyDefinition' => 'src-oud', 'name' => 'oud', 'value' => 'weg'],
			],
		];
	}//end case()

	/**
	 * The whole impact, field by field and in order.
	 *
	 * @return void
	 */
	public function testTheWholeImpact(): void {
		$impact = $this->impact()->compute(
			self::case(),
			'ct-dst',
			'st-besluit',
			[' datum ' => ' startdatum ', 'leeg' => ' '],
			['Toelichting' => 'Omdat', 'besluit' => 'misschien']
		);

		$this->assertSame(json_decode(self::EXPECTED, true), json_decode(json_encode($impact), true));
	}//end testTheWholeImpact()

	/**
	 * Without a landing status only the flagged field is required, and nothing answered.
	 *
	 * @return void
	 */
	public function testWithoutALandingStatus(): void {
		$impact = $this->impact()->compute(self::case(), 'ct-dst', '');

		$this->assertSame(['toelichting'], array_column($impact['required'], 'name'));
		$this->assertSame([false], array_column($impact['required'], 'valid'));
		$this->assertFalse($impact['complete']);
		$this->assertSame(['naam', 'Aantal'], array_column($impact['ported'], 'source'));
		$this->assertSame(['datum', 'soort', 'oud'], array_column($impact['dropped'], 'name'));
	}//end testWithoutALandingStatus()

	/**
	 * A remap of an answer the case does not hold is refused.
	 *
	 * @return void
	 */
	public function testARemapOfAnUnknownAnswerIsRefused(): void {
		try {
			$this->impact()->compute(self::case(), 'ct-dst', '', ['nergens' => 'vrij']);
			$this->fail('An unknown answer must be refused.');
		} catch (RefusedException $e) {
			$this->assertSame('rebind-remap-unknown-answer', $e->getRule());
			$this->assertSame('This case has no answer called nergens to move.', $e->getSentence());
		}
	}//end testARemapOfAnUnknownAnswerIsRefused()

	/**
	 * A remap onto a field the target does not have is refused.
	 *
	 * @return void
	 */
	public function testARemapOntoAnUnknownFieldIsRefused(): void {
		try {
			$this->impact()->compute(self::case(), 'ct-dst', '', ['oud' => 'Nergens']);
			$this->fail('An unknown field must be refused.');
		} catch (RefusedException $e) {
			$this->assertSame('rebind-remap-unknown-field', $e->getRule());
			$this->assertSame('The target case type has no field called Nergens.', $e->getSentence());
		}
	}//end testARemapOntoAnUnknownFieldIsRefused()

	/**
	 * A remap onto a field a name match already took is refused, with its sentence.
	 *
	 * @return void
	 */
	public function testARemapOntoATakenFieldNamesIt(): void {
		try {
			$this->impact()->compute(self::case(), 'ct-dst', '', ['oud' => 'naam']);
			$this->fail('A taken field must be refused.');
		} catch (RefusedException $e) {
			$this->assertSame('rebind-remap-field-taken', $e->getRule());
			$this->assertSame('The field naam already receives another answer.', $e->getSentence());
		}
	}//end testARemapOntoATakenFieldNamesIt()

	/**
	 * A remap a value does not fit names the answer and the field.
	 *
	 * @return void
	 */
	public function testARemapThatDoesNotFitNamesBoth(): void {
		try {
			$this->impact()->compute(self::case(), 'ct-dst', '', ['oud' => 'soort']);
			$this->fail('A value that does not fit must be refused.');
		} catch (RefusedException $e) {
			$this->assertSame('The value of oud does not fit the field soort.', $e->getSentence());
		}
	}//end testARemapThatDoesNotFitNamesBoth()

	/**
	 * The impact the old code answered, as JSON.
	 */
	private const EXPECTED = '{"dropped":['
		.'{"name":"soort","definition":"src-soort","kind":"text","value":"groot","reason":"type","candidates":["toelichting","vrij"]}'
		.',{"name":"oud","definition":"src-oud","kind":"text","value":"weg","reason":"absent","candidates":["toelichting","vrij"]}'
		.'],"ported":['
		.'{"source":"naam","target":"naam","targetDefinition":"dst-naam","sourceKind":"text","targetKind":"text","value":"Eik","newValue":"Eik","mapping":"same"}'
		.',{"source":"Aantal","target":"aantal","targetDefinition":"dst-aantal","sourceKind":"text","targetKind":"integer","value":"12","newValue":"12","mapping":"converted"}'
		.',{"source":"datum","target":"startdatum","targetDefinition":"dst-start","sourceKind":"date","targetKind":"date","value":"2026-06-01","newValue":"2026-06-01","mapping":"remapped"}'
		.'],"required":['
		.'{"name":"toelichting","definition":"dst-toelichting","kind":"text","choices":[],"description":"Waarom","value":"Omdat","normalized":"Omdat","valid":true}'
		.',{"name":"besluit","definition":"dst-besluit","kind":"choice","choices":["ja","nee"],"description":"","value":"misschien","normalized":null,"valid":false}'
		.'],"complete":false}';
}//end class
