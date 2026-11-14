<?php

/**
 * Reusable process steps at their edges: missing rows, odd shapes, refusals.
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

use OCA\Dossiq\Service\Starter\ReusableProcessStepService;
use OCA\Dossiq\Service\Starter\StarterStore;
use OCA\Dossiq\Service\Support\LanguageMapText;
use OCA\Dossiq\Tests\Support\StarterStoreHarness;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Tests for ReusableProcessStepService's refusals and odd shapes.
 */
#[CoversClass(ReusableProcessStepService::class)]
#[UsesClass(StarterStore::class)]
#[UsesClass(LanguageMapText::class)]
final class ReusableProcessStepEdgesTest extends TestCase {

	/**
	 * The store and its rows.
	 *
	 * @var StarterStoreHarness
	 */
	private StarterStoreHarness $harness;

	/**
	 * The service under test.
	 *
	 * @var ReusableProcessStepService
	 */
	private ReusableProcessStepService $steps;

	/**
	 * Build the service over a real in-memory store with one step.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->harness = new StarterStoreHarness(test: $this);
		$this->steps = new ReusableProcessStepService($this->harness->store, new NullLogger());
		$this->harness->seed(schema: 'reusableStep', uuid: 'step-1', row: ['id' => 'step-1', 'title' => 'Advies']);
	}//end setUp()

	/**
	 * A case type that is not there has no steps to name: null, not an empty list.
	 *
	 * @return void
	 */
	public function testAnUnknownCaseTypeHasNoStepList(): void {
		self::assertNull(actual: $this->steps->stepsOf(caseTypeId: 'ct-missing'));
	}//end testAnUnknownCaseTypeHasNoStepList()

	/**
	 * A step list that is not a list reads as none, and a missing step is skipped.
	 *
	 * @return void
	 */
	public function testAnOddOrDanglingStepListIsReadSafely(): void {
		$this->harness->seed(schema: 'caseType', uuid: 'ct-odd', row: ['id' => 'ct-odd', 'reusableSteps' => 'step-1']);
		$this->harness->seed(schema: 'caseType', uuid: 'ct-1', row: ['id' => 'ct-1', 'reusableSteps' => ['gone', 'step-1']]);

		self::assertSame(expected: [], actual: $this->steps->stepsOf(caseTypeId: 'ct-odd'));

		$steps = $this->steps->stepsOf(caseTypeId: 'ct-1');
		self::assertCount(expectedCount: 1, haystack: $steps);
		self::assertSame(expected: 'Advies', actual: $steps[0]['title']);
	}//end testAnOddOrDanglingStepListIsReadSafely()

	/**
	 * Use is counted once per case type, and a case type with an odd list is skipped.
	 *
	 * @return void
	 */
	public function testUsedByNamesEachCaseTypeOnce(): void {
		$this->harness->seed(schema: 'caseType', uuid: 'ct-odd', row: ['id' => 'ct-odd', 'title' => 'Odd', 'reusableSteps' => 'step-1']);
		$this->harness->seed(
			schema: 'caseType',
			uuid: 'ct-1',
			row: ['id' => 'ct-1', 'title' => 'Bezwaar', 'reusableSteps' => ['step-1', 'step-1']],
		);
		$this->harness->seed(schema: 'caseType', uuid: 'ct-2', row: ['id' => 'ct-2', 'title' => 'Melding', 'reusableSteps' => ['step-2']]);

		self::assertSame(expected: [['id' => 'ct-1', 'title' => 'Bezwaar']], actual: $this->steps->usedBy(stepId: 'step-1'));
	}//end testUsedByNamesEachCaseTypeOnce()

	/**
	 * Without a configured case type schema, use is unknown, and a delete is refused as unavailable.
	 *
	 * @return void
	 */
	public function testAnUnreachableCaseTypeStoreRefusesTheDelete(): void {
		$harness = new StarterStoreHarness(test: $this, unconfigured: [ReusableProcessStepService::CASE_TYPES]);
		$harness->seed(schema: 'reusableStep', uuid: 'step-1', row: ['id' => 'step-1']);
		$steps = new ReusableProcessStepService($harness->store, new NullLogger());

		self::assertNull(actual: $steps->usedBy(stepId: 'step-1'));
		self::assertSame(
			expected: ['ok' => false, 'reason' => 'unavailable', 'usedBy' => ''],
			actual: $steps->delete(stepId: 'step-1')
		);
		self::assertSame(expected: ['id' => 'step-1'], actual: $harness->register->row(schema: 'reusableStep', uuid: 'step-1'));
	}//end testAnUnreachableCaseTypeStoreRefusesTheDelete()

	/**
	 * A step in use is not deleted, and the refusal names the first user.
	 *
	 * @return void
	 */
	public function testAStepInUseIsNotDeleted(): void {
		$this->harness->seed(schema: 'caseType', uuid: 'ct-1', row: ['id' => 'ct-1', 'title' => 'Bezwaar', 'reusableSteps' => ['step-1']]);

		self::assertSame(
			expected: ['ok' => false, 'reason' => 'in_use', 'usedBy' => 'Bezwaar'],
			actual: $this->steps->delete(stepId: 'step-1')
		);
	}//end testAStepInUseIsNotDeleted()

	/**
	 * Deleting a step that is not there answers not_found; an unused one is deleted and logged.
	 *
	 * @return void
	 */
	public function testDeletingAMissingStepIsNotFoundAndAnUnusedOneIsLogged(): void {
		self::assertSame(
			expected: ['ok' => false, 'reason' => 'not_found', 'usedBy' => ''],
			actual: $this->steps->delete(stepId: 'step-missing')
		);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('info')->with('Dossiq starter: a reusable step was deleted', ['step' => 'step-1']);
		$steps = new ReusableProcessStepService($this->harness->store, $logger);

		self::assertSame(expected: ['ok' => true, 'reason' => '', 'usedBy' => ''], actual: $steps->delete(stepId: 'step-1'));
		self::assertSame(expected: [], actual: $this->harness->register->all(schema: 'reusableStep'));
	}//end testDeletingAMissingStepIsNotFoundAndAnUnusedOneIsLogged()

	/**
	 * Attaching to a missing case type or a missing step is refused and writes nothing.
	 *
	 * @return void
	 */
	public function testAttachingSomethingMissingIsRefused(): void {
		$this->harness->seed(schema: 'caseType', uuid: 'ct-1', row: ['id' => 'ct-1']);

		self::assertFalse(condition: $this->steps->attach(caseTypeId: 'ct-missing', stepId: 'step-1'));
		self::assertFalse(condition: $this->steps->attach(caseTypeId: 'ct-1', stepId: 'step-missing'));
		self::assertArrayNotHasKey(key: 'reusableSteps', array: $this->harness->register->row(schema: 'caseType', uuid: 'ct-1'));
	}//end testAttachingSomethingMissingIsRefused()

	/**
	 * An odd step list is replaced on attach; attaching again keeps one entry.
	 *
	 * @return void
	 */
	public function testAttachReplacesAnOddListAndIsIdempotent(): void {
		$this->harness->seed(schema: 'caseType', uuid: 'ct-1', row: ['id' => 'ct-1', 'reusableSteps' => 'nonsense']);

		self::assertTrue(condition: $this->steps->attach(caseTypeId: 'ct-1', stepId: 'step-1'));
		self::assertSame(expected: ['step-1'], actual: $this->harness->register->row(schema: 'caseType', uuid: 'ct-1')['reusableSteps']);

		self::assertTrue(condition: $this->steps->attach(caseTypeId: 'ct-1', stepId: 'step-1'));
		self::assertSame(expected: ['step-1'], actual: $this->harness->register->row(schema: 'caseType', uuid: 'ct-1')['reusableSteps']);
	}//end testAttachReplacesAnOddListAndIsIdempotent()
}//end class
