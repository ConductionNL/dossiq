<?php

/**
 * Unit tests for CaseTypeRuleSentences.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\CaseType
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/case-type-conditional-rules/specs/case-types/spec.md#requirement-the-rules-tab-reads-and-writes-the-case-types-rules
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\CaseType;

use OCA\Dossiq\Service\CaseType\CaseTypeRuleSentences;
use OCA\Dossiq\Service\CaseTypeResolver;
use OCA\Dossiq\Service\Support\TranslatedText;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the rows the Rules tab of a case type shows.
 *
 * @covers \OCA\Dossiq\Service\CaseType\CaseTypeRuleSentences
 * @uses   \OCA\Dossiq\Service\Support\TranslatedText
 * @uses   \OCA\Dossiq\Service\Support\LanguageMapText
 */
class CaseTypeRuleSentencesTest extends TestCase {

	/**
	 * The service over one effective case type and its statuses, with an English translator.
	 *
	 * @param array<string, mixed>             $caseType The effective case type.
	 * @param array<int, array<string, mixed>> $statuses The case type's statuses.
	 *
	 * @return CaseTypeRuleSentences The service.
	 */
	private function sentences(array $caseType, array $statuses = []): CaseTypeRuleSentences {
		$resolver = $this->createMock(CaseTypeResolver::class);
		$resolver->method('effectiveCaseType')->with('ct-1')->willReturn($caseType);
		$resolver->method('statusTypesFor')->willReturn($statuses);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static fn (string $text, array $parameters = []): string => vsprintf($text, $parameters)
		);
		$l10n->method('getLanguageCode')->willReturn('en');

		return new CaseTypeRuleSentences(resolver: $resolver, l10n: $l10n, text: new TranslatedText(l10n: $l10n));
	}//end sentences()

	/**
	 * The rows of one rule set.
	 *
	 * @param array<int, mixed>                $rules    The stored rules.
	 * @param array<int, array<string, mixed>> $statuses The case type's statuses.
	 *
	 * @return array<int, array<string, mixed>> The rows.
	 */
	private function rows(array $rules, array $statuses = []): array {
		return $this->sentences(caseType: ['id' => 'ct-1', 'rules' => $rules], statuses: $statuses)->rulesFor(caseTypeId: 'ct-1')['rules'];
	}//end rows()

	/**
	 * The board's own example reads as one "If / then" row that is on.
	 *
	 * @return void
	 */
	public function testTheBoardExampleReadsAsOneRow(): void {
		$rows = $this->rows(
			rules: [
				[
					'id'         => 'structural-review',
					'name'       => 'Structural review for large building costs',
					'when'       => ['event' => 'saved'],
					'conditions' => [['kind' => 'fieldCompare', 'field' => 'bouwkosten', 'op' => 'gt', 'value' => '100000']],
					'actions'    => [['action' => 'addTask', 'taskType' => 'toets-constructeur']],
				],
			]
		);

		$this->assertSame(
			[
				[
					'id'      => 'structural-review',
					'name'    => 'Structural review for large building costs',
					'enabled' => true,
					'state'   => 'On',
					'moment'  => 'When a case is saved',
					'title'   => 'If bouwkosten is greater than 100000',
					'line'    => 'then add task toets-constructeur',
				],
			],
			$rows
		);
	}//end testTheBoardExampleReadsAsOneRow()

	/**
	 * Every operator reads as its own comparison, and an unknown one as equality.
	 *
	 * @return void
	 */
	public function testEveryOperatorReadsAsItsComparison(): void {
		$titles = [];
		foreach (['eq', 'neq', 'gt', 'gte', 'lt', 'lte', 'like'] as $op) {
			$titles[] = $this->rows(
				rules: [['id' => $op, 'conditions' => [['kind' => 'fieldCompare', 'field' => 'n', 'op' => $op, 'value' => 5]]]]
			)[0]['title'];
		}

		$this->assertSame(
			[
				'If n is 5',
				'If n is not 5',
				'If n is greater than 5',
				'If n is at least 5',
				'If n is less than 5',
				'If n is at most 5',
				'If n is 5',
			],
			$titles
		);
	}//end testEveryOperatorReadsAsItsComparison()

	/**
	 * Every condition kind reads in words, joined by "and"; a status by its name.
	 *
	 * @return void
	 */
	public function testEveryConditionKindReadsInWordsJoinedByAnd(): void {
		$row = $this->rows(
			rules: [
				[
					'id'         => 'all',
					'conditions' => [
						['kind' => 'fieldEquals', 'field' => 'welstandsgebied', 'value' => true],
						['kind' => 'fieldPresent', 'field' => 'adres'],
						['kind' => 'documentPresent', 'documentType' => 'Bouwtekening'],
						['kind' => 'statusIs', 'status' => 'st-1'],
						['kind' => 'statusIs', 'status' => 'st-unknown'],
						['kind' => 'somethingNew'],
						'not a condition',
					],
				],
			],
			statuses: [['id' => 'st-1', 'name' => 'In behandeling'], ['@self' => ['id' => 'st-2'], 'name' => 'Besluit'], ['name' => 'no id']]
		)[0];

		$this->assertSame(
			'If welstandsgebied is yes and adres is filled in and the case has a document of type Bouwtekening'
			.' and the status is In behandeling and the status is st-unknown and a condition dossiq does not know',
			$row['title']
		);
	}//end testEveryConditionKindReadsInWordsJoinedByAnd()

	/**
	 * Every action kind reads in words, joined by commas.
	 *
	 * @return void
	 */
	public function testEveryActionKindReadsInWords(): void {
		$row = $this->rows(
			rules: [
				[
					'id'      => 'all',
					'actions' => [
						['action' => 'addTask', 'taskType' => 'toets'],
						['action' => 'setField', 'field' => 'prioriteit', 'value' => 'hoog'],
						['action' => 'setField', 'field' => 'motivering'],
						['action' => 'setField', 'field' => 'labels', 'value' => ['a', 'b']],
						['action' => 'setField', 'field' => 'spoed', 'value' => false],
						['action' => 'chooseVariant', 'variant' => 'Met welstand'],
						['action' => 'requireDocument', 'documentType' => 'Advies'],
						['action' => 'sendMessage', 'emailTemplate' => 'weigering'],
						['action' => 'teleport'],
					],
				],
			]
		)[0];

		$this->assertSame('Always', $row['title']);
		$this->assertSame(
			'then add task toets, set prioriteit to hoog, make motivering required, set labels to ["a","b"],'
			.' set spoed to no, choose variant Met welstand, require a document of type Advies, send message weigering,'
			.' an action dossiq does not know',
			$row['line']
		);
	}//end testEveryActionKindReadsInWords()

	/**
	 * Each moment reads in words, a status by its name; an unknown or missing one is empty.
	 *
	 * @return void
	 */
	public function testEachMomentReadsInWords(): void {
		$rows = $this->rows(
			rules: [
				['id' => 'a', 'when' => ['event' => 'created']],
				['id' => 'b', 'when' => ['event' => 'statusEntered', 'status' => 'st-1']],
				['id' => 'c', 'when' => ['event' => 'statusEntered', 'status' => 'st-gone']],
				['id' => 'd', 'when' => ['event' => 'later']],
				['id' => 'e', 'when' => 'saved'],
				['id' => 'f'],
			],
			statuses: [['id' => 'st-1', 'name' => ['en' => 'Refused', 'nl' => 'Geweigerd']]]
		);

		$this->assertSame(
			['When a case is created', 'When a case enters status Refused', 'When a case enters status st-gone', '', '', ''],
			array_column($rows, 'moment')
		);
	}//end testEachMomentReadsInWords()

	/**
	 * A rule turned off reads Off; a rule without actions says nothing happens; non-rules are skipped.
	 *
	 * @return void
	 */
	public function testARuleTurnedOffAndAnEmptyRule(): void {
		$rows = $this->rows(rules: [['id' => 'x', 'enabled' => false, 'name' => ['not', 'text']], 'junk', ['id' => 7]]);

		$this->assertCount(2, $rows);
		$this->assertSame(
			['id' => 'x', 'name' => '', 'enabled' => false, 'state' => 'Off', 'moment' => '', 'title' => 'Always', 'line' => 'then nothing happens'],
			$rows[0]
		);
		$this->assertSame('7', $rows[1]['id']);
		$this->assertTrue($rows[1]['enabled']);
	}//end testARuleTurnedOffAndAnEmptyRule()

	/**
	 * A case type without rules answers an empty list; a missing one null.
	 *
	 * @return void
	 */
	public function testNoRulesIsEmptyAndAMissingCaseTypeIsNull(): void {
		$this->assertSame(['rules' => []], $this->sentences(caseType: ['id' => 'ct-1'])->rulesFor(caseTypeId: 'ct-1'));
		$this->assertSame(['rules' => []], $this->sentences(caseType: ['id' => 'ct-1', 'rules' => 'nope'])->rulesFor(caseTypeId: 'ct-1'));
		$this->assertNull($this->sentences(caseType: [])->rulesFor(caseTypeId: 'ct-1'));
	}//end testNoRulesIsEmptyAndAMissingCaseTypeIsNull()
}//end class
