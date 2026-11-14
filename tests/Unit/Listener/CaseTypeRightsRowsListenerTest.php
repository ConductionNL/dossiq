<?php

/**
 * CaseTypeRightsRowsListener unit tests
 *
 * OpenRegister asks each provider for its rights-matrix rows. Dossiq answers
 * only for its own provider: the assigned-group read rows always, then the
 * rows of every current (published, not superseded) case type's matrix. A
 * failing case-type read is logged and grants nothing, while the
 * assigned-group rows already given stand.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Listener;

use OCA\Dossiq\Listener\CaseTypeRightsRowsListener;
use OCA\Dossiq\Service\Access\AssignedGroupReadRows;
use OCA\Dossiq\Service\Access\CaseRightsRowSources;
use OCA\Dossiq\Service\Access\CaseTypeRightsMatrixRows;
use OCA\Dossiq\Service\Access\OrganisationRoles;
use OCA\Dossiq\Service\CaseTypeStore;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Unit tests for the case-type rights rows answer.
 *
 * @covers \OCA\Dossiq\Listener\CaseTypeRightsRowsListener
 *
 * @uses \OCA\Dossiq\Service\Access\CaseRightsRowSources
 * @uses \OCA\Dossiq\Service\CaseTypeStore
 */
class CaseTypeRightsRowsListenerTest extends TestCase {

	/**
	 * The case-type store, with only the register read stubbed.
	 *
	 * @var CaseTypeStore|MockObject
	 */
	private CaseTypeStore $store;

	/**
	 * The mocked organisation roles.
	 *
	 * @var OrganisationRoles|MockObject
	 */
	private OrganisationRoles $roles;

	/**
	 * The mocked case-type matrix row source.
	 *
	 * @var CaseTypeRightsMatrixRows|MockObject
	 */
	private CaseTypeRightsMatrixRows $caseTypeRows;

	/**
	 * The mocked assigned-group row source.
	 *
	 * @var AssignedGroupReadRows|MockObject
	 */
	private AssignedGroupReadRows $assignedRows;

	/**
	 * The mocked logger.
	 *
	 * @var LoggerInterface|MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * Build the collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->store = $this->createPartialMock(CaseTypeStore::class, ['everyCaseType']);
		$this->roles = $this->createMock(OrganisationRoles::class);
		$this->roles->method('allUnscoped')->willReturn([['id' => 'role-1', 'group' => 'behandelaars']]);
		$this->caseTypeRows = $this->createMock(CaseTypeRightsMatrixRows::class);
		$this->assignedRows = $this->createMock(AssignedGroupReadRows::class);
		$this->assignedRows->method('rows')->willReturn([['group' => 'team-a', 'actions' => ['read']]]);
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * Only current case types reach the matrix, read without rbac or tenancy,
	 * and both sets of rows are added under dossiq's app id.
	 *
	 * @return void
	 */
	public function testTheProviderGetsAssignedAndCurrentCaseTypeRows(): void {
		$this->store->expects($this->once())->method('everyCaseType')
			->with(['_rbac' => false, '_multitenancy' => false])
			->willReturn(
				[
					['id' => 'ct-live', 'rightsMatrix' => [['role' => 'role-1']]],
					['id' => 'ct-draft', 'isDraft' => true, 'rightsMatrix' => [['role' => 'role-1']]],
					['id' => 'ct-old', 'supersededBy' => 'ct-live', 'rightsMatrix' => [['role' => 'role-1']]],
					['uuid' => 'ct-bare'],
					['@self' => ['id' => 'ct-self'], 'isDraft' => false, 'supersededBy' => ''],
				]
			);
		$this->caseTypeRows->expects($this->once())->method('rows')->with(
			[
				['id' => 'ct-live', 'rightsMatrix' => [['role' => 'role-1']]],
				['id' => 'ct-bare', 'rightsMatrix' => []],
				['id' => 'ct-self', 'rightsMatrix' => []],
			],
			[['id' => 'role-1', 'group' => 'behandelaars']]
		)->willReturn([['group' => 'behandelaars', 'actions' => ['read']]]);
		$this->logger->expects($this->never())->method('error');

		$event = $this->rowsEvent(provider: CaseTypeRightsRowsListener::PROVIDER);
		$this->listener()->handle($event);

		$this->assertSame(
			[
				['dossiq', [['group' => 'team-a', 'actions' => ['read']]]],
				['dossiq', [['group' => 'behandelaars', 'actions' => ['read']]]],
			],
			$event->added
		);
	}//end testTheProviderGetsAssignedAndCurrentCaseTypeRows()

	/**
	 * A failing case-type read is logged and adds nothing more, while the
	 * assigned-group rows already added stand.
	 *
	 * @return void
	 */
	public function testAFailingCaseTypeReadGrantsNothingAndIsLogged(): void {
		$this->store->method('everyCaseType')->willThrowException(new RuntimeException('register gone'));
		$this->caseTypeRows->expects($this->never())->method('rows');
		$this->logger->expects($this->once())->method('error')->with(
			$this->stringContains('grant nothing'),
			['exception' => 'register gone']
		);

		$event = $this->rowsEvent(provider: CaseTypeRightsRowsListener::PROVIDER);
		$this->listener()->handle($event);

		$this->assertSame([['dossiq', [['group' => 'team-a', 'actions' => ['read']]]]], $event->added);
	}//end testAFailingCaseTypeReadGrantsNothingAndIsLogged()

	/**
	 * Another provider's request is not answered.
	 *
	 * @return void
	 */
	public function testAnotherProviderIsNotAnswered(): void {
		$this->assignedRows->expects($this->never())->method('rows');
		$this->store->expects($this->never())->method('everyCaseType');

		$event = $this->rowsEvent(provider: 'someapp.rights');
		$this->listener()->handle($event);

		$this->assertSame([], $event->added);
	}//end testAnotherProviderIsNotAnswered()

	/**
	 * Any other event is ignored.
	 *
	 * @return void
	 */
	public function testAForeignEventIsIgnored(): void {
		$this->assignedRows->expects($this->never())->method('rows');
		$this->store->expects($this->never())->method('everyCaseType');

		$this->listener()->handle(new Event());
	}//end testAForeignEventIsIgnored()

	/**
	 * The listener over the shared collaborators.
	 *
	 * @return CaseTypeRightsRowsListener
	 */
	private function listener(): CaseTypeRightsRowsListener {
		return new CaseTypeRightsRowsListener(
			$this->store,
			$this->roles,
			new CaseRightsRowSources(caseTypes: $this->caseTypeRows, assignedGroup: $this->assignedRows),
			$this->logger,
		);
	}//end listener()

	/**
	 * OpenRegister's matrix-rows event, as a double recording what was added.
	 *
	 * @param string $provider The provider asked.
	 *
	 * @return Event
	 */
	private function rowsEvent(string $provider): Event {
		if (class_exists(CaseTypeRightsRowsListener::EVENT) === false) {
			eval('namespace OCA\OpenRegister\Event; class MatrixRowsRequestedEvent extends \OCP\EventDispatcher\Event {'
				. ' public array $added = [];'
				. ' public function __construct(private string $provider) {}'
				. ' public function getProvider(): string { return $this->provider; }'
				. ' public function addRows(array $rows, string $appId): void { $this->added[] = [$appId, $rows]; } }');
		}

		$class = CaseTypeRightsRowsListener::EVENT;

		return new $class($provider);
	}//end rowsEvent()
}//end class
