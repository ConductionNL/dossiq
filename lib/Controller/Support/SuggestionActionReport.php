<?php

/**
 * Dossiq suggestion action report.
 *
 * What a person reports doing with an AI suggestion, read from the request
 * without a store: whether the report may be recorded at all, and the
 * suggestion, the applied value, the reason and the surrounding context as
 * the oversight entry stores them.
 *
 * @category Controller
 * @package  OCA\Dossiq\Controller\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/ai-oversight-dispatch/spec.md
 * @spec openspec/changes/method-decomposition/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller\Support;

use OCP\IRequest;

/**
 * A reported action on a suggestion: its refusal, and the parts the oversight entry stores.
 *
 * @spec openspec/specs/ai-oversight-dispatch/spec.md
 * @spec openspec/changes/method-decomposition/tasks.md
 */
final class SuggestionActionReport {

	/**
	 * What a person can do with a suggestion.
	 *
	 * @var string[]
	 */
	public const USER_ACTIONS = ['accepted', 'rejected', 'modified', 'ignored'];

	/**
	 * Why a shown suggestion went unused.
	 *
	 * @var string[]
	 */
	public const UNUSED_BECAUSE = ['closed', 'expired', 'superseded'];

	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request that carries the report.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IRequest $request,
	) {
	}//end __construct()

	/**
	 * Why the report is refused: a required field missing, or an unknown action or cause.
	 *
	 * @return string|null The refusal, or null when the report may be recorded.
	 *
	 * @spec openspec/specs/ai-oversight-dispatch/spec.md
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function refusal(): ?string {
		$caseId = (string)$this->request->getParam('caseId', '');
		$type = (string)$this->request->getParam('type', '');
		$userAction = (string)$this->request->getParam('userAction', '');
		$unusedBecause = (string)$this->request->getParam('unusedBecause', '');

		if ($caseId === '' || $type === '' || $userAction === '') {
			return 'caseId, type, and userAction are required';
		}

		return $this->actionRefusal(userAction: $userAction, unusedBecause: $unusedBecause);
	}//end refusal()

	/**
	 * The suggestion as the entry stores it, an empty array when absent.
	 *
	 * @return array<mixed>
	 *
	 * @spec openspec/specs/ai-oversight-dispatch/spec.md
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function suggestion(): array {
		return $this->asPayload(value: $this->request->getParam('suggestion', [])) ?? [];
	}//end suggestion()

	/**
	 * The value the person applied, or null when absent.
	 *
	 * @return array<mixed>|null
	 *
	 * @spec openspec/specs/ai-oversight-dispatch/spec.md
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function actualValue(): ?array {
		return $this->asPayload(value: $this->request->getParam('actualValue'));
	}//end actualValue()

	/**
	 * The reason given, when it is a string; otherwise null.
	 *
	 * @return string|null
	 *
	 * @spec openspec/specs/ai-oversight-dispatch/spec.md
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function reason(): ?string {
		$value = $this->request->getParam('reason');
		if (is_string($value) === false) {
			return null;
		}

		return $value;
	}//end reason()

	/**
	 * What surrounded the suggestion: why it went unused, the prompt, the model, the ids.
	 *
	 * @return array<string, string>
	 *
	 * @spec openspec/specs/ai-oversight-dispatch/spec.md
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function context(): array {
		return [
			'unusedBecause' => (string)$this->request->getParam('unusedBecause', ''),
			'prompt' => (string)$this->request->getParam('prompt', ''),
			'model' => (string)$this->request->getParam('model', ''),
			'suggestionId' => (string)$this->request->getParam('suggestionId', ''),
			'documentId' => (string)$this->request->getParam('documentId', ''),
		];
	}//end context()

	/**
	 * Why a reported action is refused, or null when it is accepted.
	 *
	 * @param string $userAction    The reported action.
	 * @param string $unusedBecause Why an ignored suggestion went unused.
	 *
	 * @return string|null The refusal, or null.
	 */
	private function actionRefusal(string $userAction, string $unusedBecause): ?string {
		if (in_array($userAction, self::USER_ACTIONS, true) === false) {
			return 'userAction must be one of: ' . implode(', ', self::USER_ACTIONS);
		}

		if ($userAction === 'ignored' && in_array($unusedBecause, self::UNUSED_BECAUSE, true) === false) {
			return 'unusedBecause must be one of: ' . implode(', ', self::UNUSED_BECAUSE);
		}

		return null;
	}//end actionRefusal()

	/**
	 * A suggestion or applied value as the audit entry stores it.
	 *
	 * The schema holds an object; a surface that shows text sends a string,
	 * which is kept under `text`.
	 *
	 * @param mixed $value The request value.
	 *
	 * @return array<mixed>|null The value as an array, or null when absent.
	 */
	private function asPayload(mixed $value): ?array {
		if (is_array($value) === true) {
			return $value;
		}

		if (is_string($value) === true && $value !== '') {
			return ['text' => $value];
		}

		return null;
	}//end asPayload()
}//end class
