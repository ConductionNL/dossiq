<?php

/**
 * Dossiq Hermiq Assistant Exception.
 *
 * Signals a failure calling Hermiq's case-assistant surface, carrying the
 * HTTP status Hermiq responded with (or a locally-mapped one for transport/
 * configuration failures) plus Hermiq's stable `errorCode`, when present
 * (e.g. `guardrail_blocked`), so `AssistantController` can relay both
 * without matching on message text.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Assistant
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
 * @spec openspec/specs/case-assistant-via-hermiq/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Assistant;

use RuntimeException;
use Throwable;

/**
 * Coded failure from a HermiqAssistantClient call.
 *
 * @spec openspec/specs/case-assistant-via-hermiq/spec.md
 */
class HermiqAssistantException extends RuntimeException {
	/**
	 * Constructor.
	 *
	 * @param string $message Human-readable detail (Hermiq's `message`/`error`, or a local code).
	 * @param int $statusCode The HTTP status to relay.
	 * @param string|null $errorCode Hermiq's stable machine-readable error code, when present.
	 * @param Throwable|null $previous The wrapped transport-layer exception, when any.
	 */
	public function __construct(
		string $message,
		private readonly int $statusCode,
		private readonly ?string $errorCode = null,
		?Throwable $previous = null,
	) {
		parent::__construct(message: $message, code: $statusCode, previous: $previous);
	}//end __construct()

	/**
	 * The HTTP status to relay to the caller.
	 *
	 * @return int
	 *
	 * @spec openspec/specs/case-assistant-via-hermiq/spec.md
	 */
	public function getStatusCode(): int {
		return $this->statusCode;
	}//end getStatusCode()

	/**
	 * Hermiq's stable machine-readable error code, when present.
	 *
	 * @return string|null
	 *
	 * @spec openspec/specs/case-assistant-via-hermiq/spec.md
	 */
	public function getErrorCode(): ?string {
		return $this->errorCode;
	}//end getErrorCode()
}//end class
