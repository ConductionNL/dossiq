<?php

/**
 * Dossiq refusal exception.
 *
 * A refusal that a caller can read: the rule that said no, one sentence that
 * says it in plain language, and the HTTP status the controller answers with.
 *
 * It exists because a rule that refuses and a store that fails looked the
 * same from outside. Both ended as an empty value, and the sentence the
 * caller finally saw was written by whatever code found the emptiness next:
 * "you are not authorised" for a register that could not be read, "no active
 * mandate matrix" for a query that threw. The caller could not tell a no from
 * a failure, and neither could the log.
 *
 * The message stays the snake_case code the engine already uses, because
 * CaseActionProvider classifies refusals on it and the case timeline turns it
 * into the sentence the user reads. The rule slug is the kebab-case form of
 * the same code, which is what ADR-050 puts in `error`.
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
 * @spec openspec/changes/refusals-carry-a-status/specs/quality-gates/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Exception;

use RuntimeException;
use Throwable;

/**
 * A rule refused a write, or could not be evaluated (REQ-QG-CRN-2).
 *
 * @spec openspec/changes/refusals-carry-a-status/specs/quality-gates/spec.md
 */
class RefusedException extends RuntimeException {
	/**
	 * The status for a rule that was evaluated and said no.
	 *
	 * @var int
	 */
	public const STATUS_REFUSED = 409;

	/**
	 * The status for a rule that names who may act, and the caller is not them.
	 *
	 * @var int
	 */
	public const STATUS_FORBIDDEN = 403;

	/**
	 * The status for a rule the caller can satisfy by sending something more.
	 *
	 * @var int
	 */
	public const STATUS_UNPROCESSABLE = 422;

	/**
	 * The status for a rule that could not be evaluated at all.
	 *
	 * 503 rather than 403: the difference between "you may not" and "we
	 * could not tell" is the whole point of this class, and a client that
	 * retries a 503 is behaving correctly. ADR-102 reserves 503 for an
	 * absence expected to be restored, which is what an unreadable register
	 * is.
	 *
	 * @var int
	 */
	public const STATUS_INDETERMINATE = 503;

	/**
	 * Machine-readable rule slug, kebab-case, for the `error` field.
	 *
	 * @var string
	 */
	private string $rule;

	/**
	 * One static sentence the caller can show, authored at the throw site.
	 *
	 * @var string
	 */
	private string $sentence;

	/**
	 * The HTTP status the controller answers with.
	 *
	 * @var int
	 */
	private int $status;

	/**
	 * Constructor.
	 *
	 * @param string         $rule     Kebab-case rule slug, e.g. `transition-unauthorized`.
	 * @param string         $sentence Static, user-facing sentence. Never an exception message.
	 * @param int            $status   HTTP status to answer with.
	 * @param Throwable|null $previous The failure underneath, when there was one.
	 */
	public function __construct(
		string $rule,
		string $sentence,
		int $status = self::STATUS_REFUSED,
		?Throwable $previous = null,
	) {
		parent::__construct(
			message: str_replace(search: '-', replace: '_', subject: $rule),
			code: 0,
			previous: $previous,
		);
		$this->rule = $rule;
		$this->sentence = $sentence;
		$this->status = $status;
	}//end __construct()

	/**
	 * A rule that could not be evaluated, so the answer is neither yes nor no.
	 *
	 * @param string         $rule     Kebab-case rule slug.
	 * @param string         $sentence Static, user-facing sentence.
	 * @param Throwable|null $previous The failure underneath.
	 *
	 * @return self A 503-carrying refusal.
	 *
	 * @spec openspec/changes/refusals-carry-a-status/specs/quality-gates/spec.md
	 */
	public static function indeterminate(string $rule, string $sentence, ?Throwable $previous = null): self {
		return new self(
			rule: $rule,
			sentence: $sentence,
			status: self::STATUS_INDETERMINATE,
			previous: $previous,
		);
	}//end indeterminate()

	/**
	 * The rule that refused, as a kebab-case slug.
	 *
	 * @return string The slug for the response's `error` field.
	 *
	 * @spec openspec/changes/refusals-carry-a-status/specs/quality-gates/spec.md
	 */
	public function getRule(): string {
		return $this->rule;
	}//end getRule()

	/**
	 * The sentence a caller may show.
	 *
	 * Separate from `getMessage()` on purpose: the message is the engine's
	 * code, and controllers in this app never return an exception message to
	 * a client. This one is written as prose at the throw site.
	 *
	 * @return string The static sentence.
	 *
	 * @spec openspec/changes/refusals-carry-a-status/specs/quality-gates/spec.md
	 */
	public function getSentence(): string {
		return $this->sentence;
	}//end getSentence()

	/**
	 * The HTTP status this refusal answers with.
	 *
	 * @return int The status code.
	 *
	 * @spec openspec/changes/refusals-carry-a-status/specs/quality-gates/spec.md
	 */
	public function getStatus(): int {
		return $this->status;
	}//end getStatus()
}//end class
