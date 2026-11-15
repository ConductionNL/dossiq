<?php

/**
 * Golden test for which evaluator answers each guard type in the registry.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Transitions
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/status-transition-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Transitions;

use OCA\Dossiq\Service\Transitions\ApprovalGuard;
use OCA\Dossiq\Service\Transitions\CapacityGuard;
use OCA\Dossiq\Service\Transitions\ChecklistGuard;
use OCA\Dossiq\Service\Transitions\DateReachedGuard;
use OCA\Dossiq\Service\Transitions\DelegatedAuthorityGuard;
use OCA\Dossiq\Service\Transitions\DocumentAssessmentGuard;
use OCA\Dossiq\Service\Transitions\GuardEvaluatorInterface;
use OCA\Dossiq\Service\Transitions\GuardRegistry;
use OCA\Dossiq\Service\Transitions\GuardResult;
use OCA\Dossiq\Service\Transitions\RequiredDocumentGuard;
use OCA\Dossiq\Service\Transitions\RequiredFieldGuard;
use OCA\Dossiq\Service\Transitions\RoleGuard;
use OCA\Dossiq\Service\Transitions\StatusChecklistGuard;
use OCA\Dossiq\Service\Transitions\StatusEntryRequirementsGuard;
use OCA\Dossiq\Tests\Support\MatchesGoldenFiles;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;

/**
 * Pins, per guard type, whether the registry knows it and which evaluator answers it, for the acting user.
 *
 * @covers \OCA\Dossiq\Service\Transitions\GuardRegistry
 * @covers \OCA\Dossiq\Service\Transitions\CaseContentGuards
 * @covers \OCA\Dossiq\Service\Transitions\ActorGuards
 * @covers \OCA\Dossiq\Service\Transitions\StatusEntryGuards
 * @uses \OCA\Dossiq\Service\Transitions\GuardResult
 */
class GuardRegistryWiringGoldenTest extends TestCase {
	use MatchesGoldenFiles;

	/**
	 * The guard types a definition can name, plus one nobody registers.
	 */
	private const TYPES = [
		'checklist', 'requiredField', 'requiredDocument', 'roleGuard', 'delegatedAuthorityGuard', 'mandaatGuard',
		'statusChecklist', 'statusCapacity', 'approvalGate', 'documentAssessment', 'statusEntryRequirements', 'dateReached', 'nobodysGuard',
	];

	/**
	 * Every evaluator present, and the optional ones left out.
	 *
	 * @return array<string, array{0: bool}>
	 */
	public static function registries(): array {
		return ['all evaluators' => [true], 'without the optional evaluators' => [false]];
	}//end registries()

	/**
	 * Each guard type is known, and answered by its evaluator, as recorded.
	 *
	 * @param bool $withOptional Whether the three optional evaluators are given.
	 *
	 * @return void
	 *
	 * @dataProvider registries
	 */
	public function testEachGuardTypeIsAnsweredAsRecorded(bool $withOptional): void {
		$registry = $this->registry(withOptional: $withOptional);

		$answers = [];
		foreach (self::TYPES as $type) {
			$answers[$type] = [
				'knows' => $registry->knows(type: $type),
				'answer' => $registry->evaluateAll(guards: [['type' => $type]], case: ['id' => 'case-1'], userId: 'behandelaar'),
			];
		}

		$this->assertMatchesGolden(
			'guard-registry-wiring/' . preg_replace('/[^a-z]+/', '-', strtolower((string)$this->dataName())),
			$answers
		);
	}//end testEachGuardTypeIsAnsweredAsRecorded()

	/**
	 * Every shape of snapshot: skipped, passed without details, failed with and without, unknown.
	 *
	 * @return void
	 */
	public function testEveryShapeOfASnapshot(): void {
		$registry = $this->registry(withOptional: false);
		$answers  = [
			'passes' => new GuardResult(passed: true),
			'fails-bare' => new GuardResult(passed: false, failureMessage: 'Nee'),
			'fails-detailed' => new GuardResult(passed: false, failureMessage: null, details: ['field' => 'x']),
		];
		foreach ($answers as $type => $result) {
			$evaluator = $this->createMock(GuardEvaluatorInterface::class);
			$evaluator->method('evaluate')->willReturn($result);
			$registry->registerEvaluator(type: $type, evaluator: $evaluator);
		}

		$this->assertSame(
			[
				['type' => 'passes', 'passed' => true, 'failureMessage' => null],
				['type' => 'fails-bare', 'passed' => false, 'failureMessage' => 'Nee'],
				['type' => 'fails-detailed', 'passed' => false, 'failureMessage' => null, 'details' => ['field' => 'x']],
				['type' => 'unheard-of', 'passed' => false, 'failureMessage' => 'Onbekende guard', 'details' => ['unknown' => true]],
			],
			$registry->evaluateAll(
				guards: [['type' => ''], [], ['type' => 'passes', 'config' => ['field' => 'x']], ['type' => 'fails-bare'], ['type' => 'fails-detailed', 'config' => []], ['type' => 'unheard-of']],
				case: ['id' => 'case-1'],
				userId: 'behandelaar'
			)
		);
	}//end testEveryShapeOfASnapshot()

	/**
	 * The registry, built the way its constructor takes the evaluators.
	 *
	 * @param bool $withOptional Whether the three optional evaluators are given.
	 *
	 * @return GuardRegistry The registry.
	 */
	private function registry(bool $withOptional): GuardRegistry {
		$authority = $this->evaluator(class: DelegatedAuthorityGuard::class, name: 'delegatedAuthority');
		$authority->method('withDefaults')->willReturn($this->evaluator(class: DelegatedAuthorityGuard::class, name: 'delegatedAuthority with mandaatCategorie'));

		$given = [
			'checklist' => $this->evaluator(class: ChecklistGuard::class, name: 'checklist'),
			'requiredField' => $this->evaluator(class: RequiredFieldGuard::class, name: 'requiredField'),
			'requiredDocument' => $this->evaluator(class: RequiredDocumentGuard::class, name: 'requiredDocument'),
			'roleGuard' => $this->evaluator(class: RoleGuard::class, name: 'role'),
			'authority' => $authority,
			'statusChecklist' => $this->evaluator(class: StatusChecklistGuard::class, name: 'statusChecklist'),
			'capacity' => $this->evaluator(class: CapacityGuard::class, name: 'capacity'),
			'logger' => $this->createMock(LoggerInterface::class),
			'approvalGuard' => $withOptional ? $this->evaluator(class: ApprovalGuard::class, name: 'approval') : null,
			'documentAssessment' => $withOptional ? $this->evaluator(class: DocumentAssessmentGuard::class, name: 'documentAssessment') : null,
			'entryRequirements' => $withOptional ? $this->evaluator(class: StatusEntryRequirementsGuard::class, name: 'entryRequirements') : null,
			'dateReached' => $withOptional ? $this->evaluator(class: DateReachedGuard::class, name: 'dateReached') : null,
		];

		$parameters = (new ReflectionClass(GuardRegistry::class))->getConstructor()->getParameters();
		$arguments = [];
		foreach ($parameters as $parameter) {
			$type = (string)$parameter->getType();
			if (array_key_exists($parameter->getName(), $given) === true) {
				$arguments[$parameter->getName()] = $given[$parameter->getName()];
				continue;
			}

			$bundle = new ReflectionClass(ltrim($type, '?'));
			$fields = [];
			foreach ($bundle->getConstructor()->getParameters() as $field) {
				$fields[$field->getName()] = $given[$field->getName()];
			}

			$arguments[$parameter->getName()] = $bundle->newInstanceArgs($fields);
		}

		return new GuardRegistry(...$arguments);
	}//end registry()

	/**
	 * An evaluator mock that answers with its own name and the user it was asked for.
	 *
	 * @param class-string $class The evaluator class.
	 * @param string       $name  The name it answers with.
	 *
	 * @return \PHPUnit\Framework\MockObject\MockObject The evaluator.
	 */
	private function evaluator(string $class, string $name): object {
		$evaluator = $this->createMock($class);
		$evaluator->method('evaluate')->willReturnCallback(
			static fn (array $guardConfig, array $case, string $userId): GuardResult => new GuardResult(
				passed: false,
				failureMessage: $name . ' for ' . $userId,
				details: ['case' => $case['id'] ?? null],
			)
		);

		return $evaluator;
	}//end evaluator()
}//end class
