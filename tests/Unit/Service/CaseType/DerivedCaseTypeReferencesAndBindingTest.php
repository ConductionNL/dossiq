<?php

/**
 * Two small case-type helpers that run when a case type is derived or a case
 * moves: repointing a derived case type at its own copied children (and
 * clearing a workflow pin that cannot be mapped), and binding a case to the
 * workflow template of the case type it lands on.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/zaaktype-versioning/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\CaseType;

use OCA\Dossiq\Service\CaseType\DerivedCaseTypeReferences;
use OCA\Dossiq\Service\CaseType\WorkflowTemplateBinding;
use OCA\Dossiq\Service\CaseTypeStore;
use OCA\Dossiq\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Dossiq\Service\CaseType\DerivedCaseTypeReferences
 * @covers \OCA\Dossiq\Service\CaseType\WorkflowTemplateBinding
 * @uses   \OCA\Dossiq\Service\CaseTypeStore
 * @uses   \OCA\Dossiq\Service\Support\SerialisedResult
 */
class DerivedCaseTypeReferencesAndBindingTest extends TestCase {

	/**
	 * The saves made, as [object, register, schema].
	 *
	 * @var array<int, array{0: array, 1: string, 2: string}>
	 */
	public array $saves = [];

	/**
	 * An object service that records saves and answers with an id stamped,
	 * or throws when told to.
	 *
	 * @param \Throwable|null $failure Thrown on save when set.
	 *
	 * @return object
	 */
	private function objectService(?\Throwable $failure=null): object {
		$test = $this;
		return new class($test, $failure) {
			/**
			 * @param DerivedCaseTypeReferencesAndBindingTest $test    The test.
			 * @param \Throwable|null                         $failure Thrown on save when set.
			 */
			public function __construct(private DerivedCaseTypeReferencesAndBindingTest $test, private ?\Throwable $failure) {
			}

			/**
			 * @param array  $object   The object.
			 * @param string $register The register.
			 * @param string $schema   The schema.
			 *
			 * @return array
			 */
			public function saveObject(array $object, string $register, string $schema): array {
				$this->test->saves[] = [$object, $register, $schema];
				if ($this->failure !== null) {
					throw $this->failure;
				}

				return $object + ['saved' => true];
			}
		};
	}//end objectService()

	/**
	 * The references service.
	 *
	 * @param LoggerInterface|null $logger The logger.
	 *
	 * @return DerivedCaseTypeReferences
	 */
	private function references(?LoggerInterface $logger=null): DerivedCaseTypeReferences {
		return new DerivedCaseTypeReferences(
			store: new CaseTypeStore(settingsService: $this->createMock(SettingsService::class)),
			logger: $logger ?? $this->createMock(LoggerInterface::class)
		);
	}//end references()

	/**
	 * The initial status and the workflow pin move to the copies, in one save.
	 *
	 * @return void
	 */
	public function testTheCopyIsRepointedAtItsOwnChildrenInOneSave(): void {
		$caseType = ['id' => 'ct-new', 'initialStatus' => ['id' => 's-old'], 'workflowDefinition' => 'wf-old'];

		$result = $this->references()->repoint(
			objectService: $this->objectService(),
			register: 'reg',
			schema: 'types',
			caseType: $caseType,
			statusMap: ['s-old' => 's-new'],
			templateMap: ['wf-old' => 'wf-new']
		);

		$expected = ['id' => 'ct-new', 'initialStatus' => 's-new', 'workflowDefinition' => 'wf-new'];
		self::assertSame([[$expected, 'reg', 'types']], $this->saves);
		self::assertSame($expected + ['saved' => true], $result);
	}//end testTheCopyIsRepointedAtItsOwnChildrenInOneSave()

	/**
	 * A workflow pin with no copy is cleared, and an unmapped status is kept.
	 *
	 * @return void
	 */
	public function testAnUnmappablePinIsClearedAndAnUnmappedStatusKept(): void {
		$this->references()->repoint(
			objectService: $this->objectService(),
			register: 'reg',
			schema: 'types',
			caseType: ['id' => 'ct-new', 'initialStatus' => 's-other', 'workflowDefinition' => ['id' => 'wf-elsewhere']],
			statusMap: ['s-old' => 's-new'],
			templateMap: []
		);

		self::assertSame(['id' => 'ct-new', 'initialStatus' => 's-other', 'workflowDefinition' => null], $this->saves[0][0]);
	}//end testAnUnmappablePinIsClearedAndAnUnmappedStatusKept()

	/**
	 * Without anything to repoint nothing is saved.
	 *
	 * @return void
	 */
	public function testNothingToRepointSavesNothing(): void {
		$caseType = ['id' => 'ct-new', 'initialStatus' => 's-other', 'workflowDefinition' => ''];

		$result = $this->references()->repoint(
			objectService: $this->objectService(),
			register: 'reg',
			schema: 'types',
			caseType: $caseType,
			statusMap: ['s-old' => 's-new'],
			templateMap: ['wf-old' => 'wf-new']
		);

		self::assertSame([], $this->saves);
		self::assertSame($caseType, $result);
	}//end testNothingToRepointSavesNothing()

	/**
	 * A failed save is logged and the repointed copy is returned unsaved.
	 *
	 * @return void
	 */
	public function testAFailedSaveIsLoggedAndTheCopyReturned(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with(
			'CaseTypeCopyService: could not repoint the copy on its own children',
			['caseType' => 'ct-new', 'exception' => 'locked']
		);

		$result = $this->references(logger: $logger)->repoint(
			objectService: $this->objectService(failure: new \RuntimeException('locked')),
			register: 'reg',
			schema: 'types',
			caseType: ['id' => 'ct-new', 'initialStatus' => 's-old'],
			statusMap: ['s-old' => 's-new'],
			templateMap: []
		);

		self::assertSame(['id' => 'ct-new', 'initialStatus' => 's-new'], $result);
	}//end testAFailedSaveIsLoggedAndTheCopyReturned()

	/**
	 * The binding over these template rows for case type ct-2.
	 *
	 * @param array<int, array<string, mixed>> $rows The template rows.
	 *
	 * @return WorkflowTemplateBinding
	 */
	private function binding(array $rows): WorkflowTemplateBinding {
		$store = $this->getMockBuilder(CaseTypeStore::class)
			->disableOriginalConstructor()
			->onlyMethods(['rowsOfType'])
			->getMock();
		$store->expects($this->once())->method('rowsOfType')->with('workflow_template_schema', 'ct-2')->willReturn($rows);

		return new WorkflowTemplateBinding(store: $store);
	}//end binding()

	/**
	 * The active template wins over earlier listed ones.
	 *
	 * @return void
	 */
	public function testTheActiveTemplateIsBound(): void {
		$case = $this->binding([['id' => 'wf-a', 'version' => 4], ['@self' => ['id' => 'wf-b'], 'version' => '7', 'isActive' => true]])
			->bind(case: ['id' => 'c-1', 'workflowTemplate' => 'wf-old', 'workflowVersion' => 2], targetCaseTypeId: 'ct-2');

		self::assertSame(['id' => 'c-1', 'workflowTemplate' => 'wf-b', 'workflowVersion' => 7], $case);
	}//end testTheActiveTemplateIsBound()

	/**
	 * Without an active template the first listed is bound, at version 1 by default.
	 *
	 * @return void
	 */
	public function testWithoutAnActiveTemplateTheFirstIsBound(): void {
		$case = $this->binding([['id' => 'wf-a', 'isActive' => 'true'], ['id' => 'wf-b', 'version' => 3]])
			->bind(case: ['id' => 'c-1'], targetCaseTypeId: 'ct-2');

		self::assertSame(['wf-a', 1], [$case['workflowTemplate'], $case['workflowVersion']]);
	}//end testWithoutAnActiveTemplateTheFirstIsBound()

	/**
	 * A case type without templates unbinds the case.
	 *
	 * @return void
	 */
	public function testNoTemplateUnbindsTheCase(): void {
		$case = $this->binding([])->bind(case: ['id' => 'c-1', 'workflowTemplate' => 'wf-old', 'workflowVersion' => 2], targetCaseTypeId: 'ct-2');

		self::assertSame(['id' => 'c-1', 'workflowTemplate' => null, 'workflowVersion' => null], $case);
	}//end testNoTemplateUnbindsTheCase()
}//end class
