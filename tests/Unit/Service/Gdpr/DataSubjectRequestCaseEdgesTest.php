<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * DataSubjectRequestCase edges: an unreadable case, an export never asked, a complete run, and the preview record.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Gdpr
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Gdpr;

use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\Gdpr\DataSubjectRequestCase;
use OCA\Dossiq\Service\Gdpr\PlatformDataSubjectRights;
use OCA\Dossiq\Service\Timeline\CaseTimeline;
use OCA\Dossiq\Service\Timeline\TimelineKinds;
use OCA\Dossiq\Service\Transitions\CaseStatusStore;
use OCA\Dossiq\Service\Transitions\FourEyesRule;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Paths the main suite does not reach.
 *
 * @covers \OCA\Dossiq\Service\Gdpr\DataSubjectRequestCase
 * @uses   \OCA\Dossiq\Exception\RefusedException
 * @uses   \OCA\Dossiq\Service\Transitions\FourEyesRule
 */
class DataSubjectRequestCaseEdgesTest extends TestCase {

	private PlatformDataSubjectRights&MockObject $platform;
	private CaseStatusStore&MockObject $cases;
	private CaseTimeline&MockObject $timeline;
	private DataSubjectRequestCase $requests;

	/**
	 * Recorded timeline entries.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $recorded = [];

	/**
	 * @return void
	 */
	protected function setUp(): void {
		$this->platform = $this->createMock(PlatformDataSubjectRights::class);
		$this->cases = $this->createMock(CaseStatusStore::class);
		$this->timeline = $this->createMock(CaseTimeline::class);
		$this->timeline->method('record')->willReturnCallback(
			function (string $caseId, string $kind, string $message, array $fields = []): string {
				$this->recorded[] = ['caseId' => $caseId, 'kind' => $kind, 'message' => $message, 'fields' => $fields];
				return 't1';
			}
		);
		$this->requests = new DataSubjectRequestCase(platform: $this->platform, cases: $this->cases, fourEyes: new FourEyesRule(), timeline: $this->timeline);
	}//end setUp()

	/**
	 * A case that cannot be read refuses every act as indeterminate, touching nothing.
	 *
	 * @return void
	 */
	public function testAnUnreadableCaseRefusesEveryAct(): void {
		$this->cases->method('loadCase')->willReturnOnConsecutiveCalls(null, [], null, []);
		$this->cases->expects($this->never())->method('saveCase');
		$this->platform->expects($this->never())->method($this->anything());

		foreach (['preview', 'run', 'requestExport', 'exportState'] as $act) {
			try {
				$this->requests->{$act}(caseId: 'c1');
				$this->fail($act.' should refuse');
			} catch (RefusedException $e) {
				$this->assertSame('case-unreadable', $e->getRule(), $act);
				$this->assertSame(RefusedException::STATUS_INDETERMINATE, $e->getStatus(), $act);
			}
		}
	}//end testAnUnreadableCaseRefusesEveryAct()

	/**
	 * With no export asked for, the state says so without asking the platform.
	 *
	 * @return void
	 */
	public function testAnExportNeverAskedForIsNotLookedUp(): void {
		$this->cases->method('loadCase')->willReturn(['id' => 'c1', 'subjectExportId' => '  ']);
		$this->platform->expects($this->never())->method('export');

		$this->assertSame(['exportId' => '', 'downloadable' => false, 'expiresAt' => '', 'expired' => false], $this->requests->exportState(caseId: 'c1'));
	}//end testAnExportNeverAskedForIsNotLookedUp()

	/**
	 * An export the platform no longer has reads as expired and leaves the case alone.
	 *
	 * @return void
	 */
	public function testAnExportThePlatformLostReadsExpired(): void {
		$this->cases->method('loadCase')->willReturn(['id' => 'c1', 'subjectExportId' => 'e1']);
		$this->platform->method('export')->with('e1')->willReturn([]);
		$this->cases->expects($this->never())->method('saveCase');

		$this->assertSame(['exportId' => 'e1', 'downloadable' => false, 'expiresAt' => '', 'expired' => true], $this->requests->exportState(caseId: 'c1'));
	}//end testAnExportThePlatformLostReadsExpired()

	/**
	 * A complete run says so on the timeline and counts nothing left behind.
	 *
	 * @return void
	 */
	public function testACompleteRunSaysSo(): void {
		$this->cases->method('loadCase')->willReturn(['id' => 'c1', 'erasurePreviewId' => ' p1 ']);
		$this->cases->method('findStatusRecords')->willReturn([
			['transitionLabel' => DataSubjectRequestCase::ACT_PREPARE, 'actor' => 'anna', 'createdAt' => '2026-09-16T10:00:00+00:00'],
			['transitionLabel' => DataSubjectRequestCase::ACT_APPROVE, 'actor' => 'bram', 'createdAt' => '2026-09-17T10:00:00+00:00'],
		]);
		$this->platform->expects($this->once())->method('approvePreview')->with('p1');
		$this->platform->expects($this->once())->method('runErasure')->with('p1')->willReturn(['destroyed' => ['o1', 'o2', 'o3'], 'pseudonymised' => [], 'complete' => true]);
		$saved = [];
		$this->cases->method('saveCase')->willReturnCallback(function (array $case) use (&$saved): array {
			$saved = $case;
			return $case;
		});

		$this->requests->run(caseId: 'c1');

		$this->assertSame('2026-09-17T10:00:00+00:00', $saved['erasureApprovedAt']);
		$this->assertCount(1, $this->recorded);
		$this->assertSame(TimelineKinds::DATA_SUBJECT_REQUEST, $this->recorded[0]['kind']);
		$this->assertSame('The erasure ran: 3 destroyed, 0 pseudonymised. The platform reports it complete.', $this->recorded[0]['message']);
		$this->assertSame(['act' => 'run', 'previewId' => 'p1', 'withheld' => 0, 'complete' => true], $this->recorded[0]['fields']);
	}//end testACompleteRunSaysSo()

	/**
	 * The preview sums each bucket's counts into the timeline sentence and fields.
	 *
	 * @return void
	 */
	public function testThePreviewRecordSumsTheBuckets(): void {
		$this->cases->method('loadCase')->willReturn(['id' => 'c1', 'dataSubjectRequestType' => ' verwijdering ', 'dataSubject' => 's1', 'dataSubjectType' => '  ']);
		$this->cases->method('saveCase')->willReturnArgument(0);
		$this->platform->expects($this->once())->method('previewErasure')
			->with('s1', null, PlatformDataSubjectRights::MODE_PSEUDONYMISE, 'c1')
			->willReturn([
				'uuid' => 'p1',
				'report' => [
					'counts' => ['erasable' => ['zaak' => 2, 'document' => 3], 'pseudonymised' => ['contact' => 1], 'protected' => ['archief' => 4]],
					'protected' => [['name' => 'a'], ['name' => 'b']],
				],
			]);

		$this->requests->preview(caseId: 'c1');

		$this->assertSame('The erasure preview was taken: 5 erasable, 1 to pseudonymise, 4 protected.', $this->recorded[0]['message']);
		$this->assertSame(['act' => 'preview', 'previewId' => 'p1', 'erasable' => 5, 'pseudonymised' => 1, 'protected' => 2], $this->recorded[0]['fields']);
	}//end testThePreviewRecordSumsTheBuckets()

	/**
	 * A case with no data subject is refused as unprocessable before the platform is asked.
	 *
	 * @return void
	 */
	public function testAnExportWithoutASubjectIsRefused(): void {
		$this->cases->method('loadCase')->willReturn(['id' => 'c1', 'dataSubjectRequestType' => 'inzage', 'dataSubject' => ' ']);
		$this->platform->expects($this->never())->method('requestExport');

		try {
			$this->requests->requestExport(caseId: 'c1');
			$this->fail('should refuse');
		} catch (RefusedException $e) {
			$this->assertSame('data-subject-missing', $e->getRule());
			$this->assertSame(RefusedException::STATUS_UNPROCESSABLE, $e->getStatus());
			$this->assertSame('This case names no data subject, so there is nothing to export.', $e->getSentence());
		}
	}//end testAnExportWithoutASubjectIsRefused()
}//end class
