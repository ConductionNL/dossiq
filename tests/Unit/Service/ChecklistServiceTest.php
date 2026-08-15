<?php

/**
 * ChecklistService (REQ-003) Unit Tests
 *
 * @category Tests
 * @package  OCA\Procest\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/inspection-checklists/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Service;

use OCA\Procest\Service\ChecklistService;
use OCA\Procest\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Unit tests for the pure checklist arithmetic of REQ-003.
 *
 * ChecklistService composes ChecklistPayloadReader directly rather than taking
 * it by injection (REQ-003 requires the service to be constructible with no
 * dependencies), so exercising the service necessarily executes the reader.
 * The reader is therefore declared below so PHPUnit's strict coverage metadata
 * does not report every case here as risky. It is covered in its own right by
 * tests/Unit/Service/Support/ChecklistPayloadReaderTest.php.
 *
 * ⚠️ Never write a literal coverage-annotation token in this prose. PHPUnit
 * scans the whole docblock and matches them MID-LINE, not just at the start of
 * one. The sentence above originally used the annotation word followed by
 * "suite"; PHPUnit registered a coverage target literally named `suite` and
 * failed the run with an "is invalid" warning on all 16 cases, with every test
 * still passing. Same family as the fleet's gate-19/gate-26 prose-parsing
 * defect — an explanatory comment is not inert.
 *
 * @covers \OCA\Procest\Service\ChecklistService
 * @uses   \OCA\Procest\Service\Support\ChecklistPayloadReader
 */
class ChecklistServiceTest extends TestCase {

	/**
	 * The subject under test.
	 *
	 * @var ChecklistService
	 */
	private ChecklistService $service;

	/**
	 * Set up the subject.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		// OCA\Procest\Service\ChecklistService takes NO constructor arguments.
		// The three that were here belong to OCA\Procest\Service\Inspection\
		// ChecklistService — a different class with the same file name, which an
		// earlier fixer keyed constructors by.
		$this->service = new ChecklistService();
	}//end setUp()

	/**
	 * A three-item run in the sectioned template shape.
	 *
	 * @param array<int, array<string, mixed>> $responses Responses to seed.
	 *
	 * @return array<string, mixed> The run payload.
	 */
	private function makeRun(array $responses = []): array {
		return [
			'templateSnapshot' => [
				'sections' => [
					[
						'order' => 1,
						'items' => [
							[
								'id' => 'fundering',
								'label' => 'Fundering conform tekening',
								'responseType' => 'ja_nee_nvt',
								'required' => true,
								'fotoRequired' => ChecklistService::PHOTO_ON_FAIL,
							],
							[
								'id' => 'wapening',
								'label' => 'Wapening',
								'responseType' => 'ja_nee_nvt',
								'required' => true,
								'fotoRequired' => ChecklistService::PHOTO_NEVER,
							],
						],
					],
					[
						'order' => 2,
						'items' => [
							[
								'id' => 'maatvoering',
								'label' => 'Maatvoering',
								'responseType' => 'meting',
								'required' => false,
								'fotoRequired' => ChecklistService::PHOTO_NEVER,
							],
						],
					],
				],
			],
			'responses' => $responses,
		];
	}//end makeRun()

	/**
	 * An empty run is 0 of 3.
	 *
	 * @return void
	 */
	public function testProgressOnAnUntouchedRun(): void {
		$this->assertSame(
			['completed' => 0, 'total' => 3, 'percent' => 0],
			$this->service->getProgress($this->makeRun())
		);
	}//end testProgressOnAnUntouchedRun()

	/**
	 * Progress counts answered items and rounds the percentage.
	 *
	 * @return void
	 */
	public function testProgressCountsAnsweredItems(): void {
		$progress = $this->service->getProgress(
			$this->makeRun([['itemId' => 'fundering', 'value' => 'ja']])
		);

		$this->assertSame(1, $progress['completed']);
		$this->assertSame(3, $progress['total']);
		$this->assertSame(33, $progress['percent']);
	}//end testProgressCountsAnsweredItems()

	/**
	 * A response row with no usable value is NOT progress.
	 *
	 * Without this, a run of blank rows would report itself complete.
	 *
	 * @return void
	 */
	public function testABlankResponseDoesNotCountAsAnswered(): void {
		$progress = $this->service->getProgress(
			$this->makeRun([['itemId' => 'fundering', 'value' => '']])
		);

		$this->assertSame(0, $progress['completed']);
	}//end testABlankResponseDoesNotCountAsAnswered()

	/**
	 * A response for an item the template does not contain is ignored.
	 *
	 * @return void
	 */
	public function testAnOrphanResponseDoesNotInflateProgress(): void {
		$progress = $this->service->getProgress(
			$this->makeRun([['itemId' => 'not-in-this-template', 'value' => 'ja']])
		);

		$this->assertSame(0, $progress['completed']);
		$this->assertSame(3, $progress['total']);
	}//end testAnOrphanResponseDoesNotInflateProgress()

	/**
	 * An empty checklist is complete, not a division by zero.
	 *
	 * @return void
	 */
	public function testAnEmptyChecklistIsHundredPercent(): void {
		$this->assertSame(
			['completed' => 0, 'total' => 0, 'percent' => 100],
			$this->service->getProgress([])
		);
	}//end testAnEmptyChecklistIsHundredPercent()

	/**
	 * The conformity summary always partitions the item total.
	 *
	 * @return void
	 */
	public function testConformitySummaryPartitionsEveryItem(): void {
		$summary = $this->service->getConformitySummary(
			$this->makeRun(
				[
					['itemId' => 'fundering', 'value' => 'nee', 'photos' => ['f1']],
					['itemId' => 'wapening', 'value' => 'nvt'],
				]
			)
		);

		$this->assertSame(
			['conforming' => 0, 'nonConforming' => 1, 'na' => 1, 'pending' => 1],
			$summary
		);
		$this->assertSame(3, array_sum($summary));
	}//end testConformitySummaryPartitionsEveryItem()

	/**
	 * A non-ja_nee_nvt item conforms by virtue of being answered.
	 *
	 * @return void
	 */
	public function testAMeasurementItemConformsWhenAnswered(): void {
		$summary = $this->service->getConformitySummary(
			$this->makeRun([['itemId' => 'maatvoering', 'numericValue' => 42]])
		);

		$this->assertSame(1, $summary['conforming']);
		$this->assertSame(2, $summary['pending']);
	}//end testAMeasurementItemConformsWhenAnswered()

	/**
	 * An untouched run reports both required items outstanding.
	 *
	 * @return void
	 */
	public function testValidateCompletionReportsEveryMissingRequiredItem(): void {
		$violations = $this->service->validateCompletion($this->makeRun());

		$this->assertCount(2, $violations, 'both required items, reported together');
		$this->assertStringContainsString('Fundering conform tekening', $violations[0]);
		$this->assertStringContainsString('Wapening', $violations[1]);
	}//end testValidateCompletionReportsEveryMissingRequiredItem()

	/**
	 * An optional item left blank is not a violation.
	 *
	 * Negative control for the test above: a validator that flagged every
	 * unanswered item would also produce two violations there.
	 *
	 * @return void
	 */
	public function testAnOptionalItemMayBeLeftBlank(): void {
		$violations = $this->service->validateCompletion(
			$this->makeRun(
				[
					['itemId' => 'fundering', 'value' => 'ja'],
					['itemId' => 'wapening', 'value' => 'ja'],
				]
			)
		);

		$this->assertSame([], $violations, 'maatvoering is optional');
	}//end testAnOptionalItemMayBeLeftBlank()

	/**
	 * `bij_nee` demands a photo only when the answer is 'nee'.
	 *
	 * @return void
	 */
	public function testPhotoOnFailGateOnlyBitesOnNee(): void {
		$ok = $this->service->validateCompletion(
			$this->makeRun(
				[
					['itemId' => 'fundering', 'value' => 'ja'],
					['itemId' => 'wapening', 'value' => 'ja'],
				]
			)
		);
		$this->assertSame([], $ok, "'ja' needs no photo under bij_nee");

		$bad = $this->service->validateCompletion(
			$this->makeRun(
				[
					['itemId' => 'fundering', 'value' => 'nee'],
					['itemId' => 'wapening', 'value' => 'ja'],
				]
			)
		);
		$this->assertCount(1, $bad);
		$this->assertStringContainsString('photo is required', $bad[0]);
	}//end testPhotoOnFailGateOnlyBitesOnNee()

	/**
	 * 🔴 REQ-001's scenario: a missing mandatory photo REJECTS the answer and
	 * leaves the item incomplete, rather than accepting it and failing later.
	 *
	 * @return void
	 */
	public function testCompleteItemRefusesAnAnswerMissingItsMandatoryPhoto(): void {
		$before = $this->makeRun();

		try {
			$this->service->completeItem($before, 'fundering', ['value' => 'nee']);
			$this->fail('expected the missing photo to be refused');
		} catch (RuntimeException $e) {
			$this->assertStringContainsString('photo is required', $e->getMessage());
		}

		// The item must remain incomplete.
		$this->assertSame(0, $this->service->getProgress($before)['completed']);
	}//end testCompleteItemRefusesAnAnswerMissingItsMandatoryPhoto()

	/**
	 * completeItem is pure — the argument is not mutated.
	 *
	 * @return void
	 */
	public function testCompleteItemDoesNotMutateItsArgument(): void {
		$before = $this->makeRun();
		$after = $this->service->completeItem($before, 'wapening', ['value' => 'ja']);

		$this->assertSame(0, $this->service->getProgress($before)['completed']);
		$this->assertSame(1, $this->service->getProgress($after)['completed']);
	}//end testCompleteItemDoesNotMutateItsArgument()

	/**
	 * Re-answering an item replaces its response instead of appending one.
	 *
	 * @return void
	 */
	public function testCompleteItemReplacesAnEarlierAnswer(): void {
		$first = $this->service->completeItem($this->makeRun(), 'wapening', ['value' => 'ja']);
		$second = $this->service->completeItem($first, 'wapening', ['value' => 'nee']);

		$this->assertCount(1, $second['responses']);
		$this->assertSame('nee', $second['responses'][0]['value']);
		$this->assertSame(1, $this->service->getConformitySummary($second)['nonConforming']);
	}//end testCompleteItemReplacesAnEarlierAnswer()

	/**
	 * An unknown item id is refused.
	 *
	 * @return void
	 */
	public function testCompleteItemRejectsAnUnknownItem(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessageMatches('/Unknown checklist item/');

		$this->service->completeItem($this->makeRun(), 'no-such-item', ['value' => 'ja']);
	}//end testCompleteItemRejectsAnUnknownItem()

	/**
	 * The flat `items` shape is understood as well as the sectioned one.
	 *
	 * @return void
	 */
	public function testTheFlatItemsShapeIsSupported(): void {
		$flat = [
			'items' => [
				['id' => 'a', 'label' => 'A', 'responseType' => 'tekst', 'required' => true],
				['id' => 'b', 'label' => 'B', 'responseType' => 'tekst', 'required' => false],
			],
			'responses' => [['itemId' => 'a', 'value' => 'done']],
		];

		$this->assertSame(
			['completed' => 1, 'total' => 2, 'percent' => 50],
			$this->service->getProgress($flat)
		);
		$this->assertSame([], $this->service->validateCompletion($flat));
	}//end testTheFlatItemsShapeIsSupported()

	/**
	 * An item without an `id` falls back to `order`, matching
	 * Inspection\ChecklistService::indexItemsBySnapshot().
	 *
	 * @return void
	 */
	public function testItemsWithoutAnIdAreKeyedByOrder(): void {
		$payload = [
			'items' => [['order' => 7, 'label' => 'Seventh', 'responseType' => 'tekst', 'required' => true]],
			'responses' => [['itemId' => '7', 'value' => 'ok']],
		];

		$this->assertSame(1, $this->service->getProgress($payload)['completed']);
		$this->assertSame([], $this->service->validateCompletion($payload));
	}//end testItemsWithoutAnIdAreKeyedByOrder()
}//end class
