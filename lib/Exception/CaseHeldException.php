<?php

/**
 * Dossiq case-held exception.
 *
 * The one refusal a delete of a case can carry: something holds the case, and
 * the caller is told which rules hold, all of them, in one message. Before it
 * existed the two guards that refused a delete (the Awb legal hold and the
 * signed beschikking) each raised a bare `RuntimeException` whose message said
 * one thing at a time, and the open statutory term was not guarded at all.
 *
 * The rule slugs travel beside the sentence so a client can act on the reason
 * without parsing prose, and the status is fixed here rather than at each
 * controller, so every door that translates this exception answers the same
 * 409 (ADR-105: an app exception under `lib/Exception/` maps where the app
 * says it maps).
 *
 * @category Exception
 * @package  OCA\Dossiq\Exception
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/case-delete-guard/specs/case-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Exception;

use RuntimeException;
use Throwable;

/**
 * Something holds this case, so it is not deleted (REQ-CM-35).
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/changes/case-delete-guard/specs/case-management/spec.md
 */
class CaseHeldException extends RuntimeException {

	/**
	 * The status every door answers when a case is held.
	 *
	 * A conflict, not a validation failure: the request is well formed and the
	 * case is real, and it is the state of the case that refuses.
	 *
	 * @var int
	 */
	public const STATUS = 409;

	/**
	 * The error code the refusal body carries beside the message.
	 *
	 * @var string
	 */
	public const ERROR_CODE = 'case.held';

	/**
	 * A statutory term on the case is still running.
	 *
	 * @var string
	 */
	public const RULE_OPEN_TERM = 'open-term';

	/**
	 * Another case names this one as its parent.
	 *
	 * @var string
	 */
	public const RULE_HAS_SUBCASES = 'has-subcases';

	/**
	 * OpenRegister carries an active legal hold on the case.
	 *
	 * @var string
	 */
	public const RULE_LEGAL_HOLD = 'legal-hold';

	/**
	 * The case is closed and its retention period has not ended.
	 *
	 * @var string
	 */
	public const RULE_IN_RETENTION = 'in-retention';

	/**
	 * Every rule this refusal knows, in the order a message names them.
	 *
	 * @var array<int, string>
	 */
	public const RULES = [
		self::RULE_OPEN_TERM,
		self::RULE_HAS_SUBCASES,
		self::RULE_LEGAL_HOLD,
		self::RULE_IN_RETENTION,
	];

	/**
	 * Constructor.
	 *
	 * @param array<int, string> $rules The rule slugs that hold, at least one.
	 * @param string $message The sentence naming every rule in words.
	 * @param Throwable|null $previous The underlying failure, when there is one.
	 */
	public function __construct(
		private readonly array $rules,
		string $message,
		?Throwable $previous = null,
	) {
		parent::__construct(message: $message, code: self::STATUS, previous: $previous);
	}//end __construct()

	/**
	 * Rebuild the refusal from the body a stopped delete event carried.
	 *
	 * The guard runs inside OpenRegister's event dispatcher, which wraps a
	 * stopped event in its own `HookStoppedException` and carries this body
	 * through as that exception's errors. A controller that wants to answer
	 * {@see self::STATUS} rather than the dispatcher's generic status asks
	 * here whether the refusal was this one.
	 *
	 * @param array<string, mixed> $errors The stopped event's error body.
	 *
	 * @return self|null The refusal, or null when the stop was something else.
	 *
	 * @spec openspec/changes/case-delete-guard/specs/case-management/spec.md
	 */
	public static function fromHookErrors(array $errors): ?self {
		if (($errors['error'] ?? '') !== self::ERROR_CODE) {
			return null;
		}

		$rules = [];
		foreach ((array)($errors['blockedBy'] ?? []) as $rule) {
			if (in_array($rule, self::RULES, true) === true) {
				$rules[] = $rule;
			}
		}

		if ($rules === []) {
			return null;
		}

		return new self(rules: $rules, message: (string)($errors['message'] ?? ''));
	}//end fromHookErrors()

	/**
	 * The rule slugs that hold this case.
	 *
	 * @return array<int, string> One or more of {@see self::RULES}.
	 *
	 * @spec openspec/changes/case-delete-guard/specs/case-management/spec.md
	 */
	public function getRules(): array {
		return $this->rules;
	}//end getRules()

	/**
	 * The refusal as a response body (ADR-050).
	 *
	 * @return array{message: string, error: string, blockedBy: array<int, string>}
	 *
	 * @spec openspec/changes/case-delete-guard/specs/case-management/spec.md
	 */
	public function toResponseBody(): array {
		return [
			'message' => $this->getMessage(),
			'error' => self::ERROR_CODE,
			'blockedBy' => $this->rules,
		];
	}//end toResponseBody()
}//end class
