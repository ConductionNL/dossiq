<?php

/**
 * Unit tests for ActionHandlerRegistry: the four built-in action types are
 * registered under their declared names, and a registered handler replaces
 * or extends them.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Transitions;

use OCA\Dossiq\Service\Transitions\ActionHandlerInterface;
use OCA\Dossiq\Service\Transitions\ActionHandlerRegistry;
use OCA\Dossiq\Service\Transitions\BesluitvormingPublishHandler;
use OCA\Dossiq\Service\Transitions\CreateSubCaseHandler;
use OCA\Dossiq\Service\Transitions\CreateTaskHandler;
use OCA\Dossiq\Service\Transitions\ResumeTermHandler;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\Transitions\ActionHandlerRegistry
 */
class ActionHandlerRegistryTest extends TestCase {

	private CreateTaskHandler $createTask;

	private CreateSubCaseHandler $createSubCase;

	private BesluitvormingPublishHandler $publish;

	private ResumeTermHandler $resume;

	private ActionHandlerRegistry $registry;

	protected function setUp(): void {
		parent::setUp();
		$this->createTask = $this->createMock(CreateTaskHandler::class);
		$this->createSubCase = $this->createMock(CreateSubCaseHandler::class);
		$this->publish = $this->createMock(BesluitvormingPublishHandler::class);
		$this->resume = $this->createMock(ResumeTermHandler::class);
		$this->registry = new ActionHandlerRegistry($this->createTask, $this->createSubCase, $this->publish, $this->resume);
	}//end setUp()

	public function testTheBuiltInTypesResolveToTheirHandlers(): void {
		$this->assertSame($this->createTask, $this->registry->getHandler('createTask'));
		$this->assertSame($this->createSubCase, $this->registry->getHandler('createSubCase'));
		$this->assertSame($this->publish, $this->registry->getHandler('besluitvormingPublish'));
		$this->assertSame($this->resume, $this->registry->getHandler('resumeTerm'));
		$this->assertSame(['createTask', 'createSubCase', 'besluitvormingPublish', 'resumeTerm'], $this->registry->getRegisteredTypes());
	}//end testTheBuiltInTypesResolveToTheirHandlers()

	public function testAnUnknownTypeHasNoHandler(): void {
		$this->assertNull($this->registry->getHandler('sendLetter'));
		$this->assertNull($this->registry->getHandler('createtask'));
	}//end testAnUnknownTypeHasNoHandler()

	public function testARegisteredHandlerIsAddedAfterTheBuiltIns(): void {
		$extra = $this->createMock(ActionHandlerInterface::class);

		$this->registry->registerHandler('sendLetter', $extra);

		$this->assertSame($extra, $this->registry->getHandler('sendLetter'));
		$this->assertSame(['createTask', 'createSubCase', 'besluitvormingPublish', 'resumeTerm', 'sendLetter'], $this->registry->getRegisteredTypes());
	}//end testARegisteredHandlerIsAddedAfterTheBuiltIns()

	public function testRegisteringABuiltInTypeReplacesItInPlace(): void {
		$replacement = $this->createMock(ActionHandlerInterface::class);

		$this->registry->registerHandler('createTask', $replacement);

		$this->assertSame($replacement, $this->registry->getHandler('createTask'));
		$this->assertSame(['createTask', 'createSubCase', 'besluitvormingPublish', 'resumeTerm'], $this->registry->getRegisteredTypes());
	}//end testRegisteringABuiltInTypeReplacesItInPlace()
}//end class
