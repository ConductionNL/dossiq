<?php

/**
 * Unit tests for AskPersonTaskStore: a created task is mirrored into the task
 * engine under its case and the acting user (none when the session has no
 * user), an unidentifiable task is refused, and a lookup returns the task or
 * null when the engine no longer holds it.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Flow;

use OCA\Dossiq\Flow\AskPersonTaskStore;
use OCA\Dossiq\Service\Task\EngineTaskGateway;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Flow\AskPersonTaskStore
 */
class AskPersonTaskStoreTest extends TestCase {

	private EngineTaskGateway&MockObject $engine;

	private IUserSession&MockObject $session;

	protected function setUp(): void {
		parent::setUp();
		$this->engine = $this->createMock(EngineTaskGateway::class);
		$this->session = $this->createMock(IUserSession::class);
	}//end setUp()

	private function store(): AskPersonTaskStore {
		return new AskPersonTaskStore($this->engine, $this->session);
	}//end store()

	private function signedInAs(string $uid): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->session->method('getUser')->willReturn($user);
	}//end signedInAs()

	public function testCreateMirrorsTheTaskUnderItsTrimmedCaseAndTheActingUser(): void {
		$this->signedInAs('alice');
		$task = ['case' => '  case-1 ', 'title' => 'Beoordeel aanvraag'];
		$this->engine->expects($this->once())->method('mirrorImport')
			->with($task, 'case-1', 'alice')
			->willReturn('task-9');

		$this->assertSame('task-9', $this->store()->create($task));
	}//end testCreateMirrorsTheTaskUnderItsTrimmedCaseAndTheActingUser()

	public function testWithoutASignedInUserTheTaskHasNoActor(): void {
		$this->session->method('getUser')->willReturn(null);
		$this->engine->expects($this->once())->method('mirrorImport')
			->with(['title' => 'x'], '', null)
			->willReturn('task-1');

		$this->assertSame('task-1', $this->store()->create(['title' => 'x']));
	}//end testWithoutASignedInUserTheTaskHasNoActor()

	public function testABlankUidCountsAsNoActor(): void {
		$this->signedInAs('   ');
		$this->engine->expects($this->once())->method('mirrorImport')
			->with(['case' => 'c'], 'c', null)
			->willReturn('task-2');

		$this->store()->create(['case' => 'c']);
	}//end testABlankUidCountsAsNoActor()

	public function testATaskTheEngineCannotIdentifyIsRefused(): void {
		$this->signedInAs('alice');
		$this->engine->method('mirrorImport')->willReturn('');

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('dossiq.askPerson could not identify the task it created');
		$this->store()->create(['case' => 'c']);
	}//end testATaskTheEngineCannotIdentifyIsRefused()

	public function testFindReturnsTheEnginesTask(): void {
		$this->engine->expects($this->once())->method('find')->with('task-3')
			->willReturn(['id' => 'task-3', 'status' => 'open']);

		$this->assertSame(['id' => 'task-3', 'status' => 'open'], $this->store()->find('task-3'));
	}//end testFindReturnsTheEnginesTask()

	public function testFindReturnsNullWhenTheEngineHasNoSuchTask(): void {
		$this->engine->method('find')->willReturn(null);

		$this->assertNull($this->store()->find('task-4'));
	}//end testFindReturnsNullWhenTheEngineHasNoSuchTask()

	public function testFindReturnsNullWhenTheTaskWasDeleted(): void {
		$this->engine->method('find')->willThrowException(new DoesNotExistException('gone'));

		$this->assertNull($this->store()->find('task-5'));
	}//end testFindReturnsNullWhenTheTaskWasDeleted()

	public function testOtherLookupFailuresPropagate(): void {
		$this->engine->method('find')->willThrowException(new RuntimeException('engine down'));

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('engine down');
		$this->store()->find('task-6');
	}//end testOtherLookupFailuresPropagate()
}//end class
