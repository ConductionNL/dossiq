<?php

/**
 * Unit tests for CasePlanReview.
 *
 * 🔴 THE PLAN THAT IS NEVER REVIEWED IS THE PLAN THAT HARMS. A household whose
 * situation moved on six months ago is still being worked to a plan describing
 * last winter, and nothing said so because there was nothing to say it with.
 *
 * Two properties, and they fail in opposite directions. A plan whose review
 * date has passed is DUE, which is the whole point. A plan that has never been
 * given a review date is NOT due, which matters just as much: reporting the two
 * as one would put every plan written before this change into the overdue list
 * on the day it ships, and a list that is wrong on its first day is a list
 * nobody reads on its second.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/the-social-domain-plan-and-its-grounds/specs/dossiq-sociaal-domein-jeugdwet/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\SociaalDomein;

use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\SociaalDomein\CasePlanReview;
use OCA\Dossiq\Service\SociaalDomein\SociaalDomeinStore;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Unit tests for CasePlanReview.
 *
 * @covers \OCA\Dossiq\Service\SociaalDomein\CasePlanReview
 *
 * @uses \OCA\Dossiq\Service\SociaalDomein\SociaalDomeinStore
 * @uses \OCA\Dossiq\Exception\RefusedException
 * @uses \OCA\Dossiq\Service\SettingsService
 */
class CasePlanReviewTest extends TestCase {

	/**
	 * The in-memory store.
	 *
	 * @var FakeSociaalDomeinObjects
	 */
	private FakeSociaalDomeinObjects $objects;

	/**
	 * The service under test.
	 *
	 * @var CasePlanReview
	 */
	private CasePlanReview $review;

	/**
	 * One plan, due for review last month.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->objects = new FakeSociaalDomeinObjects();
		$this->objects->seed(
			'gezinsplan',
			[
				'id' => 'plan-1',
				'caseId' => 'case-1',
				'preparedBy' => 'anna',
				'reviewDate' => '2026-05-15',
			]
		);

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->objects);

		$this->review = new CasePlanReview(
			store: new SociaalDomeinStore(settingsService: $settings, logger: new NullLogger())
		);
	}//end setUp()

	/**
	 * 🔴 A plan whose review date has passed says it is stale.
	 *
	 * @return void
	 */
	public function testAPlanPastItsReviewDateIsDue(): void {
		$plan = $this->objects->rowsOf('gezinsplan')[0];

		self::assertTrue($this->review->isDue(plan: $plan, today: '2026-06-01'));
		self::assertFalse(
			$this->review->isDue(plan: $plan, today: '2026-05-01'),
			'a plan whose review date is still ahead is not due'
		);
	}//end testAPlanPastItsReviewDateIsDue()

	/**
	 * 🔴 A plan that was never given a review date is not due.
	 *
	 * Otherwise every plan written before this change is overdue on day one.
	 *
	 * @return void
	 */
	public function testAPlanWithNoReviewDateIsNotDue(): void {
		self::assertFalse(
			$this->review->isDue(plan: ['id' => 'plan-2'], today: '2026-06-01')
		);
	}//end testAPlanWithNoReviewDateIsNotDue()

	/**
	 * 🔴 A review records the reviewer, the date and WHAT CHANGED.
	 *
	 * @return void
	 */
	public function testAReviewRecordsBothChangesTheReviewerAndTheDate(): void {
		$plan = $this->review->record(
			planId: 'plan-1',
			reviewedBy: 'anna',
			changes: [
				'Doel "Sem gaat weer naar school" afgesloten als behaald',
				'Interventie "Weerbaarheidstraining" toegevoegd',
			],
			nextReviewDate: '2026-12-01',
			onDate: '2026-06-01',
		);

		self::assertCount(1, $plan['reviews']);
		self::assertSame('anna', $plan['reviews'][0]['reviewedBy']);
		self::assertSame('2026-06-01', $plan['reviews'][0]['reviewDate']);
		self::assertCount(2, $plan['reviews'][0]['changes']);
		self::assertStringContainsString('Weerbaarheidstraining', $plan['reviews'][0]['changes'][1]);

		// The new review date moved, so the plan is no longer stale.
		self::assertSame('2026-12-01', $plan['reviewDate']);
		self::assertFalse($this->review->isDue(plan: $plan, today: '2026-06-02'));
	}//end testAReviewRecordsBothChangesTheReviewerAndTheDate()

	/**
	 * A second review appends rather than replacing the first.
	 *
	 * @return void
	 */
	public function testASecondReviewIsAppended(): void {
		$this->review->record(
			planId: 'plan-1',
			reviewedBy: 'anna',
			changes: ['Eerste herziening'],
			onDate: '2026-06-01',
		);
		$plan = $this->review->record(
			planId: 'plan-1',
			reviewedBy: 'bram',
			changes: ['Tweede herziening'],
			onDate: '2026-09-01',
		);

		self::assertCount(2, $plan['reviews']);
		self::assertSame(['anna', 'bram'], array_column($plan['reviews'], 'reviewedBy'));
	}//end testASecondReviewIsAppended()

	/**
	 * 🔴 A review that says only that it happened is refused.
	 *
	 * "Reviewed on 3 March by A. Jansen" is a tick. The changes are the only
	 * part a later reader, or the household, can check the plan against.
	 *
	 * @return void
	 */
	public function testAReviewWithNothingChangedIsRefused(): void {
		try {
			$this->review->record(planId: 'plan-1', reviewedBy: 'anna', changes: ['   ', '']);
			self::fail('A review recording no change must be refused.');
		} catch (RefusedException $e) {
			self::assertSame('review-needs-what-changed', $e->getRule());
		}

		// The plan is untouched: no empty review is left behind.
		self::assertArrayNotHasKey('reviews', $this->objects->rowsOf('gezinsplan')[0]);
	}//end testAReviewWithNothingChangedIsRefused()

	/**
	 * A review of a plan that is not there is refused, not invented.
	 *
	 * @return void
	 */
	public function testAReviewOfAMissingPlanIsRefused(): void {
		$this->expectException(RefusedException::class);
		$this->expectExceptionMessage('plan_not_found');

		$this->review->record(planId: 'plan-404', reviewedBy: 'anna', changes: ['iets']);
	}//end testAReviewOfAMissingPlanIsRefused()
}//end class
