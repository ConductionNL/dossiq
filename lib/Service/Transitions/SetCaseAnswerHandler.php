<?php

/**
 * Writes one property definition's answer into a case's `properties` list.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Transitions
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/case-type-conditional-rules/specs/case-types/spec.md#requirement-rules-are-evaluated-by-the-engines-that-own-the-actions
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Transitions;

use OCA\Dossiq\Service\AssigneeResolver;
use OCA\Dossiq\Service\SettingsService;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Action `setCaseAnswer`: `{name, value}`.
 *
 * Reads the case as stored, replaces the value of the entry with that name or
 * appends one, and saves the case. Every other answer is kept as it was.
 *
 * @spec openspec/changes/case-type-conditional-rules/specs/case-types/spec.md#requirement-rules-are-evaluated-by-the-engines-that-own-the-actions
 */
class SetCaseAnswerHandler implements ActionHandlerInterface {

	/**
	 * Constructor.
	 *
	 * @param AssigneeResolver $assignees The case's id.
	 * @param SettingsService  $settings  The object service, register and case schema.
	 * @param LoggerInterface  $logger    The logger.
	 */
	public function __construct(
		private readonly AssigneeResolver $assignees,
		private readonly SettingsService $settings,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Write the answer onto the stored case.
	 *
	 * @param array<string, mixed> $actionConfig      `{name, value}`.
	 * @param array<string, mixed> $case              The case the step runs on.
	 * @param array<string, mixed> $transitionContext The run or transition context.
	 *
	 * @return ActionResult The result.
	 *
	 * @spec openspec/changes/case-type-conditional-rules/specs/case-types/spec.md#requirement-rules-are-evaluated-by-the-engines-that-own-the-actions
	 */
	public function handle(array $actionConfig, array $case, array $transitionContext): ActionResult {
		$name = trim((string)($actionConfig['name'] ?? ''));
		$caseId = $this->assignees->caseId(case: $case);
		$objects = $this->settings->getObjectService();
		if ($name === '' || $caseId === '' || $objects === null) {
			return new ActionResult(succeeded: false, error: 'set_case_answer_incomplete');
		}

		try {
			$stored = $objects->find($caseId);
			if (is_object($stored) === true && method_exists($stored, 'jsonSerialize') === true) {
				$stored = $stored->jsonSerialize();
			}

			$stored = (array)$stored;
			$stored['properties'] = self::withAnswer(
				answers: (array)($stored['properties'] ?? []),
				name: $name,
				value: ($actionConfig['value'] ?? null)
			);
			$objects->saveObject(
				object: $stored,
				register: $this->settings->getConfigValue(key: 'register'),
				schema: $this->settings->getConfigValue(key: 'case_schema')
			);
		} catch (Throwable $e) {
			$this->logger->error('SetCaseAnswerHandler: the answer was not written', ['case' => $caseId, 'exception' => $e->getMessage()]);

			return new ActionResult(succeeded: false, error: 'set_case_answer_failed');
		}

		return new ActionResult(succeeded: true, data: ['name' => $name]);
	}//end handle()

	/**
	 * The answers with one answer set: the entry of that name replaced, or one appended.
	 *
	 * @param array<int, mixed> $answers The case's `properties` list.
	 * @param string            $name    The property definition's name.
	 * @param mixed             $value   The value.
	 *
	 * @return array<int, mixed> The list.
	 *
	 * @spec openspec/changes/case-type-conditional-rules/specs/case-types/spec.md#requirement-rules-are-evaluated-by-the-engines-that-own-the-actions
	 */
	public static function withAnswer(array $answers, string $name, mixed $value): array {
		foreach ($answers as $index => $answer) {
			if (is_array($answer) === true && ($answer['name'] ?? null) === $name) {
				$answers[$index]['value'] = $value;
				return array_values($answers);
			}
		}

		$answers[] = ['name' => $name, 'value' => $value];

		return array_values($answers);
	}//end withAnswer()
}//end class
