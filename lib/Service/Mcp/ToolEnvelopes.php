<?php

/**
 * The answers a curated tool gives when it does not do what it was asked.
 *
 * An assistant reads the tool result as data, so a refusal is an envelope with
 * a stable code and a sentence, never an exception that becomes a stack trace
 * in a chat.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Mcp
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
 * @spec openspec/changes/hermiq-ai-tooling/specs/mcp-integration/spec.md#requirement-req-mcp-204-guard-enforcing-write-tools-one-per-action-on-the-owning-service
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Mcp;

use OCA\Dossiq\Exception\RefusedException;
use Throwable;

/**
 * Error envelopes shared by the curated tool classes.
 *
 * @spec openspec/changes/hermiq-ai-tooling/specs/mcp-integration/spec.md#requirement-req-mcp-204-guard-enforcing-write-tools-one-per-action-on-the-owning-service
 */
trait ToolEnvelopes {

	/**
	 * An error envelope the assistant can read back.
	 *
	 * @param string $code    A stable machine code.
	 * @param string $message A sentence for the person.
	 *
	 * @return array{error: string, message: string}
	 */
	private function error(string $code, string $message): array {
		return ['error' => $code, 'message' => $message];
	}//end error()

	/**
	 * The envelope for a failure the owning service raised.
	 *
	 * A refusal carries its own rule and sentence. Any other exception from
	 * the service is a refusal the service stated in its message: the same
	 * text its controller answers the browser with.
	 *
	 * @param Throwable $e The failure.
	 *
	 * @return array{error: string, message: string}
	 */
	private function refusal(Throwable $e): array {
		if ($e instanceof RefusedException) {
			return $this->error(code: $e->getRule(), message: $e->getSentence());
		}

		return $this->error(code: 'refused', message: $e->getMessage());
	}//end refusal()
}//end trait
