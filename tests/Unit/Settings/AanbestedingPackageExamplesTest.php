<?php

/**
 * The aanbesteding package's example is one tender in evaluation with two lots and three bids
 *
 * The lots are sub-cases of the tender, every bid is registered on a lot's
 * case, every bid has an evaluation, and the tender's timeline carries the
 * question deadline before the closing date, which falls before the bids
 * would be refused as late.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/tenders-run-on-the-tender-case-type/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

class AanbestedingPackageExamplesTest extends TestCase {

	/**
	 * The package directory.
	 */
	private const DIR = __DIR__ . '/../../../lib/Settings/packages/aanbesteding';

	/**
	 * The status Beoordeling of the tender case type.
	 */
	private const EVALUATION = 'f7d821cb-59f6-52b4-bc18-772734ad5336';

	/**
	 * One file of the package, decoded.
	 *
	 * @param string $path The path in the package.
	 *
	 * @return array<string, mixed>
	 */
	private static function json(string $path): array {
		return json_decode((string)file_get_contents(self::DIR . '/' . $path), true, 512, JSON_THROW_ON_ERROR);
	}//end json()

	/**
	 * The relative day a `{{date:N}}` token names.
	 *
	 * @param string $token The token.
	 *
	 * @return int The day offset.
	 */
	private static function offset(string $token): int {
		self::assertMatchesRegularExpression('/^\{\{date(time)?:(-?\d+)( \d\d:\d\d)?\}\}$/', $token);
		preg_match('/:(-?\d+)/', $token, $match);

		return (int)$match[1];
	}//end offset()

	/**
	 * The tender case, the first row of the case example.
	 *
	 * @return array<string, mixed>
	 */
	private static function tender(): array {
		return self::json('examples/20-case.json')['rows'][0];
	}//end tender()

	/**
	 * Two lots, both sub-cases of the tender, of the tender case type, in evaluation.
	 *
	 * @return void
	 */
	public function testTheTwoLotsAreSubCasesOfTheTenderInEvaluation(): void {
		$tender = self::tender();
		$lots   = self::json('examples/25-lots.json');

		$this->assertSame('case_schema', $lots['schema']);
		$this->assertCount(2, $lots['rows']);
		foreach ($lots['rows'] as $lot) {
			$this->assertSame($tender['id'], $lot['parentCase']);
			$this->assertSame($tender['caseType'], $lot['caseType']);
			$this->assertSame(self::EVALUATION, $lot['status']);
			$this->assertSame(self::EVALUATION, $tender['status']);
		}

		$this->assertNotSame($lots['rows'][0]['id'], $lots['rows'][1]['id']);
	}//end testTheTwoLotsAreSubCasesOfTheTenderInEvaluation()

	/**
	 * Three bids, each on a lot's own case, from known suppliers, both lots bid on.
	 *
	 * @return void
	 */
	public function testThreeBidsAreRegisteredOnTheLotsCases(): void {
		$tender    = self::tender();
		$lotIds    = array_column(self::json('examples/25-lots.json')['rows'], 'id');
		$suppliers = array_column(self::json('examples/30-suppliers.json')['rows'], 'id');
		$bids      = self::json('examples/50-tender-bids.json');

		$this->assertSame(['app' => 'dossiq', 'register' => 'dossiq', 'slug' => 'bid'], $bids['schema']);
		$this->assertCount(3, $bids['rows']);
		foreach ($bids['rows'] as $bid) {
			$this->assertSame($tender['id'], $bid['tender']);
			$this->assertContains($bid['lot'], $lotIds);
			$this->assertSame($bid['lot'], $bid['case'], 'a lot bid attaches to the lot case');
			$this->assertContains($bid['supplier'], $suppliers);
			$this->assertSame('admissible', $bid['state']);
		}

		$this->assertSame($lotIds, array_values(array_unique(array_column($bids['rows'], 'lot'))));
	}//end testThreeBidsAreRegisteredOnTheLotsCases()

	/**
	 * The bids came in by the closing date, and the questions closed before it.
	 *
	 * @return void
	 */
	public function testTheTimelineRunsQuestionsThenClosingThenBids(): void {
		$values = array_column(self::tender()['properties'], 'value', 'name');
		$questions = self::offset(token: $values['vragenTermijnEinde']);
		$closing   = self::offset(token: $values['sluiting']);

		$this->assertLessThan($closing, $questions);
		foreach (self::json('examples/50-tender-bids.json')['rows'] as $bid) {
			$this->assertLessThanOrEqual($closing, self::offset(token: $bid['submittedAt']));
		}

		$answer = self::json('examples/45-tender-questions.json')['rows'][0];
		$this->assertSame('published', $answer['state']);
		$this->assertLessThanOrEqual($questions, self::offset(token: $answer['submittedAt']));
	}//end testTheTimelineRunsQuestionsThenClosingThenBids()

	/**
	 * The properties the example answers are the ones the case type declares, by id.
	 *
	 * @return void
	 */
	public function testTheTimelineAnswersTheDeclaredProperties(): void {
		$declared = array_column(self::json('case-type.json')['propertyDefinitions'], 'id', 'name');

		$this->assertArrayHasKey('vragenTermijnEinde', $declared);
		foreach (self::tender()['properties'] as $entry) {
			$this->assertSame($declared[$entry['name']], $entry['propertyDefinition']);
		}
	}//end testTheTimelineAnswersTheDeclaredProperties()

	/**
	 * Every bid has one evaluation whose total is the sum of its criterion scores.
	 *
	 * @return void
	 */
	public function testEveryBidHasOneEvaluationThatAddsUp(): void {
		$bidIds      = array_column(self::json('examples/50-tender-bids.json')['rows'], 'id');
		$evaluations = self::json('examples/60-evaluations.json');

		$this->assertSame(['app' => 'dossiq', 'register' => 'dossiq', 'slug' => 'evaluation'], $evaluations['schema']);
		$this->assertSame($bidIds, array_column($evaluations['rows'], 'bid'));
		foreach ($evaluations['rows'] as $evaluation) {
			$this->assertEqualsWithDelta(array_sum($evaluation['criterionScores']), $evaluation['weightedTotal'], 0.001);
		}
	}//end testEveryBidHasOneEvaluationThatAddsUp()
}//end class
