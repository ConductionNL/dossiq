<?php

/**
 * Re-syncs the case role vocabulary after a change, logging a failure.
 *
 * The using class provides `$vocabulary` (CaseRoleVocabularyInterface) and `$logger`.
 *
 * @category Listener
 * @package  OCA\Dossiq\Listener\Support
 *
 * @author    Conduction Development Team <dev@conduction.nl>
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
 * @spec exclude shared step of several listeners, covered by their own specs
 */

declare(strict_types=1);

namespace OCA\Dossiq\Listener\Support;

use Throwable;

/**
 * Re-syncs the case role vocabulary, warning instead of failing.
 *
 * @spec exclude shared step of several listeners, covered by their own specs
 */
trait ResyncsRoleVocabulary {
	/**
	 * Re-sync the vocabulary; a failure is logged as a warning naming the change.
	 *
	 * @param string $change What changed, as the warning names it.
	 *
	 * @return void
	 */
	private function resyncVocabularyAfter(string $change): void {
		try {
			$this->vocabulary->sync();
		} catch (Throwable $e) {
			$this->logger->warning(
				'Dossiq people: the case role vocabulary was not re-synced after ' . $change . ': ' . $e->getMessage()
			);
		}
	}//end resyncVocabularyAfter()
}//end trait
