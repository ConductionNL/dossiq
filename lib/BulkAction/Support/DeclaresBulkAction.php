<?php

/**
 * Dossiq bulk-action support: the id and the reason rule the actions share.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category BulkAction
 * @package  OCA\Dossiq\BulkAction\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\BulkAction\Support;

use InvalidArgumentException;

/**
 * The action id read from the using class's `ID` constant, and the
 * "a reason is required" rule of the actions that record one.
 *
 * @spec exclude shared plumbing of the bulk actions, covered by their own specs
 */
trait DeclaresBulkAction {

	/**
	 * The action id.
	 *
	 * @return string The id.
	 *
	 * @spec exclude shared plumbing of the bulk actions, covered by their own specs
	 */
	public function getId(): string {
		return self::ID;
	}//end getId()

	/**
	 * Refuse parameters without a non-blank `reason`.
	 *
	 * @param array<string, mixed> $parameters The parameters.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When the reason is empty.
	 */
	private function requireReason(array $parameters): void {
		if (trim((string)($parameters['reason'] ?? '')) === '') {
			throw new InvalidArgumentException('reason is required');
		}
	}//end requireReason()
}//end trait
