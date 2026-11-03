<?php

/**
 * Task stub test
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Support
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

namespace OCA\Dossiq\Tests\Unit\Support;

use JsonSerializable;
use OCA\OpenRegister\Db\Task;
use OCA\OpenRegister\Db\TaskStubContract;
use OCA\OpenRegister\Kernel\Contracts\TaskInterface;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * The Task stub answers OpenRegister's kernel task contract, so an installed
 * OpenRegister's TaskService can declare it as its TaskInterface.
 *
 * @coversNothing
 */
class TaskStubTest extends TestCase {

	/**
	 * The stub is the Task this process loaded. Tests that load OpenRegister's
	 * own classes run in a process of their own, so the real entity here means
	 * one of them leaked into the shared process.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->assertStringEndsWith(
			suffix: 'tests/Stubs/Db/Task.php',
			string: (string)(new ReflectionClass(objectOrClass: Task::class))->getFileName(),
			message: 'OpenRegister\'s own Task entity is loaded in the shared process.'
		);
	}//end setUp()

	/**
	 * The stub is serialisable, and is the kernel contract when that loaded before it.
	 *
	 * Whether OpenRegister's contracts load is settled once, when the stub is
	 * declared; a test that registers OpenRegister later cannot change it.
	 *
	 * @return void
	 */
	public function testTheStubIsTheKernelContractWhenItLoadedFirst(): void {
		$contract = new ReflectionClass(objectOrClass: TaskStubContract::class);

		$this->assertInstanceOf(expected: JsonSerializable::class, actual: new Task());
		$this->assertTrue(condition: (new ReflectionClass(objectOrClass: Task::class))->implementsInterface(interface: TaskStubContract::class));
		if (in_array(needle: TaskInterface::class, haystack: $contract->getInterfaceNames(), strict: true) === true) {
			$this->assertInstanceOf(expected: TaskInterface::class, actual: new Task());
		}
	}//end testTheStubIsTheKernelContractWhenItLoadedFirst()

	/**
	 * Hydrate sets the four fields the stub keeps and ignores the rest; the terminal states are the entity's.
	 *
	 * @return void
	 */
	public function testHydrateSerialiseAndTerminalState(): void {
		$task = (new Task())->hydrate(object: ['uuid' => 't-1', 'runUuid' => 'r-1', 'nodeId' => 'n-1', 'state' => 'active', 'title' => 'x']);

		$this->assertSame(expected: ['uuid' => 't-1', 'runUuid' => 'r-1', 'nodeId' => 'n-1', 'state' => 'active'], actual: $task->jsonSerialize());
		$this->assertSame(expected: 'r-1', actual: $task->getRunUuid());
		$this->assertFalse(condition: $task->isInTerminalState());
		foreach (['completed', 'terminated', 'disabled'] as $state) {
			$this->assertTrue(condition: $task->hydrate(object: ['state' => $state])->isInTerminalState(), message: $state);
		}

		$this->assertSame(expected: 't-1', actual: $task->getUuid());
		$this->assertSame(expected: ['uuid' => null, 'runUuid' => null, 'nodeId' => null, 'state' => null], actual: (new Task())->jsonSerialize());
	}//end testHydrateSerialiseAndTerminalState()
}//end class
