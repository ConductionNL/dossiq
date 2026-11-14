<?php

/**
 * Unit tests for the WorkflowLifecycleGuard status helpers: a definition's
 * lifecycle status and route, the case type's pinned default, which active
 * definition is the default, and when a published definition may be
 * deprecated.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Workflow;

use OCA\Dossiq\Service\Workflow\WorkflowDefinitionRepository;
use OCA\Dossiq\Service\Workflow\WorkflowLifecycleGuard;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Dossiq\Service\Workflow\WorkflowLifecycleGuard
 */
class WorkflowLifecycleGuardStatusTest extends TestCase {

	private WorkflowDefinitionRepository&MockObject $repository;

	private LoggerInterface&MockObject $logger;

	protected function setUp(): void {
		parent::setUp();
		$this->repository = $this->createMock(WorkflowDefinitionRepository::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	private function guard(): WorkflowLifecycleGuard {
		return new WorkflowLifecycleGuard($this->repository, $this->logger);
	}//end guard()

	public function testAnExplicitStatusWinsAndTheFlagsDecideOtherwise(): void {
		$guard = $this->guard();

		$this->assertSame('deprecated', $guard->statusOf(['lifecycleStatus' => 'deprecated', 'isDraft' => true]));
		$this->assertSame('published', $guard->statusOf(['lifecycleStatus' => 'published']));
		$this->assertSame('draft', $guard->statusOf(['lifecycleStatus' => 'archived']));
		$this->assertSame('draft', $guard->statusOf([]));
		$this->assertSame('published', $guard->statusOf(['isDraft' => false, 'isActive' => true]));
		$this->assertSame('deprecated', $guard->statusOf(['isDraft' => false, 'isActive' => false]));
		$this->assertSame('deprecated', $guard->statusOf(['isDraft' => 0]));
	}//end testAnExplicitStatusWinsAndTheFlagsDecideOtherwise()

	public function testABlankVariantIsTheStandardRoute(): void {
		$guard = $this->guard();

		$this->assertSame('standaard', $guard->variantOf([]));
		$this->assertSame('standaard', $guard->variantOf(['variant' => '  ']));
		$this->assertSame('spoed', $guard->variantOf(['variant' => ' spoed ']));
	}//end testABlankVariantIsTheStandardRoute()

	public function testThePinnedDefaultIsReadFromTheCaseType(): void {
		$this->repository->method('findCaseType')->willReturnCallback(
			static fn (string $id): ?array => [
				'ct-id' => ['workflowDefinition' => 'wf-1'],
				'ct-object' => ['workflowDefinition' => ['uuid' => 'wf-2']],
				'ct-none' => [],
			][$id] ?? null
		);
		$guard = $this->guard();

		$this->assertSame('wf-1', $guard->defaultDefinitionIdFor('ct-id'));
		$this->assertSame('wf-2', $guard->defaultDefinitionIdFor('ct-object'));
		$this->assertSame('', $guard->defaultDefinitionIdFor('ct-none'));
		$this->assertSame('', $guard->defaultDefinitionIdFor('ct-missing'));
	}//end testThePinnedDefaultIsReadFromTheCaseType()

	public function testNoCaseTypeIsNotLookedUp(): void {
		$this->repository->expects($this->never())->method('findCaseType');

		$this->assertSame('', $this->guard()->defaultDefinitionIdFor(''));
	}//end testNoCaseTypeIsNotLookedUp()

	public function testASingleActiveDefinitionIsTheDefaultWithoutALookup(): void {
		$this->repository->expects($this->never())->method('findCaseType');

		$this->assertSame(['id' => 'wf-1'], $this->guard()->defaultAmong([['id' => 'wf-1']], 'ct-1'));
	}//end testASingleActiveDefinitionIsTheDefaultWithoutALookup()

	public function testThePinnedDefinitionWinsAmongSeveral(): void {
		$this->repository->method('findCaseType')->willReturn(['workflowDefinition' => 'wf-2']);
		$this->logger->expects($this->never())->method('warning');

		$this->assertSame(
			['id' => 'wf-2', 'variant' => 'spoed'],
			$this->guard()->defaultAmong([['id' => 'wf-1', 'variant' => 'aanvraag'], ['id' => 'wf-2', 'variant' => 'spoed']], 'ct-1')
		);
	}//end testThePinnedDefinitionWinsAmongSeveral()

	public function testWithoutAUsablePinTheFirstRouteAtItsNewestVersionIsChosenAndReported(): void {
		$this->repository->method('findCaseType')->willReturn(['workflowDefinition' => 'wf-gone']);
		$this->logger->expects($this->once())->method('warning')->with(
			'Dossiq: case type has several active workflow definitions and no usable default route',
			['app' => 'dossiq', 'caseType' => 'ct-1', 'routes' => ['aanvraag', 'aanvraag', 'standaard'], 'chose' => 'wf-3']
		);

		$chosen = $this->guard()->defaultAmong(
			[
				['id' => 'wf-1', 'version' => 4],
				['id' => 'wf-2', 'variant' => 'aanvraag', 'version' => 1],
				['id' => 'wf-3', 'variant' => 'aanvraag', 'version' => 2],
			],
			'ct-1'
		);

		$this->assertSame('wf-3', $chosen['id']);
	}//end testWithoutAUsablePinTheFirstRouteAtItsNewestVersionIsChosenAndReported()

	public function testOnlyAPublishedDefinitionCanBeDeprecated(): void {
		$this->logger->expects($this->once())->method('warning')->with('Dossiq: deprecate() — definition is not published', ['app' => 'dossiq', 'id' => 'wf-1']);

		$this->assertFalse($this->guard()->isDeprecatable(['lifecycleStatus' => 'draft'], 'wf-1'));
	}//end testOnlyAPublishedDefinitionCanBeDeprecated()

	public function testTheLastPublishedDefinitionWithCasesCannotBeDeprecated(): void {
		$this->repository->method('listVersionsForCaseType')->with('ct-1')->willReturn(
			[['id' => 'wf-1', 'lifecycleStatus' => 'published'], ['id' => 'wf-0', 'lifecycleStatus' => 'deprecated']]
		);
		$this->repository->method('hasCasesFor')->with('ct-1')->willReturn(true);
		$this->logger->expects($this->once())->method('warning')->with(
			'Dossiq: deprecate() — last published definition with open cases',
			['app' => 'dossiq', 'id' => 'wf-1', 'caseType' => 'ct-1']
		);

		$this->assertFalse($this->guard()->isDeprecatable(['lifecycleStatus' => 'published', 'caseType' => 'ct-1'], 'wf-1'));
	}//end testTheLastPublishedDefinitionWithCasesCannotBeDeprecated()

	public function testAPublishedDefinitionCanGoWhenAnotherIsPublishedOrNoCasesRemain(): void {
		$this->repository->method('listVersionsForCaseType')->willReturnOnConsecutiveCalls(
			[['id' => 'wf-1', 'lifecycleStatus' => 'published'], ['id' => 'wf-2', 'isDraft' => false, 'isActive' => true]],
			[['id' => 'wf-1', 'lifecycleStatus' => 'published']]
		);
		$this->repository->method('hasCasesFor')->willReturn(false);
		$guard = $this->guard();
		$published = ['lifecycleStatus' => 'published', 'caseType' => 'ct-1'];

		$this->assertTrue($guard->isDeprecatable($published, 'wf-1'));
		$this->assertTrue($guard->isDeprecatable($published, 'wf-1'));
		$this->assertTrue($guard->isDeprecatable(['lifecycleStatus' => 'published'], 'wf-1'));
	}//end testAPublishedDefinitionCanGoWhenAnotherIsPublishedOrNoCasesRemain()
}//end class
