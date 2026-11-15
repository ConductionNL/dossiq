<?php

/**
 * Restoring a case type, and a write that does not land.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Starter
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

namespace OCA\Dossiq\Tests\Unit\Service\Starter;

use OCA\Dossiq\Service\CaseType\CaseTypeLifecycleState;
use OCA\Dossiq\Service\Starter\CaseTypeRetirementService;
use OCA\Dossiq\Service\Starter\StarterStore;
use OCA\Dossiq\Tests\Support\StarterStoreHarness;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Tests for CaseTypeRetirementService's refusals.
 */
#[CoversClass(CaseTypeRetirementService::class)]
#[UsesClass(CaseTypeLifecycleState::class)]
#[UsesClass(StarterStore::class)]
final class CaseTypeRetirementRefusalsTest extends TestCase {

	/**
	 * The store and its rows.
	 *
	 * @var StarterStoreHarness
	 */
	private StarterStoreHarness $harness;

	/**
	 * The service under test, with nobody logged in.
	 *
	 * @var CaseTypeRetirementService
	 */
	private CaseTypeRetirementService $retirement;

	/**
	 * An active and a retired case type.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->harness = new StarterStoreHarness(test: $this);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn(null);
		$this->retirement = new CaseTypeRetirementService(
			$this->harness->store,
			new CaseTypeLifecycleState(),
			$session,
			new NullLogger(),
		);

		$this->harness->seed(schema: 'caseType', uuid: 'ct-active', row: ['id' => 'ct-active', 'isDraft' => false]);
		$this->harness->seed(
			schema: 'caseType',
			uuid: 'ct-retired',
			row: ['id' => 'ct-retired', 'isDraft' => false, 'validUntil' => '2020-01-01'],
		);
	}//end setUp()

	/**
	 * Restoring a case type that is not there is refused as not_found.
	 *
	 * @return void
	 */
	public function testRestoringAnUnknownCaseTypeIsNotFound(): void {
		self::assertSame(
			expected: ['ok' => false, 'reason' => 'not_found', 'state' => ''],
			actual: $this->retirement->restore(caseTypeId: 'ct-missing')
		);
	}//end testRestoringAnUnknownCaseTypeIsNotFound()

	/**
	 * Restoring a case type that is not retired is refused, naming its state, and writes nothing.
	 *
	 * @return void
	 */
	public function testRestoringAnActiveCaseTypeIsRefused(): void {
		$writes = $this->harness->register->writes;

		self::assertSame(
			expected: ['ok' => false, 'reason' => 'not_retired', 'state' => CaseTypeLifecycleState::IN_USE],
			actual: $this->retirement->restore(caseTypeId: 'ct-active')
		);
		self::assertSame(expected: $writes, actual: $this->harness->register->writes);
	}//end testRestoringAnActiveCaseTypeIsRefused()

	/**
	 * A restore whose write fails is answered write_failed, and the type stays retired.
	 *
	 * @return void
	 */
	public function testARestoreThatCannotBeWrittenIsWriteFailed(): void {
		$this->harness->register->refusedSchemas = ['caseType'];

		self::assertSame(
			expected: ['ok' => false, 'reason' => 'write_failed', 'state' => ''],
			actual: $this->retirement->restore(caseTypeId: 'ct-retired')
		);
		self::assertSame(expected: '2020-01-01', actual: $this->harness->register->row(schema: 'caseType', uuid: 'ct-retired')['validUntil']);
	}//end testARestoreThatCannotBeWrittenIsWriteFailed()

	/**
	 * With nobody logged in, the act is recorded with an empty actor.
	 *
	 * @return void
	 */
	public function testAnActWithNoUserRecordsAnEmptyActor(): void {
		$result = $this->retirement->restore(caseTypeId: 'ct-retired');

		self::assertTrue(condition: $result['ok']);
		$row = $this->harness->register->row(schema: 'caseType', uuid: 'ct-retired');
		self::assertSame(expected: 'restored', actual: $row['lifecycleAct']);
		self::assertSame(expected: '', actual: $row['lifecycleActBy']);
		self::assertNull(actual: $row['validUntil']);
	}//end testAnActWithNoUserRecordsAnEmptyActor()
}//end class
