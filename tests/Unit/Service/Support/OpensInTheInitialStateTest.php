<?php

/**
 * OpensInTheInitialState: create in the initial state, move only when needed, undo a refused move.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/bezwaar-hearing/spec.md#requirement-hearing-waiver-afzien-van-hoorrecht
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Support;

use OCA\Dossiq\Service\Support\OpensInTheInitialState;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCA\Dossiq\Tests\Support\LifecycleRegister;
use OCA\OpenRegister\Service\Object\ObjectDeletion;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Exposes the trait's protected seam to the test.
 */
class InitialStateHost {

	use SearchesObjects;
	use OpensInTheInitialState;

	/**
	 * Write one hearing session through the seam.
	 *
	 * @param object               $objectService The object service.
	 * @param array<string, mixed> $session       The session to write.
	 *
	 * @return array<string, mixed>
	 */
	public function write(object $objectService, array $session): array {
		return $this->createThenMove(
			objectService: $objectService,
			write: ['register' => 'dossiq', 'schema' => 'hearingSession', 'object' => $session, 'field' => 'status']
		);
	}
}//end class

/**
 * The create-then-move seam behind records born past their first state.
 *
 * @covers \OCA\Dossiq\Service\Support\OpensInTheInitialState
 * @uses \OCA\Dossiq\Service\Support\SearchesObjects
 */
class OpensInTheInitialStateTest extends TestCase {

	/**
	 * A record that names no state, or the initial one, is written once and not moved.
	 *
	 * @return void
	 */
	public function testARecordInTheInitialStateIsWrittenOnce(): void {
		$register = new LifecycleRegister();
		$host     = new InitialStateHost();

		$bare    = $host->write(objectService: $register, session: ['case' => 'case-1']);
		$planned = $host->write(objectService: $register, session: ['case' => 'case-2', 'status' => 'planned']);

		$this->assertSame('planned', $bare['status']);
		$this->assertSame('planned', $planned['status']);
		$this->assertSame(2, $register->writes, 'one write per record');
		$this->assertSame([], $register->moves);
	}

	/**
	 * A record born in a later state is created in the initial one and moved.
	 *
	 * @return void
	 */
	public function testARecordInALaterStateIsCreatedThenMoved(): void {
		$register = new LifecycleRegister();

		$saved = (new InitialStateHost())->write(objectService: $register, session: ['case' => 'case-1', 'status' => 'cancelled']);

		$this->assertSame('cancelled', $saved['status']);
		$this->assertSame('cancelled', $register->row('hearingSession', (string)$saved['id'])['status']);
		$this->assertSame(['hearingSession: planned -> cancelled'], $register->moves);
		$this->assertSame(1, $register->patches, 'the move goes through OpenRegister\'s patch');
	}

	/**
	 * A forged id in the record opens a new record and leaves the named one as it was.
	 *
	 * @return void
	 */
	public function testAForgedIdOnCreateDoesNotTouchTheNamedRecord(): void {
		$register = new LifecycleRegister();
		$register->seed(schema: 'hearingSession', uuid: 'victim-1', row: ['case' => 'other-case', 'status' => 'planned']);

		$saved = (new InitialStateHost())->write(
			objectService: $register,
			session: ['id' => 'victim-1', 'uuid' => 'victim-1', '@self' => ['id' => 'victim-1'], 'case' => 'case-1', 'status' => 'cancelled']
		);

		$this->assertNotSame('victim-1', $saved['id']);
		$this->assertSame(['case' => 'other-case', 'status' => 'planned', 'id' => 'victim-1'], $register->row('hearingSession', 'victim-1'));
		$this->assertSame('cancelled', $register->row('hearingSession', (string)$saved['id'])['status']);
		$this->assertArrayNotHasKey('uuid', $register->row('hearingSession', (string)$saved['id']));
	}

	/**
	 * The move and the delete run as the caller: a caller who may only create
	 * is refused both, so the record stays in its initial state and is named.
	 *
	 * @return void
	 */
	public function testTheMoveAndTheDeleteRunAsTheCaller(): void {
		$register = new LifecycleRegister();
		$register->callerMayOnlyCreate = ['hearingSession'];

		try {
			(new InitialStateHost())->write(objectService: $register, session: ['case' => 'case-1', 'status' => 'cancelled']);
			$this->fail('a caller who may not update the record must be refused the move');
		} catch (RuntimeException $refused) {
			$this->assertStringContainsString('may not update', $refused->getMessage());
			$this->assertStringContainsString('could not be deleted again: The caller may not delete', $refused->getMessage());
		}

		$this->assertSame([], $register->moves);
		$this->assertSame([], $register->deletes);
		$this->assertSame('planned', $register->all('hearingSession')[0]['status']);
	}

	/**
	 * A move no transition allows is refused, and the created record is deleted again.
	 *
	 * @return void
	 */
	public function testARefusedMoveDeletesTheCreatedRecord(): void {
		$register = new LifecycleRegister();

		try {
			(new InitialStateHost())->write(objectService: $register, session: ['case' => 'case-1', 'status' => 'executed']);
			$this->fail('a move no transition allows must be refused');
		} catch (RuntimeException $refused) {
			$this->assertStringContainsString('could not be moved into "executed"', $refused->getMessage());
			$this->assertStringNotContainsString('could not be deleted again', $refused->getMessage());
		}

		$this->assertSame([], $register->all('hearingSession'));
	}

	/**
	 * A record that cannot be deleted again is named in the refusal.
	 *
	 * @return void
	 */
	public function testARecordThatCannotBeDeletedIsNamed(): void {
		$register = new class () extends LifecycleRegister {
			/**
			 * Refuse every delete.
			 *
			 * @param int|string $register      The register.
			 * @param int|string $schema        The schema slug.
			 * @param string     $uuid          The uuid.
			 * @param bool       $_rbac         Ignored.
			 * @param bool       $_multitenancy Ignored.
			 *
			 * @return bool
			 */
			public function deleteObject(ObjectDeletion $deletion, bool $_rbac = true, bool $_multitenancy = true): bool {
				throw new RuntimeException('delete refused');
			}
		};

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('The created record generated-1 could not be deleted again: delete refused');
		(new InitialStateHost())->write(objectService: $register, session: ['case' => 'case-1', 'status' => 'executed']);
	}

	/**
	 * A create that answers no id cannot be moved, and is refused rather than returned in the wrong state.
	 *
	 * @return void
	 */
	public function testACreateWithoutAnIdIsRefused(): void {
		$objectService = new class () {
			/** @var array<int, array<string, mixed>> */
			public array $saved = [];

			/**
			 * Store nothing addressable.
			 *
			 * @param array<string, mixed> $object The object.
			 * @param mixed                ...$rest Ignored.
			 *
			 * @return array<string, mixed>
			 */
			public function saveObject(array $object, mixed ...$rest): array {
				$this->saved[] = $object;

				return $object;
			}
		};

		try {
			(new InitialStateHost())->write(objectService: $objectService, session: ['case' => 'case-1', 'status' => 'cancelled']);
			$this->fail('a record that cannot be moved must be refused');
		} catch (RuntimeException $refused) {
			$this->assertStringContainsString('answered no id', $refused->getMessage());
		}

		$this->assertSame([['case' => 'case-1']], $objectService->saved);
	}
}//end class
