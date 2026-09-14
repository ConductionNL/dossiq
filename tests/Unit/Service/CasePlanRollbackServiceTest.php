<?php

/**
 * The reverse projection: OpenRegister rows back into a `casePlanState` blob.
 *
 * The claim design.md section 4 makes about this direction is that it is
 * TOTAL, and that claim is what these tests hold to. A row carries strictly
 * more than the blob, so nothing the blob recorded may go missing: item state
 * for every item, the milestone record for an achieved milestone, and an event
 * log the engine can read back.
 *
 * The keying is the sharp edge. The blob names items by their definition id,
 * which is what a sentry's `onPart.planItem` still names; keying on the row
 * uuid would produce a blob the engine reads as a case where nothing has
 * happened, because it answers an unknown item id with its initial state
 * rather than an error.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/retire-cmmn-caseplanstate/specs/retire-cmmn-caseplanstate/spec.md#requirement-req-rcmn-001-case-semantics-are-consumed-from-openregister
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Service\CasePlanRollbackService;
use OCA\Dossiq\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Dossiq\Service\CasePlanRollbackService
 * @uses \OCA\Dossiq\Service\CasePlanProjectionService
 * @uses \OCA\Dossiq\Service\SettingsService
 */
final class CasePlanRollbackServiceTest extends TestCase {

	/**
	 * The service under test.
	 *
	 * @var CasePlanRollbackService
	 */
	private CasePlanRollbackService $rollback;

	/**
	 * Build the service over mocked collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->rollback = new CasePlanRollbackService(
			$this->createMock(SettingsService::class),
			$this->createMock(LoggerInterface::class),
		);
	}//end setUp()

	/**
	 * A plan as OpenRegister answers it.
	 *
	 * @return array<string, mixed> The plan.
	 */
	private static function plan(): array {
		return [
			'objectUuid' => 'case-1',
			'items' => [
				['id' => 1, 'uuid' => 'u-intake', 'key' => 'intake', 'type' => 'stage', 'state' => 'completed', 'enteredAt' => '2026-09-01T10:00:00+00:00'],
				['id' => 2, 'uuid' => 'u-controle', 'key' => 'controle', 'type' => 'humanTask', 'state' => 'completed', 'enteredAt' => '2026-09-01T11:00:00+00:00'],
				['id' => 3, 'uuid' => 'u-advies', 'key' => 'extra-advies', 'type' => 'humanTask', 'state' => 'disabled', 'enteredAt' => null],
				['id' => 4, 'uuid' => 'u-besluit', 'key' => 'besluit', 'type' => 'milestone', 'state' => 'completed', 'enteredAt' => '2026-09-02T09:00:00+00:00'],
			],
			'audit' => [
				['id' => 10, 'caseItemId' => 2, 'fromState' => 'active', 'toState' => 'completed', 'created' => '2026-09-01T11:00:00+00:00'],
				['id' => 11, 'caseItemId' => 99, 'fromState' => 'active', 'toState' => 'completed', 'created' => '2026-09-01T12:00:00+00:00'],
			],
		];
	}//end plan()

	/**
	 * Every item's state comes back, keyed by the definition id the engine
	 * and every sentry name it by.
	 *
	 * @return void
	 */
	public function testEveryItemStateComesBackKeyedByDefinitionId(): void {
		$blob = $this->rollback->toBlob(plan: self::plan());

		$this->assertSame(
			[
				'intake' => 'completed',
				'controle' => 'completed',
				'extra-advies' => 'disabled',
				'besluit' => 'completed',
			],
			$blob['planItemStates'],
		);
	}//end testEveryItemStateComesBackKeyedByDefinitionId()

	/**
	 * An achieved milestone comes back as the achievement record the engine
	 * wrote, not merely as a state.
	 *
	 * @return void
	 */
	public function testAnAchievedMilestoneComesBackAsAnAchievementRecord(): void {
		$blob = $this->rollback->toBlob(plan: self::plan());

		$this->assertSame(
			['besluit' => ['achieved' => true, 'achievedAt' => '2026-09-02T09:00:00+00:00']],
			$blob['milestones'],
		);
	}//end testAnAchievedMilestoneComesBackAsAnAchievementRecord()

	/**
	 * A completed HUMAN TASK is not a milestone. The engine only ever recorded
	 * an achievement for a milestone, and a blob claiming otherwise would
	 * report work as an achieved milestone on the case page.
	 *
	 * @return void
	 */
	public function testACompletedTaskIsNotRecordedAsAMilestone(): void {
		$blob = $this->rollback->toBlob(plan: self::plan());

		$this->assertArrayNotHasKey('controle', $blob['milestones']);
		$this->assertArrayNotHasKey('intake', $blob['milestones']);
	}//end testACompletedTaskIsNotRecordedAsAMilestone()

	/**
	 * An unachieved milestone records nothing at all.
	 *
	 * @return void
	 */
	public function testAnUnachievedMilestoneRecordsNothing(): void {
		$plan = self::plan();
		$plan['items'][3]['state'] = 'terminated';

		$this->assertSame([], $this->rollback->toBlob(plan: $plan)['milestones']);
	}//end testAnUnachievedMilestoneRecordsNothing()

	/**
	 * The audit becomes an event log the engine can read, in its own shape.
	 *
	 * @return void
	 */
	public function testTheAuditBecomesTheEnginesEventLog(): void {
		$blob = $this->rollback->toBlob(plan: self::plan());

		$this->assertSame(
			[
				[
					'at' => '2026-09-01T11:00:00+00:00',
					'itemId' => 'controle',
					'itemType' => '',
					'from' => 'active',
					'to' => 'completed',
				],
			],
			$blob['eventLog'],
		);
	}//end testTheAuditBecomesTheEnginesEventLog()

	/**
	 * An audit entry whose item is not in the plan is dropped, never written
	 * with an id naming nothing.
	 *
	 * @return void
	 */
	public function testAnAuditEntryForAnAbsentItemIsDropped(): void {
		$blob = $this->rollback->toBlob(plan: self::plan());

		$this->assertCount(1, $blob['eventLog']);
		$this->assertSame('controle', $blob['eventLog'][0]['itemId']);
	}//end testAnAuditEntryForAnAbsentItemIsDropped()

	/**
	 * The blob always carries all four keys, because the engine merges what it
	 * decodes over an empty shape and a missing key would read as empty anyway;
	 * writing them makes the blob self-describing.
	 *
	 * @return void
	 */
	public function testTheBlobAlwaysCarriesAllFourKeys(): void {
		$this->assertSame(
			array_keys(CasePlanRollbackService::EMPTY_BLOB),
			array_keys($this->rollback->toBlob(plan: [])),
		);
	}//end testTheBlobAlwaysCarriesAllFourKeys()

	/**
	 * Case-file values are carried through untouched when the caller has them.
	 *
	 * @return void
	 */
	public function testCaseFileValuesAreCarriedThrough(): void {
		$blob = $this->rollback->toBlob(plan: self::plan(), caseFile: ['advies' => 'positief']);

		$this->assertSame(['advies' => 'positief'], $blob['caseFile']);
	}//end testCaseFileValuesAreCarriedThrough()

	/**
	 * A row with no `key` is skipped rather than keyed on an empty string,
	 * which would collide every such row onto one entry.
	 *
	 * @return void
	 */
	public function testARowWithoutAKeyIsSkipped(): void {
		$blob = $this->rollback->toBlob(plan: ['items' => [['id' => 1, 'type' => 'stage', 'state' => 'active']]]);

		$this->assertSame([], $blob['planItemStates']);
	}//end testARowWithoutAKeyIsSkipped()

	/**
	 * A preview reports what it would write and writes nothing.
	 *
	 * The two methods exist instead of a `$dryRun` boolean precisely so this
	 * can be asserted: a caller that got the flag the wrong way round would
	 * write a blob onto a case somebody meant only to inspect.
	 *
	 * @return void
	 */
	public function testAPreviewReportsTheCountAndWritesNothing(): void {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getOpenRegisterClass')->willReturn(self::caseLayer());
		// A write would have to go through the object service, and there is
		// none: a preview that tried to write would throw here rather than
		// quietly succeed.
		$settings->method('getObjectService')->willReturn(null);
		$rollback = new CasePlanRollbackService($settings, $this->createMock(LoggerInterface::class));

		$result = $rollback->previewCase(caseId: 'case-1');

		$this->assertFalse($result['written']);
		$this->assertSame('dry_run', $result['reason']);
		$this->assertSame(4, $result['items']);
	}//end testAPreviewReportsTheCountAndWritesNothing()

	/**
	 * A rollback with no storage behind it reports, rather than claiming a
	 * write it did not make.
	 *
	 * @return void
	 */
	public function testARollbackWithoutStorageReportsRatherThanClaimingAWrite(): void {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getOpenRegisterClass')->willReturn(self::caseLayer());
		$settings->method('getObjectService')->willReturn(null);
		$rollback = new CasePlanRollbackService($settings, $this->createMock(LoggerInterface::class));

		$result = $rollback->rollbackCase(caseId: 'case-1');

		$this->assertFalse($result['written']);
		$this->assertSame('storage_unavailable', $result['reason']);
		$this->assertSame(4, $result['items']);
	}//end testARollbackWithoutStorageReportsRatherThanClaimingAWrite()

	/**
	 * A case OpenRegister holds no plan for is reported, never written.
	 *
	 * @return void
	 */
	public function testACaseWithNoPlanInOpenRegisterIsReported(): void {
		$layer = new class {
			/**
			 * Refuse, the way OpenRegister refuses an object with no plan.
			 *
			 * @param string      $objectUuid The anchoring object.
			 * @param string|null $uid        The reading identity.
			 *
			 * @return array<string, mixed> Never; it throws.
			 */
			public function getPlan(string $objectUuid, ?string $uid): array {
				throw new \RuntimeException('no plan for ' . $objectUuid . ' as ' . (string)$uid);
			}
		};

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getOpenRegisterClass')->willReturn($layer);
		$rollback = new CasePlanRollbackService($settings, $this->createMock(LoggerInterface::class));

		$result = $rollback->rollbackCase(caseId: 'case-1');

		$this->assertFalse($result['written']);
		$this->assertSame('no_plan_in_openregister', $result['reason']);
	}//end testACaseWithNoPlanInOpenRegisterIsReported()

	/**
	 * A stand-in for OpenRegister's case layer, answering the fixture plan.
	 *
	 * @return object The stand-in.
	 */
	private static function caseLayer(): object {
		$plan = self::plan();

		return new class($plan) {
			/**
			 * Hold the plan to answer with.
			 *
			 * @param array<string, mixed> $plan The plan.
			 */
			public function __construct(private readonly array $plan) {
			}//end __construct()

			/**
			 * Answer the plan, whoever asks.
			 *
			 * @param string      $objectUuid The anchoring object.
			 * @param string|null $uid        The reading identity.
			 *
			 * @return array<string, mixed> The plan.
			 */
			public function getPlan(string $objectUuid, ?string $uid): array {
				unset($objectUuid, $uid);

				return $this->plan;
			}
		};
	}//end caseLayer()

	/**
	 * An absent case layer is reported, and no blob is written.
	 *
	 * @return void
	 */
	public function testAnAbsentCaseLayerIsReportedAndWritesNothing(): void {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getOpenRegisterClass')->willReturn(null);
		$rollback = new CasePlanRollbackService($settings, $this->createMock(LoggerInterface::class));

		$result = $rollback->rollbackCase(caseId: 'case-1');

		$this->assertFalse($result['written']);
		$this->assertSame('case_layer_unavailable', $result['reason']);
	}//end testAnAbsentCaseLayerIsReportedAndWritesNothing()
}//end class
