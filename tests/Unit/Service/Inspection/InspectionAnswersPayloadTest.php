<?php

/**
 * How a submitted inspection answer is read: which list, which words, which extras.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Inspection
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Inspection;

use OCA\Dossiq\Service\Inspection\InspectionAnswers;
use PHPUnit\Framework\TestCase;

/**
 * The payload edges the request-shape test does not reach.
 *
 * @covers \OCA\Dossiq\Service\Inspection\InspectionAnswers
 * @uses   \OCA\Dossiq\Service\ChecklistService
 */
final class InspectionAnswersPayloadTest extends TestCase {

	private InspectionAnswers $answers;

	/**
	 * A fresh reader.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->answers = new InspectionAnswers();
	}//end setUp()

	/**
	 * The one answer read from a single raw response.
	 *
	 * @param array<string, mixed> $raw The raw response.
	 *
	 * @return array<string, mixed>|null
	 */
	private function one(array $raw): ?array {
		return ($this->answers->fromPayload(payload: ['responses' => [$raw]])[0] ?? null);
	}//end one()

	/**
	 * Only the first list present is read, in the order responses, answers, items.
	 *
	 * @return void
	 */
	public function testOnlyTheFirstListPresentIsRead(): void {
		$payload = [
			'items' => [['itemId' => 'from-items']],
			'answers' => [['itemId' => 'from-answers'], 'not an answer'],
			'responses' => 'not a list',
		];

		$this->assertSame([['itemId' => 'from-answers']], $this->answers->fromPayload(payload: $payload));
		$this->assertSame([], $this->answers->fromPayload(payload: ['responses' => [], 'items' => [['itemId' => 'x']]]));
		$this->assertSame([], $this->answers->fromPayload(payload: []));
	}//end testOnlyTheFirstListPresentIsRead()

	/**
	 * Yes/no words are read case-insensitively and trimmed; other values are kept as given.
	 *
	 * @return void
	 */
	public function testYesNoWordsAreNormalisedAndOthersKept(): void {
		$expected = [
			' PASS ' => 'ja', 'Yes' => 'ja', 'true' => 'ja', 'conform' => 'ja',
			'FAIL' => 'nee', 'no' => 'nee', 'false' => 'nee', 'non_conform' => 'nee',
			'NA' => 'nvt', 'nvt' => 'nvt', 'not_applicable' => 'nvt',
			'Optie B' => 'Optie B',
		];

		foreach ($expected as $given => $value) {
			$this->assertSame($value, $this->one(['itemId' => 'q', 'value' => $given])['value'], $given);
		}

		$this->assertSame(['itemId' => 'q'], $this->one(['itemId' => 'q', 'value' => '']));
		$this->assertSame('nee', $this->one(['itemId' => 'q', 'value' => 'no', 'result' => 'pass'])['value']);
	}//end testYesNoWordsAreNormalisedAndOthersKept()

	/**
	 * A measurement must be numeric; the item id is trimmed and an id of spaces drops the answer.
	 *
	 * @return void
	 */
	public function testMeasurementsAndItemIds(): void {
		$this->assertSame(['itemId' => 'q', 'numericValue' => 12.0], $this->one(['itemId' => ' q ', 'numericValue' => 12]));
		$this->assertSame(['itemId' => 'q'], $this->one(['itemId' => 'q', 'measurement' => '12 mm']));
		$this->assertNull($this->one(['itemId' => '   ', 'value' => 'ja']));
	}//end testMeasurementsAndItemIds()

	/**
	 * A choice and a comment are kept; a comment wins over a remark; GPS is kept as an array only.
	 *
	 * @return void
	 */
	public function testExtrasAreKeptWithTheCommentFirst(): void {
		$answer = $this->one(['itemId' => 'q', 'choice' => 'B', 'comment' => 'eerst', 'remark' => 'later', 'gpsAtAnswer' => ['lat' => 52.1, 'lon' => 5.1]]);

		$this->assertSame(['itemId' => 'q', 'choice' => 'B', 'comment' => 'eerst', 'gpsAtAnswer' => ['lat' => 52.1, 'lon' => 5.1]], $answer);
		$this->assertSame(['itemId' => 'q'], $this->one(['itemId' => 'q', 'choice' => ' ', 'comment' => '', 'gpsAtAnswer' => '52.1,5.1']));
	}//end testExtrasAreKeptWithTheCommentFirst()

	/**
	 * Photo ids are scalar and non-empty, and a single photoRef is added after the list.
	 *
	 * @return void
	 */
	public function testOnlyUsablePhotoIdsAreKept(): void {
		$answer = $this->one(['itemId' => 'q', 'photos' => [7, '', ['nested'], null, 'f-8'], 'photoRef' => 9]);

		$this->assertSame(['7', 'f-8', '9'], $answer['photos']);
		$this->assertArrayNotHasKey('photos', $this->one(['itemId' => 'q', 'photos' => 'f-1', 'photoRef' => '']));
	}//end testOnlyUsablePhotoIdsAreKept()

	/**
	 * The panel word follows the value, unknown values pass through, and a measurement is mirrored.
	 *
	 * @return void
	 */
	public function testThePanelWordsAndMeasurement(): void {
		$this->assertSame(['itemId' => 'q', 'value' => 'nvt', 'result' => 'nvt'], $this->answers->forPanel(answer: ['itemId' => 'q', 'value' => 'nvt']));
		$this->assertSame('Optie B', $this->answers->forPanel(answer: ['value' => 'Optie B'])['result']);
		$this->assertSame('', $this->answers->forPanel(answer: [])['result']);
		$this->assertSame(4.5, $this->answers->forPanel(answer: ['value' => 'ja', 'numericValue' => 4.5])['measurement']);
		$this->assertArrayNotHasKey('measurement', $this->answers->forPanel(answer: ['value' => 'ja']));
	}//end testThePanelWordsAndMeasurement()

	/**
	 * Only answers whose value is nee count as failed; duplicate evidence ids appear once.
	 *
	 * @return void
	 */
	public function testFailuresAndEvidenceAreCountedOnce(): void {
		$answers = [
			['itemId' => 'a', 'value' => 'nee', 'photos' => ['1', '2']],
			['itemId' => 'b', 'value' => 'NEE', 'photos' => ['2']],
			['itemId' => 'c', 'value' => 'nee'],
			['itemId' => 'd'],
		];

		$this->assertSame(2, $this->answers->failedCount(answers: $answers));
		$this->assertSame(['1', '2'], $this->answers->evidence(answers: $answers));
		$this->assertSame(0, $this->answers->failedCount(answers: []));
	}//end testFailuresAndEvidenceAreCountedOnce()
}//end class
