<?php

/**
 * A step designed once, referenced by several case types.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service
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
 * @spec openspec/changes/starter-content-and-templates/specs/template-library/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Service\Starter\ReusableProcessStepService;
use OCA\Dossiq\Tests\Support\StarterStoreHarness;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Unit tests for ReusableProcessStepService.
 *
 * @covers \OCA\Dossiq\Service\Starter\ReusableProcessStepService
 *
 * @uses \OCA\Dossiq\Service\Starter\StarterStore
 */
class ReusableProcessStepTest extends TestCase {

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
	 * One step, referenced by two case types.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->harness = new StarterStoreHarness(test: $this);
		$this->steps = new ReusableProcessStepService($this->harness->store, new NullLogger());

		$this->harness->seed(
			schema: 'reusableStep',
			uuid: 'step-1',
			row: ['id' => 'step-1', 'name' => 'Ontvankelijkheidstoets', 'leadTimeDays' => 5],
		);
		$this->harness->seed(
			schema: 'caseType',
			uuid: 'ct-bezwaar',
			row: ['id' => 'ct-bezwaar', 'title' => 'Bezwaar', 'reusableSteps' => ['step-1']],
		);
		$this->harness->seed(
			schema: 'caseType',
			uuid: 'ct-klacht',
			row: ['id' => 'ct-klacht', 'title' => 'Klacht', 'reusableSteps' => ['step-1']],
		);
	}//end setUp()

	/**
	 * One change to the step reaches both case types, because they hold a
	 * reference rather than a copy.
	 *
	 * @return void
	 */
	public function testChangingTheStepReachesEveryCaseTypeThatUsesIt(): void {
		$step = $this->harness->register->row(schema: 'reusableStep', uuid: 'step-1');
		$step['leadTimeDays'] = 10;
		$this->harness->seed(schema: 'reusableStep', uuid: 'step-1', row: $step);

		foreach (['ct-bezwaar', 'ct-klacht'] as $caseTypeId) {
			$steps = $this->steps->stepsOf(caseTypeId: $caseTypeId);

			self::assertNotNull(actual: $steps);
			self::assertCount(expectedCount: 1, haystack: $steps);
			self::assertSame(expected: 10, actual: $steps[0]['leadTimeDays'], message: $caseTypeId . ' kept the old lead time');
		}
	}//end testChangingTheStepReachesEveryCaseTypeThatUsesIt()

	/**
	 * A step names both case types that use it.
	 *
	 * @return void
	 */
	public function testAReusableStepNamesItsUsers(): void {
		$users = $this->steps->usedBy(stepId: 'step-1');

		self::assertNotNull(actual: $users);
		self::assertSame(
			expected: ['Bezwaar', 'Klacht'],
			actual: array_column($users, 'title')
		);
	}//end testAReusableStepNamesItsUsers()

	/**
	 * A step in use is not deleted, and the refusal names a case type.
	 *
	 * @return void
	 */
	public function testAStepInUseIsNotDeleted(): void {
		$result = $this->steps->delete(stepId: 'step-1');

		self::assertFalse(condition: $result['ok']);
		self::assertSame(expected: 'in_use', actual: $result['reason']);
		self::assertSame(expected: 'Bezwaar', actual: $result['usedBy']);
		self::assertNotSame(expected: [], actual: $this->harness->register->row(schema: 'reusableStep', uuid: 'step-1'));
	}//end testAStepInUseIsNotDeleted()

	/**
	 * A step nobody uses is deleted.
	 *
	 * @return void
	 */
	public function testAStepNobodyUsesIsDeleted(): void {
		$this->harness->seed(schema: 'reusableStep', uuid: 'step-2', row: ['id' => 'step-2', 'name' => 'Hoorzitting']);

		$result = $this->steps->delete(stepId: 'step-2');

		self::assertTrue(condition: $result['ok']);
		self::assertSame(expected: [], actual: $this->harness->register->row(schema: 'reusableStep', uuid: 'step-2'));
	}//end testAStepNobodyUsesIsDeleted()

	/**
	 * Attaching a step twice leaves one reference.
	 *
	 * @return void
	 */
	public function testAttachingTheSameStepTwiceLeavesOneReference(): void {
		self::assertTrue(condition: $this->steps->attach(caseTypeId: 'ct-bezwaar', stepId: 'step-1'));

		self::assertSame(
			expected: ['step-1'],
			actual: $this->harness->register->row(schema: 'caseType', uuid: 'ct-bezwaar')['reusableSteps']
		);
	}//end testAttachingTheSameStepTwiceLeavesOneReference()

	/**
	 * A case type is not pointed at a step that does not exist.
	 *
	 * A dangling reference reads as a step the case type uses, and
	 * {@see ReusableProcessStepService::stepsOf()} would quietly answer with
	 * one fewer step than the case type declares.
	 *
	 * @return void
	 */
	public function testACaseTypeIsNotPointedAtAStepThatDoesNotExist(): void {
		self::assertFalse(condition: $this->steps->attach(caseTypeId: 'ct-bezwaar', stepId: 'step-nowhere'));
	}//end testACaseTypeIsNotPointedAtAStepThatDoesNotExist()
}//end class
