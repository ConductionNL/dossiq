<?php

/**
 * What resolving a conflict record came to: an HTTP status and its body.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Sync
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
 * @spec openspec/changes/inspection-conflict-card/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Sync;

/**
 * The status and body a resolve request answers with.
 *
 * @spec openspec/changes/inspection-conflict-card/tasks.md
 */
final class ConflictResolutionOutcome {

	/**
	 * Constructor.
	 *
	 * @param int                  $status The HTTP status.
	 * @param array<string, mixed> $body   The response body.
	 *
	 * @spec openspec/changes/inspection-conflict-card/tasks.md
	 */
	public function __construct(
		public readonly int $status,
		public readonly array $body,
	) {
	}//end __construct()

	/**
	 * A refusal with a message.
	 *
	 * @param int    $status  The HTTP status.
	 * @param string $message The message the card shows.
	 *
	 * @return self
	 *
	 * @spec openspec/changes/inspection-conflict-card/tasks.md
	 */
	public static function refused(int $status, string $message): self {
		return new self(status: $status, body: ['message' => $message]);
	}//end refused()
}//end class
