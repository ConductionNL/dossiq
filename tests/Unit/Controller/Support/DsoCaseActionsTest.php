<?php

/**
 * DsoCaseActions and DsoSamenwerkingActions Unit Tests
 *
 * A case or samenwerkverzoek that does not exist is null and is never
 * authorised; one that exists is authorised before it is handed back; the
 * dashboard hands its filters to the read; each write hands the body's fields
 * to its service, with an absent optional field as null.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Controller\Support
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
 * @spec openspec/changes/method-decomposition/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller\Support;

use OCA\Dossiq\Controller\Support\DsoCaseActions;
use OCA\Dossiq\Controller\Support\DsoSamenwerkingActions;
use OCA\Dossiq\Kernel\Contracts\BeschikkingGenerationServiceInterface;
use OCA\Dossiq\Kernel\Contracts\DsoCaseServiceInterface;
use OCA\Dossiq\Kernel\Contracts\DsoDoorsturenNotifierInterface;
use OCA\Dossiq\Kernel\Contracts\DsoObjectRepositoryInterface;
use OCA\Dossiq\Kernel\Contracts\SamenwerkverzoekServiceInterface;
use OCP\IUser;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for DsoCaseActions.
 *
 * @covers \OCA\Dossiq\Controller\Support\DsoCaseActions
 * @covers \OCA\Dossiq\Controller\Support\DsoSamenwerkingActions
 */
final class DsoCaseActionsTest extends TestCase {

	/**
	 * The read collaborator.
	 *
	 * @var DsoObjectRepositoryInterface&MockObject
	 */
	private DsoObjectRepositoryInterface $repository;

	/**
	 * The case service.
	 *
	 * @var DsoCaseServiceInterface&MockObject
	 */
	private DsoCaseServiceInterface $cases;

	/**
	 * The samenwerkverzoek service.
	 *
	 * @var SamenwerkverzoekServiceInterface&MockObject
	 */
	private SamenwerkverzoekServiceInterface $samenwerk;

	/**
	 * The beschikking service.
	 *
	 * @var BeschikkingGenerationServiceInterface&MockObject
	 */
	private BeschikkingGenerationServiceInterface $decisions;

	/**
	 * The doorsturen notifier.
	 *
	 * @var DsoDoorsturenNotifierInterface&MockObject
	 */
	private DsoDoorsturenNotifierInterface $notifier;

	/**
	 * The case actions under test.
	 *
	 * @var DsoCaseActions
	 */
	private DsoCaseActions $writes;

	/**
	 * The samenwerking actions under test.
	 *
	 * @var DsoSamenwerkingActions
	 */
	private DsoSamenwerkingActions $samenwerkingen;

	/**
	 * Set up the doubles and the writes over them.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->repository = $this->createMock(originalClassName: DsoObjectRepositoryInterface::class);
		$this->cases = $this->createMock(originalClassName: DsoCaseServiceInterface::class);
		$this->samenwerk = $this->createMock(originalClassName: SamenwerkverzoekServiceInterface::class);
		$this->decisions = $this->createMock(originalClassName: BeschikkingGenerationServiceInterface::class);
		$this->notifier = $this->createMock(originalClassName: DsoDoorsturenNotifierInterface::class);
		$this->writes = new DsoCaseActions(
			repository: $this->repository,
			dsoCaseService: $this->cases,
			decisionService: $this->decisions,
			doorsturenNotifier: $this->notifier,
		);
		$this->samenwerkingen = new DsoSamenwerkingActions(repository: $this->repository, samenwerkService: $this->samenwerk);
	}//end setUp()

	/**
	 * A missing case or samenwerkverzoek is null and nothing is authorised.
	 *
	 * @return void
	 */
	public function testAMissingObjectIsNullAndNeverAuthorised(): void {
		$this->repository->method('findZaak')->willReturn(null);
		$this->repository->method('findSamenwerkverzoek')->willReturn(null);
		$this->cases->expects($this->never())->method('authorizeZaakMutation');
		$this->samenwerk->expects($this->never())->method('authorizeSamenwerkMutation');
		$user = $this->createMock(originalClassName: IUser::class);

		$this->assertNull(actual: $this->writes->mutableCase(caseId: 'case-1', user: $user));
		$this->assertNull(actual: $this->samenwerkingen->mutableSamenwerk(samenwerkId: 'sw-1', user: $user));
	}//end testAMissingObjectIsNullAndNeverAuthorised()

	/**
	 * An existing case is authorised for the caller before it is handed back; a refusal propagates.
	 *
	 * @return void
	 */
	public function testAnExistingCaseIsAuthorisedBeforeItIsHandedBack(): void {
		$case = ['id' => 'case-1'];
		$user = $this->createMock(originalClassName: IUser::class);
		$this->repository->method('findZaak')->with('case-1')->willReturn($case);
		$this->cases->expects($this->once())->method('authorizeZaakMutation')->with($case, $user);

		$this->assertSame(expected: $case, actual: $this->writes->mutableCase(caseId: 'case-1', user: $user));

		$sw = ['id' => 'sw-1'];
		$this->repository->method('findSamenwerkverzoek')->willReturn($sw);
		$this->samenwerk->method('authorizeSamenwerkMutation')->willThrowException(new \Exception('Not authorized'));
		$this->expectExceptionMessage(message: 'Not authorized');
		$this->samenwerkingen->mutableSamenwerk(samenwerkId: 'sw-1', user: $user);
	}//end testAnExistingCaseIsAuthorisedBeforeItIsHandedBack()

	/**
	 * The dashboard hands its query and filters to the read, an absent filter as ''.
	 *
	 * @return void
	 */
	public function testTheDashboardHandsItsFiltersToTheRead(): void {
		$outcome = ['error' => null, 'results' => [['id' => 'case-1']]];
		$this->repository->expects($this->once())->method('fetchDashboard')
			->with(['dsoStatus' => ['granted']], 'bouwen', '', 'Zuiddrecht')
			->willReturn($outcome);

		$this->assertSame(
			expected: $outcome,
			actual: $this->writes->dashboard(
				params: ['dsoStatus' => ['granted']],
				filters: ['activiteitgroep' => 'bouwen', 'location' => 'Zuiddrecht']
			)
		);
	}//end testTheDashboardHandsItsFiltersToTheRead()

	/**
	 * A transition hands an absent besluitdatum and notes on as null.
	 *
	 * @return void
	 */
	public function testATransitionHandsAbsentOptionalFieldsOnAsNull(): void {
		$this->cases->expects($this->once())->method('transitionStatus')
			->with('case-1', 'granted', null, 'ok', 'anna')
			->willReturn(['id' => 'case-1', 'dsoStatus' => 'granted']);

		$updated = $this->writes->transition(caseId: 'case-1', body: ['newStatus' => 'granted', 'notes' => 'ok'], userId: 'anna');

		$this->assertSame(expected: 'granted', actual: $updated['dsoStatus']);
	}//end testATransitionHandsAbsentOptionalFieldsOnAsNull()

	/**
	 * The beschikking, the samenwerking, its answer and the forward each get the body's fields.
	 *
	 * @return void
	 */
	public function testEachWriteHandsTheBodysFieldsToItsService(): void {
		$this->decisions->expects($this->once())->method('generateBeschikking')
			->with('case-1', 'refused', '')->willReturn(['id' => 'b-1']);
		$this->samenwerk->expects($this->once())->method('initiateSamenwerking')
			->with('case-1', 'provincie', 'advies nodig')->willReturn(['id' => 'sw-1']);
		$this->samenwerk->expects($this->once())->method('respondToSamenwerking')
			->with('sw-1', true, '')->willReturn(['id' => 'sw-1', 'accepted' => true]);
		$this->notifier->expects($this->once())->method('dispatchDoorgestuurd')
			->with(['id' => 'case-1'], 'case-1', 'gemeente-b', '', 'anna');

		$this->assertSame(expected: ['id' => 'b-1'], actual: $this->writes->beschikking(caseId: 'case-1', body: ['outcome' => 'refused']));
		$this->assertSame(
			expected: ['id' => 'sw-1'],
			actual: $this->samenwerkingen->startSamenwerking(
				caseId: 'case-1',
				body: ['requestedCompetentAuthority' => 'provincie', 'rationale' => 'advies nodig']
			)
		);
		$this->assertTrue(condition: $this->samenwerkingen->answerSamenwerking(samenwerkId: 'sw-1', body: ['accept' => 1])['accepted']);
		$this->writes->forward(case: ['id' => 'case-1'], caseId: 'case-1', body: ['targetBevoegdGezag' => 'gemeente-b'], userId: 'anna');
	}//end testEachWriteHandsTheBodysFieldsToItsService()
}//end class
