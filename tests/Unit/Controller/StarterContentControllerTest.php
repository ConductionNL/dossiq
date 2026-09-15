<?php

/**
 * StarterContentController wire-contract tests.
 *
 * The admin side of what a new instance starts with. The defects pinned are the
 * ones that would not show up as an error anywhere: an unreachable register
 * reading as "nothing shipped", a partial domain copy answering 200, and an
 * arbitrary schema slug off the URL reaching the config resolver.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Controller
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
 * @spec openspec/changes/starter-content-and-templates/specs/case-type-seed-data/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller;

use OCA\Dossiq\Controller\StarterContentController;
use OCA\Dossiq\Service\Starter\CaseTypeRetirementService;
use OCA\Dossiq\Service\Starter\DomainCopyService;
use OCA\Dossiq\Service\Starter\ReusableProcessStepService;
use OCA\Dossiq\Service\Starter\ShippedConfigurationService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Wire-contract tests for StarterContentController.
 *
 * @covers \OCA\Dossiq\Controller\StarterContentController
 */
class StarterContentControllerTest extends TestCase {

	/**
	 * The provenance ledger.
	 *
	 * @var ShippedConfigurationService|MockObject
	 */
	private ShippedConfigurationService $shipped;

	/**
	 * Retire and restore.
	 *
	 * @var CaseTypeRetirementService|MockObject
	 */
	private CaseTypeRetirementService $retirement;

	/**
	 * The domain copy.
	 *
	 * @var DomainCopyService|MockObject
	 */
	private DomainCopyService $domains;

	/**
	 * The shared process steps.
	 *
	 * @var ReusableProcessStepService|MockObject
	 */
	private ReusableProcessStepService $steps;

	/**
	 * The controller under test.
	 *
	 * @var StarterContentController
	 */
	private StarterContentController $controller;

	/**
	 * The request parameters the current test answers with.
	 *
	 * @var array<string, mixed>
	 */
	private array $params = [];

	/**
	 * Build the controller with mocked collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->shipped = $this->getMockBuilder(ShippedConfigurationService::class)
			->disableOriginalConstructor()
			->onlyMethods(['overview', 'adopt'])
			->getMock();
		$this->retirement = $this->getMockBuilder(CaseTypeRetirementService::class)
			->disableOriginalConstructor()
			->onlyMethods(['retire', 'restore'])
			->getMock();
		$this->domains = $this->getMockBuilder(DomainCopyService::class)
			->disableOriginalConstructor()
			->onlyMethods(['copy'])
			->getMock();
		$this->steps = $this->getMockBuilder(ReusableProcessStepService::class)
			->disableOriginalConstructor()
			->onlyMethods(['usedBy', 'delete'])
			->getMock();

		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			fn (string $key, mixed $default = null): mixed => ($this->params[$key] ?? $default)
		);

		$this->controller = new StarterContentController(
			appName: 'dossiq',
			request: $request,
			shipped: $this->shipped,
			retirement: $this->retirement,
			domains: $this->domains,
			steps: $this->steps,
			logger: new NullLogger(),
		);
	}//end setUp()

	/**
	 * A schema the screen does not report on is a 404, and nothing is read.
	 *
	 * 🔑 `{schema}` COMES OFF THE URL. Handing it straight to the config
	 * resolver would let a caller read any schema in the register through a
	 * screen meant for the seeded ones.
	 *
	 * @return void
	 */
	public function testAnUnreportedSchemaIsRefusedWithoutReadingAnything(): void {
		$this->shipped->expects($this->never())->method('overview');

		$response = $this->controller->shipped(schema: 'tenantBillingEvent');

		self::assertSame(expected: Http::STATUS_NOT_FOUND, actual: $response->getStatus());
	}//end testAnUnreportedSchemaIsRefusedWithoutReadingAnything()

	/**
	 * An unreachable register is a 503, never "nothing shipped".
	 *
	 * ADR-102: absence fails closed with a status. An empty list here tells an
	 * administrator that dossiq ships no case types at all.
	 *
	 * @return void
	 */
	public function testAnUnreachableRegisterIsNotNothingShipped(): void {
		$this->shipped->method('overview')->willReturn(null);

		$response = $this->controller->shipped(schema: 'caseType');

		self::assertSame(expected: Http::STATUS_SERVICE_UNAVAILABLE, actual: $response->getStatus());
	}//end testAnUnreachableRegisterIsNotNothingShipped()

	/**
	 * The shipped overview comes back as a counted list.
	 *
	 * @return void
	 */
	public function testTheShippedOverviewComesBackAsACountedList(): void {
		$this->shipped->method('overview')->willReturn(
			[
				[
					'targetObject' => 'ct-1',
					'title' => 'Bezwaar',
					'state' => 'changed',
					'set' => 'bezwaar-beroep',
					'setVersion' => '0.9.0',
					'latestVersion' => '1.0.0',
					'updateAvailable' => true,
				],
			]
		);

		$data = $this->controller->shipped(schema: 'caseType')->getData();

		self::assertSame(expected: 1, actual: $data['total']);
		self::assertSame(expected: 'changed', actual: $data['items'][0]['state']);
	}//end testTheShippedOverviewComesBackAsACountedList()

	/**
	 * Adopting over a local change without accepting the loss is a conflict.
	 *
	 * @return void
	 */
	public function testAdoptingOverALocalChangeIsAConflict(): void {
		$this->shipped->method('adopt')->willReturn(['adopted' => false, 'reason' => 'changed_locally']);

		$response = $this->controller->adoptShipped(schema: 'caseType', id: 'ct-1');

		self::assertSame(expected: Http::STATUS_CONFLICT, actual: $response->getStatus());
		self::assertSame(expected: 'changed_locally', actual: $response->getData()['reason']);
	}//end testAdoptingOverALocalChangeIsAConflict()

	/**
	 * Retiring a case type that is not there is a 404, and retiring one that is
	 * answers its new state.
	 *
	 * @return void
	 */
	public function testRetireAnswersTheStateOrAFourOhFour(): void {
		$this->retirement->method('retire')->willReturnOnConsecutiveCalls(
			['ok' => false, 'reason' => 'not_found', 'state' => ''],
			['ok' => true, 'reason' => '', 'state' => 'retired'],
		);

		self::assertSame(
			expected: Http::STATUS_NOT_FOUND,
			actual: $this->controller->retire(caseTypeId: 'ct-nowhere')->getStatus()
		);

		$response = $this->controller->retire(caseTypeId: 'ct-1');

		self::assertSame(expected: Http::STATUS_OK, actual: $response->getStatus());
		self::assertSame(expected: 'retired', actual: $response->getData()['state']);
	}//end testRetireAnswersTheStateOrAFourOhFour()

	/**
	 * Restoring answers the state it left the case type in.
	 *
	 * @return void
	 */
	public function testRestoreAnswersTheStateItLeftTheCaseTypeIn(): void {
		$this->retirement->method('restore')->willReturn(['ok' => true, 'reason' => '', 'state' => 'in_use']);

		$response = $this->controller->restore(caseTypeId: 'ct-1');

		self::assertSame(expected: Http::STATUS_OK, actual: $response->getStatus());
		self::assertSame(expected: 'in_use', actual: $response->getData()['state']);
	}//end testRestoreAnswersTheStateItLeftTheCaseTypeIn()

	/**
	 * A partial domain copy does not answer 200.
	 *
	 * 🔑 A CALLER THAT ONLY CHECKS FOR 2XX MUST STILL HAVE TO READ `complete`.
	 * 207 is what makes the partial visible to a client that is not looking for
	 * it; a 200 with `complete: false` is the silent partial wearing a tick.
	 *
	 * @return void
	 */
	public function testAPartialDomainCopyDoesNotAnswerTwoHundred(): void {
		$this->params = ['name' => 'VTH Bommelerwaard'];
		$this->domains->method('copy')->willReturn(
			[
				'complete' => false,
				'domain' => 'dom-2',
				'carried' => ['caseType' => 1],
				'notCarried' => ['contentTemplate'],
				'reason' => '',
			]
		);

		$response = $this->controller->copyDomain(domainId: 'dom-vth');

		self::assertSame(expected: Http::STATUS_MULTI_STATUS, actual: $response->getStatus());
		self::assertSame(expected: ['contentTemplate'], actual: $response->getData()['notCarried']);
	}//end testAPartialDomainCopyDoesNotAnswerTwoHundred()

	/**
	 * A domain copy with no name is refused before anything is written.
	 *
	 * @return void
	 */
	public function testADomainCopyWithNoNameIsRefused(): void {
		$this->params = ['name' => '  '];
		$this->domains->expects($this->never())->method('copy');

		$response = $this->controller->copyDomain(domainId: 'dom-vth');

		self::assertSame(expected: Http::STATUS_BAD_REQUEST, actual: $response->getStatus());
	}//end testADomainCopyWithNoNameIsRefused()

	/**
	 * Deleting a step that is in use is a conflict that names the case type.
	 *
	 * @return void
	 */
	public function testDeletingAStepInUseNamesTheCaseType(): void {
		$this->steps->method('delete')->willReturn(['ok' => false, 'reason' => 'in_use', 'usedBy' => 'Bezwaar']);

		$response = $this->controller->deleteStep(stepId: 'step-1');

		self::assertSame(expected: Http::STATUS_CONFLICT, actual: $response->getStatus());
		self::assertSame(expected: 'Bezwaar', actual: $response->getData()['usedBy']);
	}//end testDeletingAStepInUseNamesTheCaseType()

	/**
	 * The case types using a step come back as a counted list.
	 *
	 * @return void
	 */
	public function testStepUsedByComesBackAsACountedList(): void {
		$this->steps->method('usedBy')->willReturn([['id' => 'ct-1', 'title' => 'Bezwaar']]);

		$data = $this->controller->stepUsedBy(stepId: 'step-1')->getData();

		self::assertSame(expected: 1, actual: $data['total']);
		self::assertSame(expected: 'Bezwaar', actual: $data['items'][0]['title']);
	}//end testStepUsedByComesBackAsACountedList()

}//end class
