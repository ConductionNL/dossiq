<?php

/**
 * The case side of an AVG request: what lands on the case, what is refused,
 * and what the handler reads afterwards.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
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
 * A data subject request, driven from the case.
 */
class DataSubjectRequestCaseTest extends TestCase {

	/**
	 * The platform.
	 *
	 * @var PlatformDataSubjectRights&MockObject
	 */
	private PlatformDataSubjectRights $platform;

	/**
	 * The case and its status record chain.
	 *
	 * @var CaseStatusStore&MockObject
	 */
	private CaseStatusStore $cases;

	/**
	 * The timeline.
	 *
	 * @var CaseTimeline&MockObject
	 */
	private CaseTimeline $timeline;

	/**
	 * The subject under test.
	 *
	 * @var DataSubjectRequestCase
	 */
	private DataSubjectRequestCase $requests;

	/**
	 * What was last saved onto the case.
	 *
	 * @var array<string, mixed>
	 */
	private array $saved = [];

	/**
	 * Wire the case side over doubles of everything it drives.
	 *
	 * The four eyes rule is REAL rather than mocked: the whole question this
	 * class asks it, who performed which act, is the question a double would
	 * answer by fiat.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->platform = $this->createMock(PlatformDataSubjectRights::class);
		$this->cases = $this->createMock(CaseStatusStore::class);
		$this->timeline = $this->createMock(CaseTimeline::class);

		$this->cases->method('saveCase')->willReturnCallback(
			function (array $case): array {
				$this->saved = $case;

				return $case;
			}
		);

		$this->requests = new DataSubjectRequestCase(
			platform: $this->platform,
			cases: $this->cases,
			fourEyes: new FourEyesRule(),
			timeline: $this->timeline,
		);
	}//end setUp()

	/**
	 * One status record, in the shape the engine writes.
	 *
	 * @param string $label The transition label.
	 * @param string $actor Who took it.
	 *
	 * @return array<string, mixed>
	 */
	private function record(string $label, string $actor): array {
		return ['transitionLabel' => $label, 'actor' => $actor, 'createdAt' => '2026-09-16T10:00:00+00:00'];
	}//end record()

	/**
	 * The preview's counts and the protected items land on the case, and a
	 * previous run's report is cleared with them.
	 *
	 * @return void
	 */
	public function testThePreviewAndItsGroundsLandOnTheCase(): void {
		$this->cases->method('loadCase')->willReturn(
			[
				'id' => 'c1',
				'dataSubjectRequestType' => 'verwijdering',
				'dataSubject' => 'a@b.nl',
				'erasureOutcome' => ['complete' => true],
			]
		);
		$this->platform->method('previewErasure')->willReturn(
			[
				'uuid' => 'p1',
				'digest' => 'abc123',
				'report' => [
					'counts' => [
						'erasable' => ['objects' => 8, 'files' => 2],
						'protected' => ['objects' => 4],
					],
					'protected' => [
						['name' => 'Subsidiedossier 2019', 'ground' => 'Archiefwet', 'basis' => 'selectielijst', 'action' => 'Wacht tot 2031'],
					],
				],
			]
		);

		$this->timeline->expects(self::once())->method('record')->with(
			self::equalTo('c1'),
			self::equalTo(TimelineKinds::DATA_SUBJECT_REQUEST),
		);

		$this->requests->preview(caseId: 'c1');

		self::assertSame('p1', $this->saved['erasurePreviewId']);
		self::assertSame('abc123', $this->saved['erasureDigest']);
		self::assertSame(8, $this->saved['erasureCounts']['erasable']['objects']);
		self::assertSame('Archiefwet', $this->saved['erasureProtected'][0]['ground']);
		self::assertSame([], $this->saved['erasureOutcome']);
	}//end testThePreviewAndItsGroundsLandOnTheCase()

	/**
	 * A preview is refused on a case that did not ask for an erasure.
	 *
	 * @return void
	 */
	public function testAnInzageCaseIsNotPreviewed(): void {
		$this->cases->method('loadCase')->willReturn(
			['id' => 'c1', 'dataSubjectRequestType' => 'inzage', 'dataSubject' => 'a@b.nl']
		);
		$this->platform->expects(self::never())->method('previewErasure');

		$this->expectException(RefusedException::class);
		$this->requests->preview(caseId: 'c1');
	}//end testAnInzageCaseIsNotPreviewed()

	/**
	 * A run without the approving act is refused, and nothing is asked of the
	 * platform.
	 *
	 * @return void
	 */
	public function testARunWithoutTheApprovingActIsRefused(): void {
		$this->cases->method('loadCase')->willReturn(
			['id' => 'c1', 'dataSubjectRequestType' => 'verwijdering', 'erasurePreviewId' => 'p1']
		);
		$this->cases->method('findStatusRecords')->willReturn(
			[$this->record(label: DataSubjectRequestCase::ACT_PREPARE, actor: 'anna')]
		);
		$this->platform->expects(self::never())->method('approvePreview');
		$this->platform->expects(self::never())->method('runErasure');

		try {
			$this->requests->run(caseId: 'c1');
			self::fail('the run was not refused');
		} catch (RefusedException $e) {
			self::assertSame('erasure-not-approved', $e->getRule());
			self::assertStringContainsString(DataSubjectRequestCase::ACT_APPROVE, $e->getSentence());
		}
	}//end testARunWithoutTheApprovingActIsRefused()

	/**
	 * The person who prepared the erasure cannot be the person who approved it,
	 * even when a record says they did.
	 *
	 * @return void
	 */
	public function testThePreparerCannotBeTheApprover(): void {
		$this->cases->method('loadCase')->willReturn(
			['id' => 'c1', 'dataSubjectRequestType' => 'verwijdering', 'erasurePreviewId' => 'p1']
		);
		$this->cases->method('findStatusRecords')->willReturn(
			[
				$this->record(label: DataSubjectRequestCase::ACT_PREPARE, actor: 'anna'),
				$this->record(label: DataSubjectRequestCase::ACT_APPROVE, actor: 'anna'),
			]
		);
		$this->platform->expects(self::never())->method('runErasure');

		try {
			$this->requests->run(caseId: 'c1');
			self::fail('the run was not refused');
		} catch (RefusedException $e) {
			self::assertSame('erasure-four-eyes-broken', $e->getRule());
			self::assertSame(RefusedException::STATUS_FORBIDDEN, $e->getStatus());
		}
	}//end testThePreparerCannotBeTheApprover()

	/**
	 * An approved run writes the platform's report onto the case and says on the
	 * timeline what was left standing.
	 *
	 * @return void
	 */
	public function testAnApprovedRunRecordsItsOutcome(): void {
		$this->cases->method('loadCase')->willReturn(
			['id' => 'c1', 'dataSubjectRequestType' => 'verwijdering', 'erasurePreviewId' => 'p1']
		);
		$this->cases->method('findStatusRecords')->willReturn(
			[
				$this->record(label: DataSubjectRequestCase::ACT_PREPARE, actor: 'anna'),
				$this->record(label: DataSubjectRequestCase::ACT_APPROVE, actor: 'bram'),
			]
		);
		$outcome = [
			'destroyed' => ['o1', 'o2'],
			'pseudonymised' => ['o3'],
			'withheld' => [['name' => 'Subsidiedossier 2019']],
			'refused' => [],
			'failed' => [],
			'complete' => false,
		];
		$this->platform->expects(self::once())->method('approvePreview')->with('p1');
		$this->platform->method('runErasure')->willReturn($outcome);

		$recorded = [];
		$this->timeline->method('record')->willReturnCallback(
			static function (string $caseId, string $kind, string $message, array $fields = []) use (&$recorded): string {
				$recorded = ['message' => $message, 'fields' => $fields];

				return 't1';
			}
		);

		$answer = $this->requests->run(caseId: 'c1');

		self::assertSame($outcome, $answer);
		self::assertSame($outcome, $this->saved['erasureOutcome']);
		self::assertSame('bram', $this->saved['erasureApprovedBy']);
		self::assertSame(1, $recorded['fields']['withheld']);
		self::assertFalse($recorded['fields']['complete']);
		self::assertStringContainsString('1 left standing', $recorded['message']);
	}//end testAnApprovedRunRecordsItsOutcome()

	/**
	 * A run on a case with no preview is refused before the chain is even read.
	 *
	 * @return void
	 */
	public function testARunWithoutAPreviewIsRefused(): void {
		$this->cases->method('loadCase')->willReturn(
			['id' => 'c1', 'dataSubjectRequestType' => 'verwijdering']
		);
		$this->cases->expects(self::never())->method('findStatusRecords');

		try {
			$this->requests->run(caseId: 'c1');
			self::fail('the run was not refused');
		} catch (RefusedException $e) {
			self::assertSame('erasure-preview-unknown', $e->getRule());
		}
	}//end testARunWithoutAPreviewIsRefused()

	/**
	 * An export past its life is not offered, and the case says it expired.
	 *
	 * @return void
	 */
	public function testAnExpiredExportIsNotOffered(): void {
		$this->cases->method('loadCase')->willReturn(
			['id' => 'c1', 'dataSubjectRequestType' => 'inzage', 'subjectExportId' => 'e1']
		);
		$this->platform->method('export')->willReturn(
			[
				'uuid' => 'e1',
				'downloadable' => false,
				'readyAt' => '2026-09-01T10:00:00+00:00',
				'expiresAt' => '2026-09-08T10:00:00+00:00',
			]
		);

		$state = $this->requests->exportState(caseId: 'c1');

		self::assertFalse($state['downloadable']);
		self::assertTrue($state['expired']);
		self::assertSame('2026-09-08T10:00:00+00:00', $state['expiresAt']);
	}//end testAnExpiredExportIsNotOffered()

	/**
	 * An export still being assembled is neither downloadable nor expired,
	 * because telling a handler it expired sends them to ask for a second one.
	 *
	 * @return void
	 */
	public function testAnUnfinishedExportIsNotCalledExpired(): void {
		$this->cases->method('loadCase')->willReturn(
			['id' => 'c1', 'dataSubjectRequestType' => 'inzage', 'subjectExportId' => 'e1']
		);
		$this->platform->method('export')->willReturn(
			['uuid' => 'e1', 'downloadable' => false, 'readyAt' => null, 'expiresAt' => '']
		);

		$state = $this->requests->exportState(caseId: 'c1');

		self::assertFalse($state['downloadable']);
		self::assertFalse($state['expired']);
	}//end testAnUnfinishedExportIsNotCalledExpired()

	/**
	 * A ready export is offered, and the case records that it is.
	 *
	 * @return void
	 */
	public function testAReadyExportIsOffered(): void {
		$this->cases->method('loadCase')->willReturn(
			['id' => 'c1', 'dataSubjectRequestType' => 'inzage', 'subjectExportId' => 'e1']
		);
		$this->platform->method('export')->willReturn(
			[
				'uuid' => 'e1',
				'downloadable' => true,
				'readyAt' => '2026-09-15T10:00:00+00:00',
				'expiresAt' => '2026-09-22T10:00:00+00:00',
			]
		);

		$state = $this->requests->exportState(caseId: 'c1');

		self::assertTrue($state['downloadable']);
		self::assertFalse($state['expired']);
		self::assertTrue($this->saved['subjectExportReady']);
	}//end testAReadyExportIsOffered()

	/**
	 * A verwijdering is answered by erasing, so it is not handed an export.
	 *
	 * @return void
	 */
	public function testAVerwijderingIsNotHandedAnExport(): void {
		$this->cases->method('loadCase')->willReturn(
			['id' => 'c1', 'dataSubjectRequestType' => 'verwijdering', 'dataSubject' => 'a@b.nl']
		);
		$this->platform->expects(self::never())->method('requestExport');

		try {
			$this->requests->requestExport(caseId: 'c1');
			self::fail('the export was not refused');
		} catch (RefusedException $e) {
			self::assertSame('not-an-access-request', $e->getRule());
		}
	}//end testAVerwijderingIsNotHandedAnExport()
}//end class
