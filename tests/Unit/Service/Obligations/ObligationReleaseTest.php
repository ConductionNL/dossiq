<?php

/**
 * Meeting an obligation releases what it blocked, and withdrawing it says so.
 *
 * The advice request is the FIRST obligation of this kind, not a special case,
 * so the behaviour asserted here is the behaviour `ConsultationService` keeps:
 * a mandatory request blocks, an answered one does not, and a withdrawn one is
 * never recorded as an answered one.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Obligations
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
 * @spec openspec/changes/what-a-transition-declares/specs/consultation-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Obligations;

use OCA\Dossiq\Service\AdviceDelegationService;
use OCA\Dossiq\Service\Consultation\ConsultationDependencyGraph;
use OCA\Dossiq\Service\Consultation\ConsultationRepository;
use OCA\Dossiq\Service\ConsultationService;
use OCA\Dossiq\Service\Obligations\ObligationDeclaration;
use OCA\Dossiq\Service\Obligations\ObligationService;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Tests\Support\MakesCaseDateNormaliser;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Dossiq\Service\ConsultationService::consultationBlocks
 * @covers \OCA\Dossiq\Service\Obligations\ObligationDeclaration
 *
 * @spec openspec/changes/what-a-transition-declares/specs/consultation-management/spec.md
 */
class ObligationReleaseTest extends TestCase {
	use MakesCaseDateNormaliser;

	/**
	 * The consultation service, over a settings service that answers nothing.
	 *
	 * Every assertion below is about the blocking RULE, which reads its
	 * argument and touches no store.
	 *
	 * @var ConsultationService
	 */
	private ConsultationService $consultations;

	/**
	 * Build the service.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$settings = $this->createMock(originalClassName: SettingsService::class);
		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$repository = new ConsultationRepository($settings, $logger);

		$this->consultations = new ConsultationService(
			settingsService: $settings,
			logger: $logger,
			adviceDelegation: $this->createMock(originalClassName: AdviceDelegationService::class),
			repository: $repository,
			dependencyGraph: new ConsultationDependencyGraph($repository),
			dates: $this->caseDates(),
			obligations: new ObligationService(
				settingsService: $settings,
				declaration: new ObligationDeclaration(),
				dates: $this->caseDates(),
				logger: $logger,
			),
		);
	}//end setUp()

	/**
	 * The advice request blocks exactly as it did before it was an obligation.
	 *
	 * Mandatory and unanswered blocks; answered, closed and withdrawn do not;
	 * and an optional request never did.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/what-a-transition-declares/specs/consultation-management/spec.md
	 */
	public function testTheAdviceRequestBlocksExactlyAsItDidBefore(): void {
		self::assertTrue(
			condition: $this->consultations->consultationBlocks(
				consultation: ['mandatory' => true, 'status' => 'open'],
			),
		);
		self::assertTrue(
			condition: $this->consultations->consultationBlocks(
				consultation: ['mandatory' => true, 'status' => 'in_handling'],
			),
		);
		self::assertFalse(
			condition: $this->consultations->consultationBlocks(
				consultation: ['mandatory' => true, 'status' => 'advice_uitgebracht'],
			),
		);
		self::assertFalse(
			condition: $this->consultations->consultationBlocks(
				consultation: ['mandatory' => true, 'status' => 'closed'],
			),
		);
		// An OPTIONAL request never held the case, whatever its status.
		self::assertFalse(
			condition: $this->consultations->consultationBlocks(
				consultation: ['mandatory' => false, 'status' => 'open'],
			),
		);
	}//end testTheAdviceRequestBlocksExactlyAsItDidBefore()

	/**
	 * A withdrawn request releases the case and is not an answer.
	 *
	 * Both halves matter. It stops blocking, because a request nobody will
	 * answer cannot hold a case for ever. And it is a WITHDRAWAL, never a
	 * settlement, because advice that was never given is not advice.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/what-a-transition-declares/specs/consultation-management/spec.md
	 */
	public function testAWithdrawnRequestReleasesAndIsNotAnAnswer(): void {
		self::assertFalse(
			condition: $this->consultations->consultationBlocks(
				consultation: ['mandatory' => true, 'status' => 'withdrawn'],
			),
		);

		$declaration = new ObligationDeclaration();
		self::assertFalse(
			condition: $declaration->isBlocking(
				obligation: ['state' => ObligationDeclaration::STATE_WITHDRAWN],
			),
		);
		self::assertNotSame(
			expected: ObligationDeclaration::STATE_MET,
			actual: ObligationDeclaration::STATE_WITHDRAWN,
		);
	}//end testAWithdrawnRequestReleasesAndIsNotAnAnswer()

	/**
	 * An obligation past its term is overdue and STILL blocking.
	 *
	 * An unmet term does not release the case: it says the wait has gone on
	 * too long. Releasing on the term would turn every missed deadline into a
	 * case that quietly proceeded without the thing it was waiting for.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/what-a-transition-declares/specs/consultation-management/spec.md
	 */
	public function testAnOverdueObligationStillBlocks(): void {
		$declaration = new ObligationDeclaration();

		self::assertTrue(
			condition: $declaration->isBlocking(
				obligation: ['state' => ObligationDeclaration::STATE_OPEN, 'dueAt' => '2020-01-01'],
			),
		);
		self::assertTrue(
			condition: $declaration->blocksStatus(
				obligation: ['state' => ObligationDeclaration::STATE_OPEN, 'dueAt' => '2020-01-01', 'blocks' => ['closing']],
				statusId: 'afgehandeld',
				isClosing: true,
			),
		);
	}//end testAnOverdueObligationStillBlocks()
}//end class
