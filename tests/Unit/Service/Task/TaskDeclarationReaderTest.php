<?php

/**
 * Reading the per-task block of a case's workflow definition.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Task
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

namespace OCA\Dossiq\Tests\Unit\Service\Task;

use OCA\Dossiq\Service\Task\TaskDeclaration;
use OCA\Dossiq\Service\Task\TaskDeclarationReader;
use OCA\Dossiq\Service\Workflow\WorkflowJsonProperty;
use OCA\Dossiq\Service\WorkflowDefinitionService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Service\Task\TaskDeclarationReader
 * @uses \OCA\Dossiq\Service\Task\TaskDeclaration
 * @uses \OCA\Dossiq\Service\Workflow\WorkflowJsonProperty
 */
class TaskDeclarationReaderTest extends TestCase {

	/**
	 * The workflow definition service the container hands out.
	 *
	 * @var WorkflowDefinitionService&MockObject
	 */
	private WorkflowDefinitionService&MockObject $definitions;

	/**
	 * The reader under test.
	 *
	 * @var TaskDeclarationReader
	 */
	private TaskDeclarationReader $reader;

	/**
	 * Build the reader on a container that serves the mocked definitions.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->definitions = $this->createMock(WorkflowDefinitionService::class);
		$container         = $this->createMock(ContainerInterface::class);
		$container->method('get')
			->with(WorkflowDefinitionService::class)
			->willReturn($this->definitions);

		$this->reader = new TaskDeclarationReader(
			container: $container,
			json: new WorkflowJsonProperty(),
			declaration: new TaskDeclaration()
		);
	}//end setUp()

	/**
	 * A blank case id never reaches the definition service.
	 *
	 * @return void
	 */
	public function testBlankCaseIdHasNoSteps(): void {
		$this->definitions->expects($this->never())->method('getDefinitionForCase');

		$this->assertSame(expected: [], actual: $this->reader->stepsForCase(caseId: '   '));
	}//end testBlankCaseIdHasNoSteps()

	/**
	 * A case without a workflow definition declares no steps.
	 *
	 * @return void
	 */
	public function testAbsentDefinitionHasNoSteps(): void {
		$this->definitions->expects($this->once())
			->method('getDefinitionForCase')
			->with(caseId: 'case-1')
			->willReturn(null);

		$this->assertSame(expected: [], actual: $this->reader->stepsForCase(caseId: ' case-1 '));
	}//end testAbsentDefinitionHasNoSteps()

	/**
	 * Steps stored as a JSON string are decoded, and non-array entries dropped.
	 *
	 * @return void
	 */
	public function testStepsAreDecodedFromTheDefinition(): void {
		$this->definitions->method('getDefinitionForCase')->willReturn(
			['steps' => json_encode([['title' => 'Beoordelen'], 'noise', ['title' => 'Besluiten']])]
		);

		$this->assertSame(
			expected: [['title' => 'Beoordelen'], ['title' => 'Besluiten']],
			actual: $this->reader->stepsForCase(caseId: 'case-1')
		);
	}//end testStepsAreDecodedFromTheDefinition()

	/**
	 * A storage failure travels up instead of becoming the unconfigured defaults.
	 *
	 * @return void
	 */
	public function testStorageFailureIsNotSwallowed(): void {
		$this->definitions->method('getDefinitionForCase')
			->willThrowException(new RuntimeException('storage down'));

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('storage down');

		$this->reader->forTask(caseId: 'case-1', statusTypeId: 's-1', title: 'Beoordelen');
	}//end testStorageFailureIsNotSwallowed()

	/**
	 * The matching step's block is normalised into the declaration.
	 *
	 * @return void
	 */
	public function testForTaskReadsTheMatchingStep(): void {
		$this->definitions->method('getDefinitionForCase')->willReturn(
			[
				'steps' => [
					['title' => 'Beoordelen', 'status' => 's-1', 'task' => ['leadTimeDays' => 3]],
					['title' => 'Beoordelen', 'status' => 's-2', 'task' => ['leadTimeDays' => 9, 'enabled' => false]],
				],
			]
		);

		$declaration = $this->reader->forTask(caseId: 'case-1', statusTypeId: 's-2', title: ' Beoordelen ');

		$this->assertFalse(condition: $declaration['enabled']);
		$this->assertSame(expected: 9, actual: $declaration['leadTimeDays']);
	}//end testForTaskReadsTheMatchingStep()

	/**
	 * A task no step names keeps the unconfigured defaults.
	 *
	 * @return void
	 */
	public function testForTaskWithoutAMatchIsTheDefault(): void {
		$this->definitions->method('getDefinitionForCase')->willReturn(
			['steps' => [['title' => 'Besluiten', 'task' => ['leadTimeDays' => 4]]]]
		);

		$this->assertSame(
			expected: TaskDeclaration::NONE,
			actual: $this->reader->forTask(caseId: 'case-1', statusTypeId: 's-1', title: 'Beoordelen')
		);
	}//end testForTaskWithoutAMatchIsTheDefault()

	/**
	 * A step without a status matches any status; a step with one matches only it.
	 *
	 * @return void
	 */
	public function testMatchingHonoursTheStatus(): void {
		$anyStatus = ['title' => 'Beoordelen'];
		$onlyS2    = ['title' => 'Beoordelen', 'status' => 's-2'];

		$this->assertSame(
			expected: $onlyS2,
			actual: $this->reader->matching(steps: ['x', $onlyS2, $anyStatus], statusTypeId: ' s-2 ', title: 'Beoordelen')
		);
		$this->assertSame(
			expected: $anyStatus,
			actual: $this->reader->matching(steps: [$onlyS2, $anyStatus], statusTypeId: 's-1', title: 'Beoordelen')
		);
		$this->assertNull(
			actual: $this->reader->matching(steps: [$onlyS2], statusTypeId: 's-1', title: 'Beoordelen')
		);
		$this->assertNull(
			actual: $this->reader->matching(steps: [$anyStatus], statusTypeId: 's-1', title: 'Besluiten')
		);
	}//end testMatchingHonoursTheStatus()

	/**
	 * A definition whose steps cannot be decoded has none.
	 *
	 * @return void
	 */
	public function testStepsOfUndecodableDefinition(): void {
		$this->assertSame(expected: [], actual: $this->reader->stepsOf(definition: ['steps' => '{not json']));
		$this->assertSame(expected: [], actual: $this->reader->stepsOf(definition: []));
	}//end testStepsOfUndecodableDefinition()
}//end class
