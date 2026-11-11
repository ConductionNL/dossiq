<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
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
use PHPUnit\Framework\TestCase;

/**
 * Every outcome of previewing an erasure and asking for an export: who is refused, and what is written, in order.
 */
class DataSubjectRequestPreviewExportGoldenTest extends TestCase {

	/**
	 * What the platform, the store and the timeline were asked, in order.
	 *
	 * @var array<int, array<int, mixed>>
	 */
	private array $calls = [];

	/**
	 * The service over one stored case.
	 *
	 * @param array<string, mixed> $case The stored case.
	 *
	 * @return DataSubjectRequestCase The service.
	 */
	private function requests(array $case): DataSubjectRequestCase {
		$platform = $this->createMock(PlatformDataSubjectRights::class);
		$platform->method('previewErasure')->willReturnCallback(
			function (string $subject, ?string $type, string $eraseMode, ?string $requestId): array {
				$this->calls[] = ['previewErasure', $subject, $type, $eraseMode, $requestId];

				return [
					'uuid' => 'p1',
					'digest' => 'd1',
					'report' => [
						'counts' => ['erasable' => ['objects' => 3, 'files' => 1], 'pseudonymised' => ['objects' => 2]],
						'protected' => [['name' => 'Dossier']],
					],
				];
			}
		);
		$platform->method('requestExport')->willReturnCallback(
			function (string $subject, ?string $type, ?string $requestId): array {
				$this->calls[] = ['requestExport', $subject, $type, $requestId];

				return ['uuid' => 'e1', 'expiresAt' => '2026-12-01', 'downloadable' => true];
			}
		);
		$cases = $this->createMock(CaseStatusStore::class);
		$cases->method('loadCase')->willReturn($case);
		$cases->method('saveCase')->willReturnCallback(
			function (array $saved): array {
				$this->calls[] = ['saveCase', $saved];

				return $saved;
			}
		);
		$timeline = $this->createMock(CaseTimeline::class);
		$timeline->method('record')->willReturnCallback(
			function (string $caseId, string $kind, string $message, array $fields = []): string {
				$this->calls[] = ['record', $caseId, $kind, $message, $fields];

				return 'entry-1';
			}
		);

		return new DataSubjectRequestCase(platform: $platform, cases: $cases, fourEyes: new FourEyesRule(), timeline: $timeline);
	}//end requests()

	/**
	 * The refusal a call throws, as rule, status and sentence, after asserting nothing was asked.
	 *
	 * @param callable $call The call.
	 *
	 * @return array{0: string, 1: int, 2: string} The refusal.
	 */
	private function refusal(callable $call): array {
		try {
			$call();
		} catch (RefusedException $e) {
			self::assertSame([], $this->calls);

			return [$e->getRule(), $e->getStatus(), $e->getSentence()];
		}

		self::fail('Not refused');
	}//end refusal()

	/**
	 * Allowed: an erasure preview asks the platform, writes the case, then records the act.
	 *
	 * @return void
	 */
	public function testAPreviewIsTakenWrittenAndRecorded(): void {
		$case = ['id' => 'c1', 'dataSubjectRequestType' => ' verwijdering ', 'dataSubject' => ' a@b.nl ', 'dataSubjectType' => ' email ', 'erasureMode' => 'erase', 'erasureOutcome' => ['complete' => true]];

		$preview = $this->requests(case: $case)->preview(caseId: 'c9');

		self::assertSame('p1', $preview['uuid']);
		self::assertSame(['previewErasure', 'a@b.nl', 'email', 'erase', 'c9'], $this->calls[0]);
		self::assertSame(
			['saveCase', array_diff_key($case, ['erasureOutcome' => 1]) + ['erasurePreviewId' => 'p1', 'erasureDigest' => 'd1', 'erasureCounts' => ['erasable' => ['objects' => 3, 'files' => 1], 'pseudonymised' => ['objects' => 2]], 'erasureProtected' => [['name' => 'Dossier']]]],
			[$this->calls[1][0], array_diff_key($this->calls[1][1], ['erasureOutcome' => 1])]
		);
		self::assertSame([], $this->calls[1][1]['erasureOutcome']);
		self::assertSame(['record', 'c9', TimelineKinds::DATA_SUBJECT_REQUEST], array_slice($this->calls[2], 0, 3));
		self::assertStringStartsWith('The erasure preview was taken: ', $this->calls[2][3]);
		self::assertSame(['act' => 'preview', 'previewId' => 'p1', 'erasable' => 4, 'pseudonymised' => 2, 'protected' => 1], $this->calls[2][4]);
		self::assertCount(3, $this->calls);
	}//end testAPreviewIsTakenWrittenAndRecorded()

	/**
	 * A preview without type or mode asks for no type and the pseudonymising mode.
	 *
	 * @return void
	 */
	public function testAPreviewsDefaults(): void {
		$this->requests(case: ['dataSubjectRequestType' => 'verwijdering', 'dataSubject' => 'a@b.nl', 'dataSubjectType' => '  '])->preview(caseId: 'c1');

		self::assertSame(['previewErasure', 'a@b.nl', null, PlatformDataSubjectRights::MODE_PSEUDONYMISE, 'c1'], $this->calls[0]);
	}//end testAPreviewsDefaults()

	/**
	 * Refused previews: not an erasure (checked before the subject), and no subject.
	 *
	 * @return void
	 */
	public function testRefusedPreviews(): void {
		self::assertSame(
			['not-an-erasure-request', RefusedException::STATUS_REFUSED, 'Only a verwijdering is previewed. This case asks for something else.'],
			$this->refusal(fn () => $this->requests(case: ['dataSubjectRequestType' => 'inzage'])->preview(caseId: 'c1'))
		);
		self::assertSame(
			['data-subject-missing', RefusedException::STATUS_UNPROCESSABLE, 'This case names no data subject, so there is nothing to look for.'],
			$this->refusal(fn () => $this->requests(case: ['dataSubjectRequestType' => 'verwijdering', 'dataSubject' => ' '])->preview(caseId: 'c1'))
		);
	}//end testRefusedPreviews()

	/**
	 * Allowed: an export is asked for, written, then recorded.
	 *
	 * @return void
	 */
	public function testAnExportIsAskedWrittenAndRecorded(): void {
		$case = ['id' => 'c1', 'dataSubjectRequestType' => 'correctie', 'dataSubject' => ' a@b.nl '];

		$export = $this->requests(case: $case)->requestExport(caseId: 'c9');

		self::assertSame('e1', $export['uuid']);
		self::assertSame(
			[
				['requestExport', 'a@b.nl', null, 'c9'],
				['saveCase', $case + ['subjectExportId' => 'e1', 'subjectExportExpiresAt' => '2026-12-01', 'subjectExportReady' => true]],
				['record', 'c9', TimelineKinds::DATA_SUBJECT_REQUEST, 'The subject export was asked for. It is ready when the platform says so.', ['act' => 'export', 'exportId' => 'e1']],
			],
			$this->calls
		);
	}//end testAnExportIsAskedWrittenAndRecorded()

	/**
	 * Refused exports: an erasure (checked before the subject), and no subject.
	 *
	 * @return void
	 */
	public function testRefusedExports(): void {
		self::assertSame(
			['not-an-access-request', RefusedException::STATUS_REFUSED, 'A verwijdering is answered by erasing, not by an export.'],
			$this->refusal(fn () => $this->requests(case: ['dataSubjectRequestType' => ' verwijdering '])->requestExport(caseId: 'c1'))
		);
		self::assertSame(
			['data-subject-missing', RefusedException::STATUS_UNPROCESSABLE, 'This case names no data subject, so there is nothing to export.'],
			$this->refusal(fn () => $this->requests(case: ['id' => 'c1'])->requestExport(caseId: 'c1'))
		);
	}//end testRefusedExports()
}//end class
