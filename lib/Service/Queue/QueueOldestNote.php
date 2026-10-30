<?php

/**
 * The line under the team queue's "Waiting longest" tile
 *
 * The oldest waiting case's type and the days to its deadline ("Woo-verzoek,
 * termijn over 4 dagen", DqTeamwachtrij), else the plain line.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Queue
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
 * @spec openspec/specs/werkvoorraad-intelligent-queue/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Queue;

use OCP\IL10N;

/**
 * Words the oldest waiting case for the "Waiting longest" tile.
 *
 * @spec openspec/specs/werkvoorraad-intelligent-queue/spec.md
 */
class QueueOldestNote {
	/**
	 * Constructor.
	 *
	 * @param IL10N $l10n The reader's language.
	 */
	public function __construct(
		private readonly IL10N $l10n,
	) {
	}//end __construct()

	/**
	 * The tile's line.
	 *
	 * @param string   $type    The oldest case's type title, '' when unknown.
	 * @param int|null $dueDays Days to its deadline, negative once passed; null without one.
	 *
	 * @return string The line.
	 *
	 * @spec openspec/specs/werkvoorraad-intelligent-queue/spec.md
	 */
	public function line(string $type, ?int $dueDays): string {
		if ($type === '' || $dueDays === null) {
			return $this->l10n->t('the oldest case in the queue');
		}

		if ($dueDays === 0) {
			return $this->l10n->t('%s, deadline today', [$type]);
		}

		if ($dueDays < 0) {
			return $this->l10n->n('%s, %n day overdue', '%s, %n days overdue', -$dueDays, [$type]);
		}

		return $this->l10n->n('%s, deadline in %n day', '%s, deadline in %n days', $dueDays, [$type]);
	}//end line()
}//end class
