<?php

/**
 * How another version of a case type differs for one running case: the
 * status it would land in (matched by name, ignoring case and spacing), the
 * statuses and fields added and removed, which removed fields carry an
 * answer, and why a case without a landing status cannot move.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/zaaktype-versioning/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\CaseType;

use OCA\Dossiq\Service\CaseDateNormaliser;
use OCA\Dossiq\Service\Cases\CaseAnswerReader;
use OCA\Dossiq\Service\Cases\RebindValueConverter;
use OCA\Dossiq\Service\CaseType\CaseTypeVersionChain;
use OCA\Dossiq\Service\CaseType\CaseVersionDiff;
use OCA\Dossiq\Service\CaseTypeResolver;
use OCA\Dossiq\Service\CaseTypeStore;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\TranslatedText;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\CaseType\CaseVersionDiff
 * @uses   \OCA\Dossiq\Service\CaseTypeStore
 * @uses   \OCA\Dossiq\Service\Cases\CaseAnswerReader
 * @uses   \OCA\Dossiq\Service\Cases\RebindValueConverter
 * @uses   \OCA\Dossiq\Service\Support\TranslatedText
 * @uses   \OCA\Dossiq\Service\Support\LanguageMapText
 */
class CaseVersionDiffTest extends TestCase {

	/**
	 * Statuses per version.
	 *
	 * @var array<string, array<int, array<string, mixed>>>
	 */
	private array $statuses = [
		'v1' => [
			['id' => 's1-new', 'name' => 'Nieuw'],
			['id' => 's1-work', 'name' => 'In behandeling'],
			['id' => 's1-hold', 'name' => 'Aangehouden'],
			['id' => 's1-blank', 'name' => ''],
		],
		'v2' => [
			['id' => 's2-new', 'name' => ' nieuw '],
			['id' => 's2-work', 'name' => ['nl' => 'In Behandeling', 'en' => 'In progress']],
			['@self' => ['id' => 's2-adv'], 'name' => 'Advies'],
		],
	];

	/**
	 * Property definitions per version.
	 *
	 * @var array<string, array<int, array<string, mixed>>>
	 */
	private array $fields = [
		'v1' => [['name' => 'bsn'], ['name' => 'locatie'], ['name' => 'oppervlakte'], ['name' => ' '], ['name' => 'bsn']],
		'v2' => [['name' => 'bsn'], ['name' => 'locatie'], ['name' => 'motivatie']],
	];

	/**
	 * The diff under test.
	 *
	 * @return CaseVersionDiff
	 */
	private function diff(): CaseVersionDiff {
		$resolver = $this->createMock(CaseTypeResolver::class);
		$resolver->method('statusTypesFor')->willReturnCallback(fn (string $id): array => ($this->statuses[$id] ?? []));
		$resolver->method('propertyDefinitionsFor')->willReturnCallback(fn (string $id): array => ($this->fields[$id] ?? []));
		$chain = $this->createMock(CaseTypeVersionChain::class);
		$chain->method('versionsOf')->willReturnCallback(
			static fn (string $id): array => in_array($id, ['v1', 'v2'], true) === true
				? [['id' => 'v2', 'title' => 'Vergunning', 'version' => 2], ['id' => 'v1', 'title' => 'Vergunning', 'version' => 1]]
				: []
		);
		$store = new CaseTypeStore(settingsService: $this->createMock(SettingsService::class));

		return new CaseVersionDiff(
			store: $store,
			resolver: $resolver,
			chain: $chain,
			answers: new CaseAnswerReader(store: $store, converter: new RebindValueConverter(dates: $this->createMock(CaseDateNormaliser::class))),
			text: new TranslatedText()
		);
	}//end diff()

	/**
	 * A case whose status exists in the target lands in it; the version,
	 * status and field differences are listed.
	 *
	 * @return void
	 */
	public function testACaseLandsInTheSameNamedStatus(): void {
		$case = [
			'status' => ['id' => 's1-work'],
			'properties' => [
				['propertyDefinition' => 'pd-1', 'name' => 'Oppervlakte', 'value' => '120'],
				['propertyDefinition' => 'pd-2', 'name' => 'bsn', 'value' => '123456782'],
			],
		];

		$result = $this->diff()->between(case: $case, sourceId: 'v1', targetId: 'v2');

		self::assertSame(
			[
				'from' => ['id' => 'v1', 'title' => 'Vergunning', 'version' => 1],
				'to' => ['id' => 'v2', 'title' => 'Vergunning', 'version' => 2],
				'status' => ['from' => 'In behandeling', 'to' => 'In Behandeling', 'targetStatusId' => 's2-work', 'mapped' => true],
				'statuses' => ['added' => ['Advies'], 'removed' => ['Aangehouden']],
				'fields' => ['added' => ['motivatie'], 'removed' => ['oppervlakte'], 'answered' => ['oppervlakte']],
				'refusals' => [],
				'canMove' => true,
			],
			$result
		);
	}//end testACaseLandsInTheSameNamedStatus()

	/**
	 * A removed field without an answer is not listed as answered.
	 *
	 * @return void
	 */
	public function testAnUnansweredRemovedFieldCostsNothing(): void {
		$case = ['status' => 's1-new', 'properties' => [['name' => 'oppervlakte', 'value' => '']]];

		$result = $this->diff()->between(case: $case, sourceId: 'v1', targetId: 'v2');

		self::assertSame(['added' => ['motivatie'], 'removed' => ['oppervlakte'], 'answered' => []], $result['fields']);
		self::assertSame(['from' => 'Nieuw', 'to' => 'nieuw', 'targetStatusId' => 's2-new', 'mapped' => true], $result['status']);
	}//end testAnUnansweredRemovedFieldCostsNothing()

	/**
	 * A case in a status the target lacks cannot move, and says which.
	 *
	 * @return void
	 */
	public function testAStatusTheTargetLacksRefusesTheMove(): void {
		$result = $this->diff()->between(case: ['status' => 's1-hold'], sourceId: 'v1', targetId: 'v2');

		self::assertFalse($result['canMove']);
		self::assertSame(['from' => 'Aangehouden', 'to' => '', 'targetStatusId' => '', 'mapped' => false], $result['status']);
		self::assertSame(['The other version has no status called "Aangehouden", so this case has nowhere to land.'], $result['refusals']);
	}//end testAStatusTheTargetLacksRefusesTheMove()

	/**
	 * A case without a status of its own case type has nothing to map.
	 *
	 * @return void
	 */
	public function testACaseWithoutItsOwnStatusHasNothingToMap(): void {
		$expected = ['This case is not in a status of its own case type, so there is nothing to map onto the other version.'];

		self::assertSame($expected, $this->diff()->between(case: [], sourceId: 'v1', targetId: 'v2')['refusals']);
		self::assertSame($expected, $this->diff()->between(case: ['status' => 's-foreign'], sourceId: 'v1', targetId: 'v2')['refusals']);
	}//end testACaseWithoutItsOwnStatusHasNothingToMap()

	/**
	 * A version outside the chain is named by its id as version 1.
	 *
	 * @return void
	 */
	public function testAnUnknownVersionIsNamedByItsId(): void {
		$result = $this->diff()->between(case: [], sourceId: 'v1', targetId: 'v9');

		self::assertSame(['id' => 'v9', 'title' => '', 'version' => 1], $result['to']);
		self::assertSame(['added' => [], 'removed' => ['Aangehouden', 'In behandeling', 'Nieuw']], $result['statuses']);
		self::assertSame(['bsn', 'locatie', 'oppervlakte'], $result['fields']['removed']);
	}//end testAnUnknownVersionIsNamedByItsId()
}//end class
