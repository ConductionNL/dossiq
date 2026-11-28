<?php

/**
 * Unit tests for CaseStepReroute.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Controller\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/method-decomposition/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller\Support;

use OCA\Dossiq\Controller\Support\CaseStepReroute;
use OCA\Dossiq\Kernel\Contracts\RoleResolverServiceInterface;
use OCA\Dossiq\Kernel\Contracts\SettingsServiceInterface;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use PHPUnit\Framework\TestCase;

/**
 * Without OpenRegister there is nothing to reroute; with it, the handed-in
 * conversions read the case and its steps, and every step with a rule is resolved.
 *
 * @covers \OCA\Dossiq\Controller\Support\CaseStepReroute
 *
 * @spec openspec/changes/method-decomposition/tasks.md
 */
final class CaseStepRerouteTest extends TestCase {

	/**
	 * The settings double over an object service, or none.
	 *
	 * @param object|null $objectService The object service.
	 *
	 * @return SettingsServiceInterface
	 */
	private function settings(?object $objectService): SettingsServiceInterface {
		$settings = $this->createMock(originalClassName: SettingsServiceInterface::class);
		$settings->method('getObjectService')->willReturn(value: $objectService);
		$settings->method('getConfigValue')->willReturnMap(
			valueMap: [
				['register', '', 'dossiq'],
				['case_schema', '', 'case'],
				['workflow_template_schema', '', 'workflowTemplate'],
			]
		);

		return $settings;
	}//end settings()

	/**
	 * Without OpenRegister the answer is null and no conversion runs.
	 *
	 * @return void
	 */
	public function testWithoutOpenRegisterThereIsNothingToReroute(): void {
		$resolver = $this->createMock(originalClassName: RoleResolverServiceInterface::class);
		$resolver->expects($this->never())->method('resolve');
		$never = function (): never {
			self::fail(message: 'no conversion without OpenRegister');
		};

		$this->assertNull(
			actual: (new CaseStepReroute(settingsService: $this->settings(objectService: null), resolver: $resolver))
				->affectedSteps(caseId: 'c-1', toArray: $never, decodeSteps: $never)
		);
	}//end testWithoutOpenRegisterThereIsNothingToReroute()

	/**
	 * The conversions read what the service answered; a step without a rule is left out.
	 *
	 * @return void
	 */
	public function testTheHandedInConversionsReadTheCaseAndItsSteps(): void {
		$objects = new class {
			/**
			 * The object for an id, wrapped so only the conversion can read it.
			 *
			 * @param string $id The id.
			 * @param ObjectFindOptions $options Where to look.
			 *
			 * @return object
			 */
			public function find(string $id, ObjectFindOptions $options): object {
				$objects = [
					'c-1' => ['id' => 'c-1', 'workflowTemplate' => 'wf-1'],
					'wf-1' => ['steps' => 'stored-steps'],
				];
				return (object)['wrapped' => $objects[$id] + ['schema' => (string)$options->schema]];
			}
		};
		$resolver = $this->createMock(originalClassName: RoleResolverServiceInterface::class);
		$resolver->method('normaliseRule')->willReturnCallback(
			callback: static fn (array $step): ?array => [['roleType' => 'x'], null][(int)($step['id'] !== 's-1')]
		);
		$resolver->expects($this->once())->method('resolve')
			->with(['roleType' => 'x'], ['id' => 'c-1', 'workflowTemplate' => 'wf-1', 'schema' => 'case'])
			->willReturn(value: ['anna']);

		$affected = (new CaseStepReroute(settingsService: $this->settings(objectService: $objects), resolver: $resolver))->affectedSteps(
			caseId: 'c-1',
			toArray: static fn (mixed $value): array => $value->wrapped,
			decodeSteps: static fn (mixed $value): array => ['stored-steps' => [['id' => 's-1', 'order' => 4], ['id' => 's-2']]][$value] ?? []
		);

		$this->assertSame(expected: [['stepId' => 's-1', 'order' => 4, 'assignees' => ['anna']]], actual: $affected);
	}//end testTheHandedInConversionsReadTheCaseAndItsSteps()
}//end class
