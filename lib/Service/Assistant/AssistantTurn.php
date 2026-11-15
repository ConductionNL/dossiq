<?php

/**
 * One conversational turn on a case: who asks, about which case, and what they said.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Assistant
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
 * @spec openspec/specs/case-assistant-via-hermiq/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Assistant;

/**
 * One conversational turn on a case: who asks, about which case, and what they said.
 *
 * @spec openspec/specs/case-assistant-via-hermiq/spec.md
 */
final class AssistantTurn {

	/**
	 * Constructor.
	 *
	 * @param string $userId  The authenticated caller's user id.
	 * @param string $caseId  The case id.
	 * @param string $message The user's message text.
	 *
	 * @spec openspec/specs/case-assistant-via-hermiq/spec.md
	 */
	public function __construct(
		public readonly string $userId,
		public readonly string $caseId,
		public readonly string $message,
	) {
	}//end __construct()
}//end class
