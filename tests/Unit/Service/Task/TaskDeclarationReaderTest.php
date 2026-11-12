<?php

/**
 * TaskDeclarationReader: which block a task on a case was created under.
 *
 * The reader resolves the case's OWN bound workflow, so the definition
 * service is asked for the case and never for the case type. These tests pin
 * the three answers it can give: no case, no definition (the unconfigured
 * default) and a definition whose matching step's block is read — matched by
 * title, and by status only when the step names one.
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
 *
 * @spec openspec/specs/process-step-configuration/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Task;

use OCA\Dossiq\Service\Task\TaskDeclaration;
use OCA\Dossiq\Service\Task\TaskDeclarationReader;
use OCA\Dossiq\Service\Workflow\WorkflowJsonProperty;
use OCA\Dossiq\Service\WorkflowDefinitionService;
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
	 * A reader whose definition service answers the given definition.
	 *
	 * @param WorkflowDefinitionService $definitions The definition service double.
	 *
	 * @return TaskDeclarationReader The reader.
	 */
	private function reader(WorkflowDefinitionService $definitions): TaskDeclarationReader {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')
			->with(WorkflowDefinitionService::class)
			->willReturn($definitions);

		return new TaskDeclarationReader(
			container: $container,
			json: new WorkflowJsonProperty(),
			declaration: new TaskDeclaration()
		);
	}//end reader()

	/**
	 * A definition service that answers one definition for one case.
	 *
	 * @param string                    $caseId     The case it expects.
	 * @param array<string, mixed>|null $definition The definition to answer.
	 *
	 * @return WorkflowDefinitionService The double.
	 */
	private function definitions(string $caseId, ?array $definition): WorkflowDefinitionService {
		$definitions = $this->createMock(WorkflowDefinitionService::class);
		$definitions->expects($this->once())
			->method('getDefinitionForCase')
			->with($caseId)
			->willReturn($definition);

		return $definitions;
	}//end definitions()

	/**
	 * A definition row whose steps are stored as a JSON string.
	 *
	 * @return array<string, mixed> The row.
	 */
	private static function definition(): array {
		return [
			'steps' => json_encode(
				[
					[
						'title' => 'Hoor de belanghebbende',
						'status' => 'status-2',
						'task' => ['leadTimeDays' => 10, 'candidateGroups' => ['Juridische Zaken']],
					],
					[
						'title' => 'Beoordeel de aanvraag',
						'task' => ['leadTimeDays' => 3],
					],
				]
			),
		];
	}//end definition()

	/**
	 * The case's own definition is asked for, with the id trimmed, and its steps decoded.
	 *
	 * @return void
	 */
	public function testTheStepsAreTheCasesBoundWorkflow(): void {
		$steps = $this->reader($this->definitions('case-1', self::definition()))->stepsForCase(' case-1 ');

		self::assertCount(2, $steps);
		self::assertSame('Hoor de belanghebbende', $steps[0]['title']);
		self::assertSame('Beoordeel de aanvraag', $steps[1]['title']);
	}//end testTheStepsAreTheCasesBoundWorkflow()

	/**
	 * A blank case never reaches the definition service.
	 *
	 * @return void
	 */
	public function testABlankCaseHasNoSteps(): void {
		$definitions = $this->createMock(WorkflowDefinitionService::class);
		$definitions->expects($this->never())->method('getDefinitionForCase');

		self::assertSame([], $this->reader($definitions)->stepsForCase('   '));
	}//end testABlankCaseHasNoSteps()

	/**
	 * A case type that declares no workflow keeps the unconfigured default.
	 *
	 * @return void
	 */
	public function testNoDefinitionMeansTheUnconfiguredDefault(): void {
		$reader = $this->reader($this->definitions('case-1', null));

		self::assertSame(TaskDeclaration::NONE, $reader->forTask('case-1', 'status-2', 'Hoor de belanghebbende'));
	}//end testNoDefinitionMeansTheUnconfiguredDefault()

	/**
	 * A definition that cannot be read refuses, rather than reading as unconfigured.
	 *
	 * @return void
	 */
	public function testAStorageFailureTravelsUp(): void {
		$definitions = $this->createMock(WorkflowDefinitionService::class);
		$definitions->method('getDefinitionForCase')->willThrowException(new RuntimeException('database gone'));

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('database gone');

		$this->reader($definitions)->forTask('case-1', 'status-2', 'Hoor de belanghebbende');
	}//end testAStorageFailureTravelsUp()

	/**
	 * The matching step's block is the declaration, matched on title and status.
	 *
	 * @return void
	 */
	public function testTheMatchingStepsBlockIsRead(): void {
		$declaration = $this->reader($this->definitions('case-1', self::definition()))
			->forTask('case-1', 'status-2', ' Hoor de belanghebbende ');

		self::assertSame(10, $declaration['leadTimeDays']);
		self::assertSame(['Juridische Zaken'], $declaration['candidateGroups']);
	}//end testTheMatchingStepsBlockIsRead()

	/**
	 * A step that names a different status does not match on the title alone.
	 *
	 * @return void
	 */
	public function testAStepOfAnotherStatusDoesNotMatch(): void {
		$declaration = $this->reader($this->definitions('case-1', self::definition()))
			->forTask('case-1', 'status-9', 'Hoor de belanghebbende');

		self::assertSame(TaskDeclaration::NONE, $declaration);
	}//end testAStepOfAnotherStatusDoesNotMatch()

	/**
	 * A step with no status belongs to the whole process and matches under any status.
	 *
	 * @return void
	 */
	public function testAStepWithoutAStatusMatchesAnyStatus(): void {
		$declaration = $this->reader($this->definitions('case-1', self::definition()))
			->forTask('case-1', 'status-7', 'Beoordeel de aanvraag');

		self::assertSame(3, $declaration['leadTimeDays']);
	}//end testAStepWithoutAStatusMatchesAnyStatus()

	/**
	 * A non-array entry among the steps is skipped, not fatal.
	 *
	 * @return void
	 */
	public function testMatchingSkipsANonArrayStep(): void {
		$reader = $this->reader($this->createMock(WorkflowDefinitionService::class));

		$step = $reader->matching(
			steps: ['not a step', ['title' => 'Beoordeel', 'status' => 'status-1']],
			statusTypeId: ' status-1 ',
			title: 'Beoordeel'
		);

		self::assertSame(['title' => 'Beoordeel', 'status' => 'status-1'], $step);
		self::assertNull($reader->matching(steps: ['not a step'], statusTypeId: 'status-1', title: 'Beoordeel'));
	}//end testMatchingSkipsANonArrayStep()
}//end class
