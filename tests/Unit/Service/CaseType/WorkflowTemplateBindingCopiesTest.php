<?php

/**
 * Which workflow template a case gets when it moves onto another case type.
 *
 * Pins rebindTemplate() of CaseRebindService and CaseVersionMove before the
 * two copies share one helper: the active template wins, else the first one
 * listed, and with none the case is unbound; the version defaults to 1.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/case-type-version-chain/specs/zaaktype-versioning/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\CaseType;

use OCA\Dossiq\Service\CaseRebindService;
use OCA\Dossiq\Service\CaseType\CaseVersionMove;
use OCA\Dossiq\Service\CaseTypeStore;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;

/**
 * Both moves bind the same template for the same rows.
 *
 * @covers \OCA\Dossiq\Service\CaseRebindService
 * @covers \OCA\Dossiq\Service\CaseType\CaseVersionMove
 *
 * @spec openspec/changes/case-type-version-chain/specs/zaaktype-versioning/spec.md
 * @uses \OCA\Dossiq\Service\CaseType\WorkflowTemplateBinding
 */
class WorkflowTemplateBindingCopiesTest extends TestCase {
	/**
	 * Both classes, crossed with every shape of template rows.
	 *
	 * @return array<string, array{0: class-string, 1: array<int, array<string, mixed>>, 2: array<string, mixed>}>
	 */
	public static function cases(): array {
		$rows = [
			'the active template wins over an earlier one' => [
				[['id' => 'wf-1', 'version' => 2], ['id' => 'wf-2', 'isActive' => true, 'version' => 5], ['id' => 'wf-3', 'isActive' => true, 'version' => 9]],
				['workflowTemplate' => 'wf-2', 'workflowVersion' => 5],
			],
			'without an active template the first is taken' => [
				[['id' => 'wf-1', 'isActive' => false, 'version' => '3'], ['id' => 'wf-2']],
				['workflowTemplate' => 'wf-1', 'workflowVersion' => 3],
			],
			'a truthy but not true flag is not active' => [
				[['id' => 'wf-1'], ['id' => 'wf-2', 'isActive' => 1]],
				['workflowTemplate' => 'wf-1', 'workflowVersion' => 1],
			],
			'the version defaults to 1' => [
				[['id' => 'wf-1', 'isActive' => true]],
				['workflowTemplate' => 'wf-1', 'workflowVersion' => 1],
			],
			'no template unbinds the case' => [
				[],
				['workflowTemplate' => null, 'workflowVersion' => null],
			],
		];
		$cases = [];
		foreach ([CaseRebindService::class, CaseVersionMove::class] as $class) {
			foreach ($rows as $label => [$templates, $expected]) {
				$cases[$class . ' ' . $label] = [$class, $templates, $expected];
			}
		}

		return $cases;
	}//end cases()

	/**
	 * The case comes back bound to the expected template, its other fields kept.
	 *
	 * @param class-string                     $class     The move.
	 * @param array<int, array<string, mixed>> $templates The target type's template rows.
	 * @param array<string, mixed>             $expected  The binding.
	 *
	 * @dataProvider cases
	 *
	 * @return void
	 */
	public function testTheExpectedTemplateIsBound(string $class, array $templates, array $expected): void {
		$store = $this->createMock(originalClassName: CaseTypeStore::class);
		$store->expects(self::once())->method('rowsOfType')->with('workflow_template_schema', 'ct-2')->willReturn($templates);
		$store->method('rowId')->willReturnCallback(static fn (array $row): string => (string)$row['id']);
		$subject = $this->build(class: $class, store: $store);

		$case = (new ReflectionMethod($subject, 'rebindTemplate'))->invoke($subject, ['id' => 'c-1', 'workflowTemplate' => 'old'], 'ct-2');

		self::assertSame(expected: array_merge(['id' => 'c-1'], $expected), actual: $case);
	}//end testTheExpectedTemplateIsBound()

	/**
	 * The move over the given store, every other dependency a double.
	 *
	 * @param class-string  $class The move.
	 * @param CaseTypeStore $store The store.
	 *
	 * @return object The move.
	 */
	private function build(string $class, CaseTypeStore $store): object {
		$arguments = [];
		foreach ((new ReflectionClass($class))->getConstructor()->getParameters() as $parameter) {
			$type = $parameter->getType();
			self::assertInstanceOf(expected: ReflectionNamedType::class, actual: $type);
			$arguments[] = $this->argument(type: $type->getName(), store: $store);
		}

		return new $class(...$arguments);
	}//end build()

	/**
	 * The store for a CaseTypeStore parameter, a double for any other.
	 *
	 * @param class-string  $type  The parameter's class.
	 * @param CaseTypeStore $store The store.
	 *
	 * @return object The argument.
	 */
	private function argument(string $type, CaseTypeStore $store): object {
		if ($type === CaseTypeStore::class) {
			return $store;
		}

		return $this->createMock(originalClassName: $type);
	}//end argument()
}//end class
