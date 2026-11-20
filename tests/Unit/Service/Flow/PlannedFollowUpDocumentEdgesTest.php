<?php

/**
 * Planned follow-up document edge-case unit tests.
 *
 * Pins the answers the document gives for graphs and inputs the form never
 * produces: a date-bounded series, a negative count, a graph that is not a
 * planned follow-up, an unknown recurrence and an anchorless series.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Flow
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/workflow-definition-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Flow;

use DateTimeImmutable;
use OCA\Dossiq\Service\Flow\PlannedFollowUpDocument;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Planned follow-up documents at their edges.
 *
 * @covers \OCA\Dossiq\Service\Flow\PlannedFollowUpDocument
 * @uses   \OCA\Dossiq\Service\Flow\PlannedSeriesCalendar
 *
 * @spec openspec/specs/workflow-definition-engine/spec.md
 */
class PlannedFollowUpDocumentEdgesTest extends TestCase {

	/**
	 * The class under test.
	 *
	 * @var PlannedFollowUpDocument
	 */
	private PlannedFollowUpDocument $document;

	/**
	 * Build the pure document class.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->document = new PlannedFollowUpDocument();
	}//end setUp()

	/**
	 * A recurring series may end on a date alone; the date is stored as given, trimmed.
	 *
	 * @return void
	 */
	public function testEndOfStoresATrimmedEndDate(): void {
		$this->assertSame(
			expected: ['until' => '2027-12-31', 'count' => 0],
			actual: $this->document->endOf(recurrence: 'yearly', until: ' 2027-12-31 ', count: 0)
		);
	}//end testEndOfStoresATrimmedEndDate()

	/**
	 * A recurring series may end after a count alone.
	 *
	 * @return void
	 */
	public function testEndOfStoresACount(): void {
		$this->assertSame(
			expected: ['until' => '', 'count' => 4],
			actual: $this->document->endOf(recurrence: 'monthly', until: '', count: 4)
		);
	}//end testEndOfStoresACount()

	/**
	 * A negative count is refused as an invalid end.
	 *
	 * @return void
	 */
	public function testEndOfRefusesANegativeCount(): void {
		$this->expectException(exception: RuntimeException::class);
		$this->expectExceptionMessage(message: 'invalid_end');

		$this->document->endOf(recurrence: 'monthly', until: '', count: -1);
	}//end testEndOfRefusesANegativeCount()

	/**
	 * A single follow-up drops any end asked for.
	 *
	 * @return void
	 */
	public function testEndOfDropsTheEndOfASingleFollowUp(): void {
		$this->assertSame(
			expected: ['until' => '', 'count' => 0],
			actual: $this->document->endOf(recurrence: 'none', until: '2027-01-01', count: 3)
		);
	}//end testEndOfDropsTheEndOfASingleFollowUp()

	/**
	 * A graph without a create-sub-case node is not a planned follow-up.
	 *
	 * @return void
	 */
	public function testMarkerOfIsNullWithoutACreateNode(): void {
		$nodes = [
			'not a node',
			['type' => 'openregister.trigger-schedule', 'config' => ['cron' => '0 6 1 1 *']],
		];

		$this->assertNull(actual: $this->document->markerOf(nodes: $nodes));
	}//end testMarkerOfIsNullWithoutACreateNode()

	/**
	 * A create node that names no related case is not a planned follow-up either.
	 *
	 * @return void
	 */
	public function testMarkerOfIsNullWithoutARelatedCase(): void {
		$nodes = [
			['type' => 'dossiq.createSubCase', 'config' => ['relatedCases' => ['  '], 'caseType' => 't']],
		];

		$this->assertNull(actual: $this->document->markerOf(nodes: $nodes));
	}//end testMarkerOfIsNullWithoutARelatedCase()

	/**
	 * A marker of a single follow-up reads the case, type, title and its anchor date.
	 *
	 * @return void
	 */
	public function testMarkerOfReadsTheGraph(): void {
		$nodes = [
			['type' => 'openregister.trigger-schedule', 'config' => ['cron' => '0 6 15 10 *']],
			[
				'type' => 'dossiq.createSubCase',
				'config' => [
					'relatedCases' => [' case-9 '],
					'caseType' => 'type-3',
					'title' => 'Controle',
					'series' => ['recurrence' => 'none', 'anchor' => '2026-10-15'],
				],
			],
		];

		$this->assertSame(
			expected: [
				'case' => 'case-9',
				'caseType' => 'type-3',
				'title' => 'Controle',
				'date' => '2026-10-15',
				'recurrence' => 'none',
				'until' => '',
				'count' => 0,
			],
			actual: $this->document->markerOf(nodes: $nodes, from: new DateTimeImmutable('2026-01-01'))
		);
	}//end testMarkerOfReadsTheGraph()

	/**
	 * An unknown recurrence token reads as a single follow-up, and missing fields as empty.
	 *
	 * @return void
	 */
	public function testSeriesOfNormalisesAnUnknownRecurrence(): void {
		$nodes = [
			['type' => 'dossiq.createSubCase', 'config' => ['series' => ['recurrence' => 'fortnightly', 'count' => '2']]],
		];

		$this->assertSame(
			expected: ['recurrence' => 'none', 'anchor' => '', 'until' => '', 'count' => 2],
			actual: $this->document->seriesOf(nodes: $nodes)
		);
	}//end testSeriesOfNormalisesAnUnknownRecurrence()

	/**
	 * A graph with no create node has the empty single series.
	 *
	 * @return void
	 */
	public function testSeriesOfWithoutACreateNode(): void {
		$this->assertSame(
			expected: ['recurrence' => 'none', 'anchor' => '', 'until' => '', 'count' => 0],
			actual: $this->document->seriesOf(nodes: [])
		);
	}//end testSeriesOfWithoutACreateNode()

	/**
	 * An anchorless series with an end date is spent only once today is past that date.
	 *
	 * @return void
	 */
	public function testAnAnchorlessSeriesIsSpentPastItsEndDate(): void {
		$document = [
			'nodes' => [
				['type' => 'dossiq.createSubCase', 'config' => ['series' => ['recurrence' => 'monthly', 'until' => '2027-03-31']]],
			],
		];

		$this->assertFalse(condition: $this->document->isSpent(document: $document, firedCount: 5, today: new DateTimeImmutable(datetime: '2027-03-31')));
		$this->assertTrue(condition: $this->document->isSpent(document: $document, firedCount: 5, today: new DateTimeImmutable(datetime: '2027-04-01')));
	}//end testAnAnchorlessSeriesIsSpentPastItsEndDate()

	/**
	 * A recurring series with neither an end date nor a count is never spent.
	 *
	 * @return void
	 */
	public function testAnOpenSeriesIsNeverSpent(): void {
		$document = [
			'nodes' => [
				['type' => 'dossiq.createSubCase', 'config' => ['series' => ['recurrence' => 'yearly', 'anchor' => '2026-01-10']]],
			],
		];

		$this->assertFalse(condition: $this->document->isSpent(document: $document, firedCount: 99, today: new DateTimeImmutable(datetime: '2040-01-01')));
	}//end testAnOpenSeriesIsNeverSpent()
}//end class
