<?php

/**
 * ScoringSheetTest
 *
 * The bids of a case are scored with the formula the case names: price and
 * lowest price read from the bids, the case's own values fixed, every other
 * value entered by the evaluator, and the evaluator's evaluation stored once.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\Scoring
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/tenders-run-on-the-tender-case-type/tasks.md#3.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Scoring;

use OCA\Dossiq\Kernel\Contracts\CaseObjectReaderInterface;
use OCA\Dossiq\Service\Scoring\ScoringRecords;
use OCA\Dossiq\Service\Scoring\ScoringSheet;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Tests\Support\AnonymousRefusingRegister;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * Tests for ScoringSheet.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\Scoring
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec openspec/changes/tenders-run-on-the-tender-case-type/tasks.md#3.2
 */
class ScoringSheetTest extends TestCase {

	/**
	 * The price-quality formula as the register ships it.
	 *
	 * @var array<string, mixed>
	 */
	private const PRICE_QUALITY = [
		'key' => 'price-quality',
		'title' => 'Best price-quality ratio',
		'expression' => 'round(qualityWeight * quality + priceWeight * maxPoints * lowestPrice / price, 2)',
		'variables' => ['qualityWeight', 'quality', 'priceWeight', 'maxPoints', 'lowestPrice', 'price'],
		'active' => true,
	];

	/**
	 * The fake register.
	 *
	 * @var AnonymousRefusingRegister
	 */
	private AnonymousRefusingRegister $register;

	/**
	 * The cases by uuid.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $cases = [];

	/**
	 * A tender on the price-quality formula with three bids, one not yet admissible.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->register = new AnonymousRefusingRegister(actor: fn (): string => 'eva');
		$this->register->seed(schema: 'scoringFormula', id: 'formula-pq', row: self::PRICE_QUALITY);
		$this->register->seed(
			schema: 'scoringFormula',
			id: 'formula-old',
			row: ['key' => 'old', 'title' => 'Old', 'expression' => 'price', 'variables' => ['price'], 'active' => false]
		);
		$this->cases['tender-1'] = [
			'id' => 'tender-1',
			'properties' => [
				['name' => 'scoringFormula', 'value' => 'price-quality'],
				['name' => 'maxPoints', 'value' => 100],
				['name' => 'qualityWeight', 'value' => '0.6'],
				['name' => 'priceWeight', 'value' => 0.4],
			],
		];
		$this->bid(id: 'bid-a', price: 80000, state: 'admissible', ref: 'INS-A');
		$this->bid(id: 'bid-b', price: 100000, state: 'evaluated', ref: 'INS-B');
		$this->bid(id: 'bid-c', price: 50000, state: 'received', ref: 'INS-C');
	}//end setUp()

	/**
	 * The sheet names the formula, the case's values, what is entered, and the admissible bids.
	 *
	 * @return void
	 */
	public function testTheSheetSplitsTheFormulaIntoTheCasesValuesAndWhatIsEntered(): void {
		$sheet = $this->sheet()->forCase(caseId: 'tender-1', userId: 'eva');

		$this->assertSame(['key' => 'price-quality', 'title' => 'Best price-quality ratio'], $sheet['formula']);
		$this->assertSame(['quality'], $sheet['inputs']);
		$this->assertSame(['qualityWeight' => 0.6, 'priceWeight' => 0.4, 'maxPoints' => 100.0], $sheet['fixed']);
		$this->assertSame(['bid-a', 'bid-b'], array_column($sheet['submissions'], 'id'), 'a bid still received is not scored');
		$this->assertSame('INS-A', $sheet['submissions'][0]['label']);
		$this->assertSame(80000.0, $sheet['submissions'][0]['price']);
		$this->assertNull($sheet['submissions'][0]['total']);
		$this->assertSame(expected: 'admissible', actual: $sheet['submissions'][0]['state'], message: 'the row says its state, so the award dialog shows the proposed bid');
		$this->assertSame(expected: '', actual: $sheet['submissions'][0]['lot'], message: 'a bid without a lot answers an empty lot');
		$this->assertNull($sheet['refusal']);
	}//end testTheSheetSplitsTheFormulaIntoTheCasesValuesAndWhatIsEntered()

	/**
	 * Scoring applies the formula with the lowest admissible price and stores one evaluation.
	 *
	 * @return void
	 */
	public function testScoringAppliesTheFormulaAndStoresTheEvaluatorsEvaluation(): void {
		$result = $this->sheet()->score(caseId: 'tender-1', submissionId: 'bid-b', scores: ['quality' => '70'], userId: 'eva');

		// 0.6 * 70 + 0.4 * 100 * 80000 / 100000: the received bid's lower price does not count.
		$this->assertSame(74.0, $result['total']);
		$evaluation = $this->register->row(schema: 'evaluation', id: $result['evaluationId']);
		$this->assertSame('bid-b', $evaluation['bid']);
		$this->assertSame('tender-1', $evaluation['tender']);
		$this->assertSame('eva', $evaluation['evaluator']);
		$this->assertSame(['quality' => 70.0], $evaluation['criterionScores']);
		$this->assertSame(74.0, $evaluation['weightedTotal']);
		$this->assertSame('submitted', $evaluation['state']);
	}//end testScoringAppliesTheFormulaAndStoresTheEvaluatorsEvaluation()

	/**
	 * Scoring the same bid again changes the evaluator's evaluation instead of adding one.
	 *
	 * @return void
	 */
	public function testScoringAgainUpdatesTheSameEvaluation(): void {
		$first  = $this->sheet()->score(caseId: 'tender-1', submissionId: 'bid-a', scores: ['quality' => 50], userId: 'eva');
		$second = $this->sheet()->score(caseId: 'tender-1', submissionId: 'bid-a', scores: ['quality' => 90], userId: 'eva');

		$this->assertSame($first['evaluationId'], $second['evaluationId']);
		$this->assertSame(94.0, $second['total']);
		$this->assertCount(1, $this->register->findAll(config: ['filters' => ['schema' => 'evaluation']]));

		$sheet = $this->sheet()->forCase(caseId: 'tender-1', userId: 'eva');
		$this->assertSame(94.0, $sheet['submissions'][0]['total']);
		$this->assertSame(['quality' => 90.0], $sheet['submissions'][0]['scores']);
	}//end testScoringAgainUpdatesTheSameEvaluation()

	/**
	 * Another evaluator's evaluation is theirs: it is neither shown nor overwritten.
	 *
	 * @return void
	 */
	public function testAnotherEvaluatorsEvaluationIsLeftAlone(): void {
		$this->register->seed(
			schema: 'evaluation',
			id: 'evaluation-piet',
			row: ['bid' => 'bid-a', 'tender' => 'tender-1', 'evaluator' => 'piet', 'criterionScores' => ['quality' => 10], 'weightedTotal' => 46.0, 'state' => 'submitted']
		);

		$this->assertNull($this->sheet()->forCase(caseId: 'tender-1', userId: 'eva')['submissions'][0]['total']);

		$result = $this->sheet()->score(caseId: 'tender-1', submissionId: 'bid-a', scores: ['quality' => 80], userId: 'eva');
		$this->assertNotSame('evaluation-piet', $result['evaluationId']);
		$this->assertSame(46.0, $this->register->row(schema: 'evaluation', id: 'evaluation-piet')['weightedTotal']);
	}//end testAnotherEvaluatorsEvaluationIsLeftAlone()

	/**
	 * A finalised evaluation no longer changes.
	 *
	 * @return void
	 */
	public function testAFinalisedEvaluationIsNotChanged(): void {
		$this->register->seed(
			schema: 'evaluation',
			id: 'evaluation-final',
			row: ['bid' => 'bid-a', 'tender' => 'tender-1', 'evaluator' => 'eva', 'criterionScores' => ['quality' => 60], 'weightedTotal' => 76.0, 'state' => 'finalised']
		);

		$result = $this->sheet()->score(caseId: 'tender-1', submissionId: 'bid-a', scores: ['quality' => 99], userId: 'eva');

		$this->assertSame('Your evaluation of this bid is no longer open, so its score cannot change.', $result['refusal']);
		$this->assertSame(76.0, $this->register->row(schema: 'evaluation', id: 'evaluation-final')['weightedTotal']);
	}//end testAFinalisedEvaluationIsNotChanged()

	/**
	 * A lot that names no formula is scored by the tender its bids point at.
	 *
	 * @return void
	 */
	public function testALotIsScoredByTheFormulaOfItsTender(): void {
		$this->cases['lot-1'] = ['id' => 'lot-1', 'properties' => []];
		$this->register->seed(
			schema: 'bid',
			id: 'bid-lot',
			row: ['tender' => 'tender-1', 'case' => 'lot-1', 'priceAmount' => 40000, 'state' => 'admissible']
		);

		$sheet = $this->sheet()->forCase(caseId: 'lot-1', userId: 'eva');

		$this->assertSame('price-quality', $sheet['formula']['key']);
		$this->assertSame(['bid-lot'], array_column($sheet['submissions'], 'id'));
		$result = $this->sheet()->score(caseId: 'lot-1', submissionId: 'bid-lot', scores: ['quality' => 50], userId: 'eva');
		$this->assertSame(70.0, $result['total'], 'the lowest price is the lot\'s own');
	}//end testALotIsScoredByTheFormulaOfItsTender()

	/**
	 * A formula value the case does not hold is entered per bid.
	 *
	 * @return void
	 */
	public function testAValueTheCaseDoesNotHoldIsEnteredPerBid(): void {
		$this->cases['tender-1']['properties'] = [['name' => 'scoringFormula', 'value' => 'price-quality'], ['name' => 'maxPoints', 'value' => 100]];

		$this->assertSame(['qualityWeight', 'quality', 'priceWeight'], $this->sheet()->forCase(caseId: 'tender-1', userId: 'eva')['inputs']);
	}//end testAValueTheCaseDoesNotHoldIsEnteredPerBid()

	/**
	 * Every refusal is a sentence, and nothing is written.
	 *
	 * @return void
	 */
	public function testEachRefusalNamesWhatIsMissingAndWritesNothing(): void {
		$sheet = $this->sheet();

		$this->assertSame(
			'Only an admissible bid on this case can be scored.',
			$sheet->score(caseId: 'tender-1', submissionId: 'bid-c', scores: ['quality' => 1], userId: 'eva')['refusal']
		);
		$this->assertSame(
			'Enter a number for quality.',
			$sheet->score(caseId: 'tender-1', submissionId: 'bid-a', scores: ['quality' => 'veel'], userId: 'eva')['refusal']
		);

		$this->cases['tender-1']['properties'][0]['value'] = 'old';
		$this->assertSame(
			'The scoring formula "old" is not in the register, or may no longer be chosen.',
			$sheet->score(caseId: 'tender-1', submissionId: 'bid-a', scores: ['quality' => 1], userId: 'eva')['refusal']
		);

		$this->cases['tender-1']['properties'][0]['value'] = '';
		$this->assertSame(
			'This case names no scoring formula. Set the scoring formula on the case first.',
			$sheet->forCase(caseId: 'tender-1', userId: 'eva')['refusal']
		);

		$this->assertSame([], $this->register->findAll(config: ['filters' => ['schema' => 'evaluation']]));
	}//end testEachRefusalNamesWhatIsMissingAndWritesNothing()

	/**
	 * A bid without a price cannot be scored by a formula that scores the price.
	 *
	 * @return void
	 */
	public function testABidWithoutAPriceIsRefusedByAPriceFormula(): void {
		$this->register->seed(schema: 'bid', id: 'bid-free', row: ['tender' => 'tender-1', 'case' => 'tender-1', 'state' => 'admissible']);

		$this->assertSame(
			'This bid names no price, and the scoring formula scores the price.',
			$this->sheet()->score(caseId: 'tender-1', submissionId: 'bid-free', scores: ['quality' => 80], userId: 'eva')['refusal']
		);
	}//end testABidWithoutAPriceIsRefusedByAPriceFormula()

	/**
	 * A case with no admissible bid says so.
	 *
	 * @return void
	 */
	public function testACaseWithoutAdmissibleBidsSaysSo(): void {
		$this->cases['tender-2'] = $this->cases['tender-1'];

		$sheet = $this->sheet()->forCase(caseId: 'tender-2', userId: 'eva');

		$this->assertSame([], $sheet['submissions']);
		$this->assertSame('There is no admissible bid to score on this case.', $sheet['refusal']);
	}//end testACaseWithoutAdmissibleBidsSaysSo()

	/**
	 * Seed one bid on tender-1.
	 *
	 * @param string $id    The bid id.
	 * @param int    $price The price.
	 * @param string $state The state.
	 * @param string $ref   The reference.
	 *
	 * @return void
	 */
	private function bid(string $id, int $price, string $state, string $ref): void {
		$this->register->seed(
			schema: 'bid',
			id: $id,
			row: ['tender' => 'tender-1', 'case' => 'tender-1', 'priceAmount' => $price, 'state' => $state, 'submissionRef' => $ref]
		);
	}//end bid()

	/**
	 * The sheet over the fake register and the cases of this test.
	 *
	 * @return ScoringSheet
	 */
	private function sheet(): ScoringSheet {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->register);

		$cases = $this->createMock(CaseObjectReaderInterface::class);
		$cases->method('fetchCaseById')->willReturnCallback(fn (string $caseUuid): ?array => ($this->cases[$caseUuid] ?? null));

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(fn (string $text, array $parameters = []): string => vsprintf($text, $parameters));

		return new ScoringSheet(settings: $settings, records: new ScoringRecords(settings: $settings), cases: $cases, l10n: $l10n);
	}//end sheet()
}//end class
