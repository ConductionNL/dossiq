<?php

/**
 * Dossiq Checklist Guard evaluator.
 *
 * Guard config shape: `{type: 'checklist', taskId: <uuid>, requiredItems?: [<itemLabel>, ...]}`.
 * Loads the referenced task and verifies that every required checklist item
 * is marked `checked: true`. If `requiredItems` is omitted, all items on the
 * task must be checked.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Transitions
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Transitions;

use OCA\Dossiq\Service\SettingsService;
use Psr\Log\LoggerInterface;

/**
 * Guard: verifies checklist items on a linked task are complete.
 *
 * @spec openspec/changes/status-transition-engine/tasks.md#T05
 */
class ChecklistGuard implements GuardEvaluatorInterface {
	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Bridge to OpenRegister + config
	 * @param LoggerInterface $logger Logger
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Evaluate the checklist guard.
	 *
	 * @param array<string, mixed> $guardConfig Guard configuration
	 * @param array<string, mixed> $case Case object (unused here, included for interface parity)
	 * @param string $userId Current user UID (unused)
	 *
	 * @return GuardResult
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 */
	public function evaluate(array $guardConfig, array $case, string $userId): GuardResult {
		$taskId = (string)($guardConfig['taskId'] ?? '');
		if ($taskId === '') {
			return new GuardResult(passed: false, failureMessage: 'Checklist guard missing taskId');
		}

		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			return new GuardResult(passed: false, failureMessage: 'Opslag niet beschikbaar');
		}

		$register = $this->settingsService->getConfigValue(key: 'register');
		$taskSchema = $this->settingsService->getConfigValue(key: 'task_schema');
		if ($register === '' || $taskSchema === '') {
			return new GuardResult(passed: false, failureMessage: 'Taak-register niet geconfigureerd');
		}

		try {
			$task = $objectService->find($taskId, register: $register, schema: $taskSchema);
			$task = $this->toArray(value: $task);
		} catch (\Throwable $e) {
			$this->logger->error('ChecklistGuard: task load failed', ['exception' => $e->getMessage()]);
			return new GuardResult(passed: false, failureMessage: 'Gekoppelde taak niet gevonden');
		}

		$missing = $this->collectMissingItems(
			task: $task,
			requiredItems: ($guardConfig['requiredItems'] ?? null),
		);
		if ($missing === []) {
			return new GuardResult(passed: true);
		}

		return new GuardResult(
			passed: false,
			failureMessage: sprintf("%d checklistitem niet afgevinkt: '%s'", count($missing), $missing[0]),
			details: ['missing' => $missing],
		);
	}//end evaluate()

	/**
	 * Collect the labels of checklist items that are not yet ticked off.
	 *
	 * When `requiredItems` is a non-empty array only those labels are
	 * considered; otherwise every unchecked item with a label counts.
	 *
	 * @param array<string, mixed> $task The loaded task object
	 * @param mixed $requiredItems Optional allow-list of required labels
	 *
	 * @return array<int, string>
	 */
	private function collectMissingItems(array $task, mixed $requiredItems): array {
		$hasRequired = (is_array($requiredItems) === true && $requiredItems !== []);
		$missing = [];
		foreach ($this->resolveItems(task: $task) as $item) {
			if (is_array($item) === false) {
				continue;
			}

			if ((bool)($item['checked'] ?? false) === true) {
				continue;
			}

			$label = $this->itemLabel(item: $item);
			if ($hasRequired === true && in_array($label, $requiredItems, true) === true) {
				$missing[] = $label;
				continue;
			}

			if ($hasRequired === false && $label !== '') {
				$missing[] = $label;
			}
		}//end foreach

		return $missing;
	}//end collectMissingItems()

	/**
	 * Read the checklist items off a task object, tolerating both shapes.
	 *
	 * @param array<string, mixed> $task The loaded task object
	 *
	 * @return array<int|string, mixed>
	 */
	private function resolveItems(array $task): array {
		$items = $task['checklist'] ?? ($task['items'] ?? []);
		if (is_array($items) === false) {
			return [];
		}

		return $items;
	}//end resolveItems()

	/**
	 * Read the display label off a single checklist item.
	 *
	 * @param array<string, mixed> $item A single checklist item
	 *
	 * @return string
	 */
	private function itemLabel(array $item): string {
		return (string)($item['label'] ?? ($item['name'] ?? ''));
	}//end itemLabel()

	/**
	 * Coerce ObjectService results to array.
	 *
	 * @param mixed $value Mixed result from ObjectService
	 *
	 * @return array<string, mixed>
	 */
	private function toArray(mixed $value): array {
		if (is_array($value) === true) {
			return $value;
		}

		if (is_object($value) === true && method_exists($value, 'jsonSerialize') === true) {
			$serialized = $value->jsonSerialize();
			if (is_array($serialized) === true) {
				return $serialized;
			}
		}

		return [];
	}//end toArray()
}//end class
