<?php

/**
 * Answer tests: three request shapes onto one, the gates, the outcome rule.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Inspection
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
 * @spec openspec/changes/inspection-checklists-onto-task/specs/inspection-checklists/spec.md#requirement-inspection-rapport-creation
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Inspection;

use OCA\Dossiq\Service\Inspection\InspectionAnswers;
use PHPUnit\Framework\TestCase;

/**
 * Tests for {@see InspectionAnswers}.
 *
 * @covers \OCA\Dossiq\Service\Inspection\InspectionAnswers
 * @uses   \OCA\Dossiq\Service\ChecklistService
 * @uses   \OCA\Dossiq\Service\Support\ChecklistPayloadReader
 */
final class InspectionAnswersTest extends TestCase {

	/**
	 * The answers under test.
	 *
	 * @var InspectionAnswers
	 */
	private InspectionAnswers $answers;

	/**
	 * Build it.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->answers = new InspectionAnswers();
	}//end setUp()

	/**
	 * Stack A, stack C and native answers all leave in the template's words.
	 *
	 * @return void
	 */
	public function testEveryRequestShapeLeavesInTheTemplatesVocabulary(): void {
		$a = $this->answers->fromPayload(payload: ['items' => [['itemId' => 'q1', 'result' => 'fail', 'comment' => 'scheur', 'photos' => ['11'], 'measurement' => '4.5']]]);
		$c = $this->answers->fromPayload(payload: ['answers' => [['itemRef' => 'q1', 'value' => 'not_applicable', 'photoRef' => '12', 'remark' => 'n.v.t.']]]);
		$n = $this->answers->fromPayload(payload: ['responses' => [['itemId' => 'q1', 'value' => 'ja'], ['value' => 'nee']]]);

		$this->assertSame(['itemId' => 'q1', 'value' => 'nee', 'numericValue' => 4.5, 'comment' => 'scheur', 'photos' => ['11']], $a[0]);
		$this->assertSame(['itemId' => 'q1', 'value' => 'nvt', 'comment' => 'n.v.t.', 'photos' => ['12']], $c[0]);
		$this->assertSame([['itemId' => 'q1', 'value' => 'ja']], $n, 'an answer naming no item is dropped');
	}//end testEveryRequestShapeLeavesInTheTemplatesVocabulary()

	/**
	 * The outcome rule over the answered items that apply.
	 *
	 * @return void
	 */
	public function testTheOutcomeRule(): void {
		$snapshot = self::snapshot();

		$this->assertSame('conform', $this->answers->outcome(snapshot: $snapshot, answers: [['itemId' => 'q1', 'value' => 'ja'], ['itemId' => 'q2', 'value' => 'nvt']]));
		$this->assertSame('non_conform', $this->answers->outcome(snapshot: $snapshot, answers: [['itemId' => 'q1', 'value' => 'nee'], ['itemId' => 'q2', 'value' => 'nvt']]));
		$this->assertSame('partly_conform', $this->answers->outcome(snapshot: $snapshot, answers: [['itemId' => 'q1', 'value' => 'nee'], ['itemId' => 'q2', 'value' => 'ja']]));
		$this->assertSame('conform', $this->answers->outcome(snapshot: $snapshot, answers: [['itemId' => 'q2', 'value' => 'nvt']]), 'nothing that applies failed');
	}//end testTheOutcomeRule()

	/**
	 * Required items and the photo gate are checked against the snapshot.
	 *
	 * @return void
	 */
	public function testRequiredItemsAndThePhotoGateAreChecked(): void {
		$snapshot = self::snapshot();

		$this->assertSame([], $this->answers->violations(snapshot: $snapshot, answers: [['itemId' => 'q1', 'value' => 'ja']]));
		$this->assertCount(1, $this->answers->violations(snapshot: $snapshot, answers: []), 'q1 is required');
		$this->assertCount(1, $this->answers->violations(snapshot: $snapshot, answers: [['itemId' => 'q1', 'value' => 'nee']]), 'nee on q1 needs a photo');
		$this->assertSame([], $this->answers->violations(snapshot: $snapshot, answers: [['itemId' => 'q1', 'value' => 'nee', 'photos' => ['9']]]));
	}//end testRequiredItemsAndThePhotoGateAreChecked()

	/**
	 * Evidence lists every photo once; the panel reads pass, fail, nvt.
	 *
	 * @return void
	 */
	public function testEvidenceAndThePanelWords(): void {
		$answers = [['itemId' => 'q1', 'value' => 'nee', 'photos' => ['9', '10']], ['itemId' => 'q2', 'value' => 'ja', 'photos' => ['9']]];

		$this->assertSame(['9', '10'], $this->answers->evidence(answers: $answers));
		$this->assertSame(1, $this->answers->failedCount(answers: $answers));
		$this->assertSame('fail', $this->answers->forPanel(answer: $answers[0])['result']);
		$this->assertSame('pass', $this->answers->forPanel(answer: $answers[1])['result']);
	}//end testEvidenceAndThePanelWords()

	/**
	 * Two yes/no items: q1 required with a photo on nee, q2 optional.
	 *
	 * @return array<string, mixed> The snapshot.
	 */
	private static function snapshot(): array {
		return [
			'sections' => [
				[
					'items' => [
						['id' => 'q1', 'label' => 'Wapening', 'responseType' => 'yes_no_na', 'required' => true, 'photoRequired' => 'if_no'],
						['id' => 'q2', 'label' => 'Maatvoering', 'responseType' => 'yes_no_na'],
					],
				],
			],
		];
	}//end snapshot()
}//end class
