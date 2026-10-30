<?php

/**
 * Suspending picks one of the case type's pause reasons, or keeps the free text.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/termijn-pause-extension/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Service\CaseLifecycleService;
use OCA\Dossiq\Service\DeadlineExtensionService;
use OCA\Dossiq\Service\DeadlinePauseService;
use OCA\Dossiq\Service\Lifecycle\CaseEndingActs;
use OCA\Dossiq\Service\Pause\PauseReasonReader;
use OCA\Dossiq\Service\TermijnService;
use OCA\Dossiq\Service\Transitions\CaseStatusStore;
use OCA\Dossiq\Service\Transitions\CaseTypeReader;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for the pause reason on the suspend gesture.
 *
 * @covers \OCA\Dossiq\Service\CaseLifecycleService
 * @covers \OCA\Dossiq\Service\DeadlinePauseService
 */
class CaseLifecycleSuspendPauseReasonTest extends TestCase {

	/**
	 * The reasons the case type declares, normalised.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private const REASONS = [
		[
			'key' => 'machtiging',
			'name' => 'Wacht op machtiging van de verzoeker',
			'legalBasis' => 'Awb 4:5, hersteltermijn',
			'category' => 'applicant',
			'waitingOn' => 'applicant',
		],
	];

	/**
	 * The lifecycle service over one open case, with a statutory term.
	 *
	 * @param DeadlinePauseService $pauseService The pause service the term goes through.
	 *
	 * @return CaseLifecycleService
	 */
	private function lifecycle(DeadlinePauseService $pauseService): CaseLifecycleService {
		$case = ['id' => 'case-1', 'caseType' => 'ct-1', 'status' => 'st-progress'];
		$store = $this->createMock(CaseStatusStore::class);
		$store->method('loadCase')->willReturnCallback(static fn (): array => $case);
		$store->method('saveCase')->willReturnArgument(0);
		$store->method('lookupStatusName')->willReturn('In behandeling');

		$caseTypes = $this->createMock(CaseTypeReader::class);
		$caseTypes->method('read')->willReturn(['suspensionAllowed' => true, 'extensionAllowed' => false]);
		$caseTypes->method('isFinalStatus')->willReturn(false);

		$termService = $this->createMock(TermijnService::class);
		$termService->method('getTermijnInstanceForZaak')->willReturn(['id' => 'ti-1']);

		return new CaseLifecycleService(
			store: $store,
			caseTypes: $caseTypes,
			termService: $termService,
			pauseService: $pauseService,
			extensionService: $this->createMock(DeadlineExtensionService::class),
			logger: $this->createMock(LoggerInterface::class),
			endings: $this->createMock(originalClassName: CaseEndingActs::class),
		);
	}//end lifecycle()

	/**
	 * The pause service over a case type that declares the given reasons.
	 *
	 * @param array<int, array<string, mixed>> $reasons What the case type declares.
	 *
	 * @return DeadlinePauseService
	 */
	private function pauses(array $reasons = self::REASONS): DeadlinePauseService {
		$reader = $this->createMock(PauseReasonReader::class);
		$reader->method('forCase')->willReturn($reasons);

		return new DeadlinePauseService(
			termService: $this->createMock(TermijnService::class),
			reasons: $reader,
		);
	}//end pauses()

	/**
	 * The state lists the case type's reasons, with the ground each rests on.
	 *
	 * @return void
	 */
	public function testTheStateOffersTheDeclaredReasons(): void {
		$pauseService = $this->createMock(DeadlinePauseService::class);
		$pauseService->method('offeredReasons')->willReturn($this->pauses()->offeredReasons(caseId: 'case-1'));

		$state = $this->lifecycle(pauseService: $pauseService)->state(caseId: 'case-1');

		$this->assertSame(
			[['key' => 'machtiging', 'name' => 'Wacht op machtiging van de verzoeker', 'legalBasis' => 'Awb 4:5, hersteltermijn']],
			$state['pauseReasons']
		);
	}//end testTheStateOffersTheDeclaredReasons()

	/**
	 * A picked reason is stored on the pause, with the rationale the pause service gives it.
	 *
	 * @return void
	 */
	public function testAPickedReasonIsStoredOnThePause(): void {
		$pauseService = $this->createMock(DeadlinePauseService::class);
		$pauseService->method('suspendRationale')->willReturn('Wacht op machtiging van de verzoeker');
		$pauseService->expects($this->once())
			->method('registerPauze')
			->with('ti-1', 14, 'Wacht op machtiging van de verzoeker', '', 'machtiging')
			->willReturn([]);

		$this->lifecycle(pauseService: $pauseService)->suspend(caseId: 'case-1', reason: '', days: 14, pauseReason: 'machtiging');
	}//end testAPickedReasonIsStoredOnThePause()

	/**
	 * Without a reason picked, the free text is the reason, as before.
	 *
	 * @return void
	 */
	public function testTheFreeTextPathStays(): void {
		$pauseService = $this->createMock(DeadlinePauseService::class);
		$pauseService->expects($this->never())->method('suspendRationale');
		$pauseService->expects($this->once())
			->method('registerPauze')
			->with('ti-1', 7, 'Aanvulling gevraagd', '', '')
			->willReturn([]);

		$this->lifecycle(pauseService: $pauseService)->suspend(caseId: 'case-1', reason: 'Aanvulling gevraagd', days: 7);
	}//end testTheFreeTextPathStays()

	/**
	 * Without a picked reason, an empty text is still refused.
	 *
	 * @return void
	 */
	public function testNoReasonAndNoTextIsRefused(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('reason_required');

		$this->lifecycle(pauseService: $this->createMock(DeadlinePauseService::class))->suspend(caseId: 'case-1', reason: ' ', days: 14);
	}//end testNoReasonAndNoTextIsRefused()

	/**
	 * A picked reason needs no text: its name is the rationale; a typed text wins.
	 *
	 * @return void
	 */
	public function testTheRationaleIsTheTextOrTheReasonsName(): void {
		$this->assertSame('Wacht op machtiging van de verzoeker', $this->pauses()->suspendRationale(caseId: 'case-1', reason: ' ', pauseReason: 'machtiging'));
		$this->assertSame('Eigen woorden', $this->pauses()->suspendRationale(caseId: 'case-1', reason: 'Eigen woorden', pauseReason: 'machtiging'));
	}//end testTheRationaleIsTheTextOrTheReasonsName()

	/**
	 * A key the case type does not declare is refused.
	 *
	 * @return void
	 */
	public function testAnUndeclaredReasonIsRefused(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('pause_reason_not_declared');

		$this->pauses(reasons: [])->suspendRationale(caseId: 'case-1', reason: 'x', pauseReason: 'made-up');
	}//end testAnUndeclaredReasonIsRefused()
}//end class
