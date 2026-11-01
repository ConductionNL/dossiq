<?php

/**
 * Dossiq NewSession value object.
 *
 * A session record to create, with what to log when saving fails and the audit entry written once it is saved.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Hearing
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
 * @spec openspec/specs/bezwaar-hearing/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Hearing;

/**
 * A record to create and the audit entry that goes with it.
 *
 * @spec openspec/specs/bezwaar-hearing/spec.md
 */
class NewSession {
	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $record        The record to save.
	 * @param string               $action        What is being done, for the log line and the refusal.
	 * @param string               $event         Audit event slug.
	 * @param array<string, mixed> $auditPayload  Audit entry payload.
	 * @param string               $tag           Audit tag.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly array $record,
		public readonly string $action,
		public readonly string $event,
		public readonly array $auditPayload,
		public readonly string $tag,
	) {
	}//end __construct()
}//end class
