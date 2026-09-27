<?php

/**
 * The lifecycle guard resolves its task-declaration check lazily.
 *
 * 🔴 INJECTED, THE VALIDATOR CLOSED A CONSTRUCTOR CYCLE: WorkflowDefinitionService
 * needs the guard, the validator needs TaskDeclarationReader, and the reader
 * needs WorkflowDefinitionService. On PHP 8.3 with Nextcloud 32 the container
 * recursed until the stack ran out, so `app:enable dossiq` failed and took
 * every app that installs dossiq as a sibling down with it. These tests pin
 * the two halves of the fix: building the guard never asks the container for
 * anything, and a publish check still runs the validator and keeps its
 * refusals.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\Workflow
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Workflow;

use OCA\Dossiq\Service\Task\TaskDeclarationValidator;
use OCA\Dossiq\Service\Workflow\WorkflowDefinitionRepository;
use OCA\Dossiq\Service\Workflow\WorkflowLifecycleGuard;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use ReflectionMethod;

/**
 * Tests for the lazily resolved task-declaration check.
 *
 * @covers \OCA\Dossiq\Service\Workflow\WorkflowLifecycleGuard
 */
final class WorkflowLifecycleGuardLazyValidatorTest extends TestCase {

	/**
	 * A guard over this container.
	 *
	 * @param ContainerInterface $container The container the guard resolves from.
	 *
	 * @return WorkflowLifecycleGuard The guard under test.
	 */
	private function guard(ContainerInterface $container): WorkflowLifecycleGuard {
		return new WorkflowLifecycleGuard(
			repository: $this->createMock(originalClassName: WorkflowDefinitionRepository::class),
			logger: new NullLogger(),
			container: $container
		);
	}//end guard()

	/**
	 * Run the private publish-time check the way publish() does.
	 *
	 * @param WorkflowLifecycleGuard $guard      The guard.
	 * @param array<string, mixed>   $definition The definition being published.
	 *
	 * @return boolean Whether every task declaration resolves.
	 */
	private function check(WorkflowLifecycleGuard $guard, array $definition): bool {
		$method = new ReflectionMethod(objectOrMethod: $guard, method: 'taskDeclarationsResolve');

		return $method->invoke($guard, $definition, 'definition-1');
	}//end check()

	/**
	 * Building the guard asks the container for nothing.
	 *
	 * This is the whole cycle fix: a constructor that resolved the validator
	 * would rebuild WorkflowDefinitionService before it exists.
	 *
	 * @return void
	 */
	public function testBuildingTheGuardAsksTheContainerForNothing(): void {
		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->expects($this->never())->method('get');

		$this->guard(container: $container);
	}//end testBuildingTheGuardAsksTheContainerForNothing()

	/**
	 * A publish check resolves the validator once and keeps what it refused.
	 *
	 * @return void
	 */
	public function testAPublishCheckRunsTheValidatorAndKeepsItsRefusals(): void {
		$refusals = [['path' => 'tasks[0].form', 'code' => 'unknown-form', 'message' => 'No form named intake.']];

		$validator = $this->createMock(originalClassName: TaskDeclarationValidator::class);
		$validator->method('refusalsFor')->willReturn($refusals);

		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->expects($this->once())
			->method('get')
			->with(TaskDeclarationValidator::class)
			->willReturn($validator);

		$guard = $this->guard(container: $container);

		$this->assertFalse(condition: $this->check(guard: $guard, definition: ['tasks' => []]));
		$this->assertSame(expected: $refusals, actual: $guard->lastRefusals());

		// A second publish reuses the resolved validator.
		$this->assertFalse(condition: $this->check(guard: $guard, definition: ['tasks' => []]));
	}//end testAPublishCheckRunsTheValidatorAndKeepsItsRefusals()

	/**
	 * Without a container, as in the other unit tests, the check is skipped.
	 *
	 * @return void
	 */
	public function testWithoutAContainerTheCheckIsSkipped(): void {
		$guard = new WorkflowLifecycleGuard(
			repository: $this->createMock(originalClassName: WorkflowDefinitionRepository::class),
			logger: new NullLogger()
		);

		$this->assertTrue(condition: $this->check(guard: $guard, definition: ['tasks' => []]));
		$this->assertSame(expected: [], actual: $guard->lastRefusals());
	}//end testWithoutAContainerTheCheckIsSkipped()
}//end class
