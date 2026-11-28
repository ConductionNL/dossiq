<?php

/**
 * Recompute who each routable step of a case goes to.
 *
 * Split out of {@see \OCA\Dossiq\Controller\RoutingController}: the case and
 * its workflow template are read from the configured register, and every step
 * that carries a routing rule is resolved against the case. Turning a stored
 * object into an array and a stored step list into rows stays with the
 * controller, which hands both in.
 *
 * @category Controller
 * @package  OCA\Dossiq\Controller\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/archive/2026-05-11-role-based-step-routing/tasks.md#T05
 * @spec openspec/changes/method-decomposition/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller\Support;

use Closure;
use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Kernel\Contracts\RoleResolverServiceInterface;
use OCA\Dossiq\Kernel\Contracts\SettingsServiceInterface;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use Throwable;

/**
 * Reads a case and its workflow's steps, and resolves each step's rule.
 *
 * @spec openspec/changes/method-decomposition/tasks.md
 */
final class CaseStepReroute {

	/**
	 * Constructor.
	 *
	 * @param SettingsServiceInterface $settingsService The object service and the configured schemas.
	 * @param RoleResolverServiceInterface $resolver Normalises a step's rule and resolves it against the case.
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function __construct(
		private readonly SettingsServiceInterface $settingsService,
		private readonly RoleResolverServiceInterface $resolver,
	) {
	}//end __construct()

	/**
	 * The steps of the case that carry a rule, with their resolved assignees.
	 *
	 * @param string $caseId The case UUID.
	 * @param Closure $toArray Turns what the object service answered into an array.
	 * @param Closure $decodeSteps Turns a stored step list into rows.
	 *
	 * @return array<int, array<string, mixed>>|null One entry per routable step; null without OpenRegister.
	 *
	 * @throws RefusedException When a case's roles could not be read at all.
	 * @throws Throwable When the case or its workflow cannot be read.
	 *
	 * @spec openspec/changes/archive/2026-05-11-role-based-step-routing/tasks.md#T05
	 */
	public function affectedSteps(string $caseId, Closure $toArray, Closure $decodeSteps): ?array {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			return null;
		}

		$register = $this->settingsService->getConfigValue('register');
		$caseSchema = $this->settingsService->getConfigValue('case_schema');
		$workflowSlug = $this->settingsService->getConfigValue('workflow_template_schema');

		$case = $toArray($objectService->find($caseId, new ObjectFindOptions(register: $register, schema: $caseSchema)));

		$steps = [];
		$workflowId = (string)($case['workflowTemplate'] ?? '');
		if ($workflowId !== '' && $workflowSlug !== '') {
			$workflow = $toArray($objectService->find($workflowId, new ObjectFindOptions(register: $register, schema: $workflowSlug)));
			$steps = $decodeSteps($workflow['steps'] ?? '[]');
		}

		return $this->resolveSteps(steps: $steps, case: $case);
	}//end affectedSteps()

	/**
	 * Resolve every routable step on a case to its assignees.
	 *
	 * @param array<int, array<string, mixed>> $steps The workflow's steps.
	 * @param array<string, mixed>             $case  The case the rules resolve against.
	 *
	 * @return array<int, array<string, mixed>> One entry per step that carries a rule.
	 *
	 * @throws RefusedException When a case's roles could not be read at all.
	 *
	 * @spec openspec/changes/archive/2026-05-11-role-based-step-routing/tasks.md#T05
	 */
	private function resolveSteps(array $steps, array $case): array {
		$affected = [];
		foreach ($steps as $step) {
			$rule = $this->resolver->normaliseRule($step);
			if ($rule === null) {
				continue;
			}

			$affected[] = [
				'stepId' => (string)($step['id'] ?? ''),
				'order' => (int)($step['order'] ?? 0),
				'assignees' => $this->resolver->resolve($rule, $case),
			];
		}

		return $affected;
	}//end resolveSteps()
}//end class
