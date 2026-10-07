<?php

/**
 * Unit tests for CaseRebindImpact and RebindValueConverter.
 *
 * 🔴 WHAT THESE GUARD IS AN ANSWER THAT CHANGES MEANING ON THE WAY OVER. A
 * rebind moves text between two case types' fields. "120" onto a number is
 * the same answer; "groot" onto a number is a broken one, and the impact must
 * say so by DROPPING it where the coordinator sees it, never by carrying it
 * over silently. The type-mismatch test is the one that matters most.
 *
 * The store is real (its id helpers are pure) and only the resolver's list of
 * definitions is stubbed, because the definitions are the input under test.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/case-type-rebind-property-impact/specs/zaaktype-versioning/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Cases;

use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\Cases\CaseAnswerReader;
use OCA\Dossiq\Service\Cases\CaseRebindImpact;
use OCA\Dossiq\Service\Cases\RebindValueConverter;
use OCA\Dossiq\Service\CaseTypeResolver;
use OCA\Dossiq\Service\CaseTypeStore;
use OCA\Dossiq\Service\SettingsService;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for CaseRebindImpact.
 *
 * @covers \OCA\Dossiq\Service\Cases\CaseRebindImpact
 * @covers \OCA\Dossiq\Service\Cases\RebindValueConverter
 *
 * @uses \OCA\Dossiq\Service\CaseTypeStore
 * @uses \OCA\Dossiq\Service\Cases\CaseAnswerReader
 * @uses \OCA\Dossiq\Exception\RefusedException
 */
class CaseRebindImpactTest extends TestCase {

	/**
	 * The impact over two case types' definitions.
	 *
	 * @param array<int, array<string, mixed>> $source The source type's definitions.
	 * @param array<int, array<string, mixed>> $target The target type's definitions.
	 *
	 * @return CaseRebindImpact The impact.
	 */
	private function impact(array $source, array $target): CaseRebindImpact {
		$resolver = $this->createMock(CaseTypeResolver::class);
		$resolver->method('propertyDefinitionsFor')->willReturnCallback(
			static fn (string $caseTypeId): array => match ($caseTypeId) {
				'ct-src' => $source,
				'ct-dst' => $target,
				default => [],
			}
		);

		$store = new CaseTypeStore($this->createMock(SettingsService::class));

		return new CaseRebindImpact(
			store: $store,
			resolver: $resolver,
			converter: new RebindValueConverter(),
			answers: new CaseAnswerReader(store: $store, converter: new RebindValueConverter()),
		);
	}//end impact()

	/**
	 * A case on the source type answering these, in the register's list shape.
	 *
	 * @param array<string, string> $answers Name => value.
	 *
	 * @return array<string, mixed> The case.
	 */
	private function caseAnswering(array $answers): array {
		$entries = [];
		foreach ($answers as $name => $value) {
			$entries[] = ['propertyDefinition' => 'src-' . $name, 'name' => $name, 'value' => $value];
		}

		return ['id' => 'case-1', 'caseType' => 'ct-src', 'properties' => $entries];
	}//end caseAnswering()

	/**
	 * Dropped, ported and required, each in its own group.
	 *
	 * @return void
	 */
	public function testTheThreeGroups(): void {
		$impact = $this->impact(
			source: [
				['id' => 'src-boomsoort', 'name' => 'boomsoort'],
				['id' => 'src-oppervlakte', 'name' => 'oppervlakte', 'propertyType' => 'integer'],
			],
			target: [
				['id' => 'dst-oppervlakte', 'name' => 'Oppervlakte', 'propertyType' => 'number'],
				['id' => 'dst-bouwjaar', 'name' => 'bouwjaar', 'propertyType' => 'integer', 'requiredAtStatus' => 'st-toets'],
				['id' => 'dst-later', 'name' => 'later', 'requiredAtStatus' => 'st-other'],
			],
		)->compute(
			case: $this->caseAnswering(['boomsoort' => 'eik', 'oppervlakte' => '120']),
			targetCaseTypeId: 'ct-dst',
			targetStatusId: 'st-toets',
		);

		self::assertSame(['boomsoort'], array_column($impact['dropped'], 'name'));
		self::assertSame('absent', $impact['dropped'][0]['reason']);
		self::assertSame('eik', $impact['dropped'][0]['value']);

		// Matched across case, converted from integer to number, same text.
		self::assertCount(1, $impact['ported']);
		self::assertSame('oppervlakte', $impact['ported'][0]['source']);
		self::assertSame('Oppervlakte', $impact['ported'][0]['target']);
		self::assertSame('dst-oppervlakte', $impact['ported'][0]['targetDefinition']);
		self::assertSame('converted', $impact['ported'][0]['mapping']);
		self::assertSame('120', $impact['ported'][0]['newValue']);

		// Required at the landing status only; a field required at another status is not asked.
		self::assertSame(['bouwjaar'], array_column($impact['required'], 'name'));
		self::assertSame('integer', $impact['required'][0]['kind']);
		self::assertFalse($impact['required'][0]['valid']);
		self::assertFalse($impact['complete']);
	}//end testTheThreeGroups()

	/**
	 * 🔴 A same-named answer whose value does not fit the target type is dropped, not converted.
	 *
	 * @return void
	 */
	public function testAValueThatDoesNotFitIsDroppedNotConverted(): void {
		$impact = $this->impact(
			source: [['id' => 'src-oppervlakte', 'name' => 'oppervlakte']],
			target: [['id' => 'dst-oppervlakte', 'name' => 'oppervlakte', 'propertyType' => 'number']],
		)->compute(
			case: $this->caseAnswering(['oppervlakte' => 'groot']),
			targetCaseTypeId: 'ct-dst',
			targetStatusId: '',
		);

		self::assertSame([], $impact['ported']);
		self::assertSame('type', $impact['dropped'][0]['reason']);
		self::assertSame([], $impact['dropped'][0]['candidates']);
	}//end testAValueThatDoesNotFitIsDroppedNotConverted()

	/**
	 * A choice field takes only a listed answer; a structured field only its own kind.
	 *
	 * @return void
	 */
	public function testChoicesAndStructuredKindsAreStrict(): void {
		$impact = $this->impact(
			source: [
				['id' => 'src-kleur', 'name' => 'kleur'],
				['id' => 'src-plek', 'name' => 'plek', 'propertyType' => 'geo'],
				['id' => 'src-maat', 'name' => 'maat'],
			],
			target: [
				['id' => 'dst-kleur', 'name' => 'kleur', 'enumValues' => ['rood', 'groen']],
				['id' => 'dst-plek', 'name' => 'plek', 'propertyType' => 'string'],
				['id' => 'dst-maat', 'name' => 'maat', 'enumValues' => ['S', 'M']],
			],
		)->compute(
			case: $this->caseAnswering(['kleur' => 'paars', 'plek' => '{"type":"Point"}', 'maat' => 'M']),
			targetCaseTypeId: 'ct-dst',
			targetStatusId: '',
		);

		self::assertSame(['kleur', 'plek'], array_column($impact['dropped'], 'name'));
		self::assertSame(['maat'], array_column($impact['ported'], 'target'));
		// Free text onto a list is a change of kind, even when the word is listed.
		self::assertSame('converted', $impact['ported'][0]['mapping']);
	}//end testChoicesAndStructuredKindsAreStrict()

	/**
	 * A remap moves a dropped answer onto a compatible free field, and fills a required one.
	 *
	 * @return void
	 */
	public function testARemapPortsAnAnswerAndSatisfiesARequiredField(): void {
		$impact = $this->impact(
			source: [['id' => 'src-datum', 'name' => 'datum', 'propertyType' => 'date']],
			target: [['id' => 'dst-start', 'name' => 'startdatum', 'propertyType' => 'date', 'isRequired' => true]],
		);
		$case = $this->caseAnswering(['datum' => '2026-06-01']);

		$before = $impact->compute(case: $case, targetCaseTypeId: 'ct-dst', targetStatusId: '');
		self::assertSame(['startdatum'], $before['dropped'][0]['candidates']);
		self::assertSame(['startdatum'], array_column($before['required'], 'name'));

		$after = $impact->compute(case: $case, targetCaseTypeId: 'ct-dst', targetStatusId: '', remap: ['datum' => 'startdatum']);
		self::assertSame([], $after['dropped']);
		self::assertSame('remapped', $after['ported'][0]['mapping']);
		self::assertSame([], $after['required']);
		self::assertTrue($after['complete']);

		$applied = $impact->apply(case: $case, impact: $after);
		self::assertSame(
			[['propertyDefinition' => 'dst-start', 'name' => 'startdatum', 'value' => '2026-06-01']],
			$applied['properties']
		);
	}//end testARemapPortsAnAnswerAndSatisfiesARequiredField()

	/**
	 * A remap onto a field the value does not fit is refused, naming the rule.
	 *
	 * @return void
	 */
	public function testARemapThatDoesNotFitIsRefused(): void {
		$impact = $this->impact(
			source: [['id' => 'src-boomsoort', 'name' => 'boomsoort']],
			target: [['id' => 'dst-aantal', 'name' => 'aantal', 'propertyType' => 'integer']],
		);

		try {
			$impact->compute(
				case: $this->caseAnswering(['boomsoort' => 'eik']),
				targetCaseTypeId: 'ct-dst',
				targetStatusId: '',
				remap: ['boomsoort' => 'aantal'],
			);
			self::fail('A remap the value does not fit must be refused.');
		} catch (RefusedException $e) {
			self::assertSame('rebind-remap-does-not-fit', $e->getRule());
		}
	}//end testARemapThatDoesNotFitIsRefused()

	/**
	 * A remap onto a field another answer already takes is refused.
	 *
	 * @return void
	 */
	public function testARemapOntoATakenFieldIsRefused(): void {
		$impact = $this->impact(
			source: [['id' => 'src-a', 'name' => 'a'], ['id' => 'src-b', 'name' => 'b']],
			target: [['id' => 'dst-a', 'name' => 'a']],
		);

		$this->expectException(RefusedException::class);
		$this->expectExceptionMessage('rebind_remap_field_taken');

		$impact->compute(
			case: $this->caseAnswering(['a' => 'x', 'b' => 'y']),
			targetCaseTypeId: 'ct-dst',
			targetStatusId: '',
			remap: ['b' => 'a'],
		);
	}//end testARemapOntoATakenFieldIsRefused()

	/**
	 * A required answer is valid only when it fits, and is saved normalised.
	 *
	 * @return void
	 */
	public function testRequiredAnswersAreValidatedAndApplied(): void {
		$impact = $this->impact(
			source: [],
			target: [
				['id' => 'dst-jaar', 'name' => 'bouwjaar', 'propertyType' => 'integer', 'isRequired' => true],
				['id' => 'dst-ok', 'name' => 'akkoord', 'propertyType' => 'boolean', 'isRequired' => true],
			],
		);
		$case = ['id' => 'case-1', 'caseType' => 'ct-src', 'properties' => []];

		$bad = $impact->compute(case: $case, targetCaseTypeId: 'ct-dst', targetStatusId: '', answers: ['bouwjaar' => '1974a', 'akkoord' => 'ja']);
		self::assertSame([false, true], array_column($bad['required'], 'valid'));
		self::assertFalse($bad['complete']);

		$good = $impact->compute(case: $case, targetCaseTypeId: 'ct-dst', targetStatusId: '', answers: ['bouwjaar' => 1974, 'akkoord' => true]);
		self::assertTrue($good['complete']);
		self::assertSame(
			[
				['propertyDefinition' => 'dst-jaar', 'name' => 'bouwjaar', 'value' => '1974'],
				['propertyDefinition' => 'dst-ok', 'name' => 'akkoord', 'value' => 'true'],
			],
			$impact->apply(case: $case, impact: $good)['properties']
		);
	}//end testRequiredAnswersAreValidatedAndApplied()

	/**
	 * A legacy name-keyed map is still read, and the list is what is written back.
	 *
	 * @return void
	 */
	public function testALegacyMapIsReadAndTheListIsWritten(): void {
		$impact = $this->impact(
			source: [],
			target: [['id' => 'dst-boomsoort', 'name' => 'boomsoort']],
		);
		$case = ['id' => 'case-1', 'caseType' => 'ct-src', 'properties' => ['boomsoort' => 'eik', 'leeg' => '']];

		$computed = $impact->compute(case: $case, targetCaseTypeId: 'ct-dst', targetStatusId: '');
		self::assertSame([], $computed['dropped']);
		self::assertSame(
			[['propertyDefinition' => 'dst-boomsoort', 'name' => 'boomsoort', 'value' => 'eik']],
			$impact->apply(case: $case, impact: $computed)['properties']
		);
	}//end testALegacyMapIsReadAndTheListIsWritten()
}//end class
