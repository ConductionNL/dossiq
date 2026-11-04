<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Zgw;

/**
 * What the business rules are asked about a write: the action and the parent state flags.
 *
 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
 */
final class RuleFlags {
	/**
	 * Constructor.
	 *
	 * @param string    $action              The action: create, update, patch or destroy.
	 * @param bool|null $parentCaseTypeDraft Whether the parent case type is a draft.
	 * @param bool|null $caseClosed          Whether the parent case is closed.
	 * @param bool      $hasGeforceerd       Whether the consumer may force an update.
	 */
	public function __construct(
		public readonly string $action,
		public readonly ?bool $parentCaseTypeDraft,
		public readonly ?bool $caseClosed,
		public readonly bool $hasGeforceerd,
	) {
	}//end __construct()

	/**
	 * Whether the write is a partial update.
	 *
	 * @return bool True for a patch.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function isPatch(): bool {
		return $this->action === 'patch';
	}//end isPatch()
}//end class
