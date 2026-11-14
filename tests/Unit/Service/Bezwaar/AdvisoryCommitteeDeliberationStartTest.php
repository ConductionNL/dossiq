<?php

/**
 * AdvisoryCommitteeService::guardDeliberationStart() tests.
 *
 * The single-object save that moves an advice request into deliberation runs
 * the panel check on the request as it will be saved: a missing OpenRegister,
 * an empty panel and a dependent member are refused; an independent panel
 * passes and nothing is recorded.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Bezwaar
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/every-move-into-deliberation-runs-the-panel-check/specs/bezwaar-advisory-committee/spec.md#requirement-every-single-object-save-that-starts-deliberation-runs-the-panel-check
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Bezwaar;

use OCA\Dossiq\Service\AdviceDelegationService;
use OCA\Dossiq\Service\Bezwaar\AdvisoryCommitteeService;
use OCA\Dossiq\Service\Bezwaar\BezwaarAuditTrail;
use OCA\Dossiq\Service\Bezwaar\PanelIndependenceChecker;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Transitions\GuardFailedException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The panel check on a save that starts deliberation.
 */
class AdvisoryCommitteeDeliberationStartTest extends TestCase {

	/**
	 * @var SettingsService&MockObject
	 */
	private SettingsService&MockObject $settingsService;

	/**
	 * @var PanelIndependenceChecker&MockObject
	 */
	private PanelIndependenceChecker&MockObject $independence;

	/**
	 * @var BezwaarAuditTrail&MockObject
	 */
	private BezwaarAuditTrail&MockObject $auditTrail;

	/**
	 * Set up mocks with OpenRegister present.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->settingsService = $this->createMock(SettingsService::class);
		$this->independence    = $this->createMock(PanelIndependenceChecker::class);
		$this->auditTrail      = $this->createMock(BezwaarAuditTrail::class);
		$this->settingsService->method('getConfigValue')->willReturnCallback(
			static fn (string $key): string => ['register' => 'reg', 'bac_advice_request_schema' => 'adviceRequest'][$key] ?? ''
		);
	}//end setUp()

	/**
	 * The service under test.
	 *
	 * @return AdvisoryCommitteeService
	 */
	private function service(): AdvisoryCommitteeService {
		return new AdvisoryCommitteeService(
			$this->settingsService,
			$this->createMock(LoggerInterface::class),
			$this->createMock(AdviceDelegationService::class),
			$this->auditTrail,
			$this->independence
		);
	}//end service()

	/**
	 * Without OpenRegister the save is refused and no member is checked.
	 *
	 * @return void
	 */
	public function testRefusesWithoutOpenRegister(): void {
		$this->settingsService->method('getObjectService')->willReturn(null);
		$this->independence->expects($this->never())->method('check');

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('OpenRegister is not available');

		$this->service()->guardDeliberationStart(request: ['panel' => ['a']], requestId: 'req-1');
	}//end testRefusesWithoutOpenRegister()

	/**
	 * A request without a panel cannot start deliberation.
	 *
	 * @return void
	 */
	public function testRefusesAnEmptyPanel(): void {
		$this->settingsService->method('getObjectService')->willReturn(new \stdClass());
		$this->independence->expects($this->never())->method('check');

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Panel must be set before deliberation can start');

		$this->service()->guardDeliberationStart(request: ['bezwaar' => 'bz-1', 'panel' => []], requestId: 'req-1');
	}//end testRefusesAnEmptyPanel()

	/**
	 * An independent panel passes: the members of the request as it will be
	 * saved are checked against its bezwaar, and nothing is recorded.
	 *
	 * @return void
	 */
	public function testAnIndependentPanelPasses(): void {
		$this->settingsService->method('getObjectService')->willReturn(new \stdClass());
		$this->independence->expects($this->once())->method('check')
			->with('bz-1', ['anna', 'bert'])
			->willReturn(['ok' => true, 'member' => null, 'reason' => '']);
		$this->auditTrail->expects($this->never())->method('recordRefusal');

		$this->service()->guardDeliberationStart(request: ['bezwaar' => 'bz-1', 'panel' => ['anna', 'bert']], requestId: 'req-1');
	}//end testAnIndependentPanelPasses()

	/**
	 * A dependent member is recorded on the request's trail, then refused.
	 *
	 * @return void
	 */
	public function testADependentMemberIsRecordedAndRefused(): void {
		$this->settingsService->method('getObjectService')->willReturn(new \stdClass());
		$this->independence->method('check')
			->willReturn(['ok' => false, 'member' => 'bert', 'reason' => 'bert decided the primair besluit']);
		$this->auditTrail->expects($this->once())->method('recordRefusal')
			->with(
				'reg',
				'adviceRequest',
				'req-7',
				'independence-check-failed',
				['conflictingMember' => 'bert', 'reason' => 'bert decided the primair besluit']
			);

		$this->expectException(GuardFailedException::class);
		$this->expectExceptionMessage('Panel member conflict (Awb Art. 7:13 lid 3): bert decided the primair besluit');

		$this->service()->guardDeliberationStart(request: ['bezwaar' => 'bz-1', 'panel' => ['anna', 'bert']], requestId: 'req-7');
	}//end testADependentMemberIsRecordedAndRefused()
}//end class
