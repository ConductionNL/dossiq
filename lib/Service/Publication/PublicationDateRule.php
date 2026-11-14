<?php

/**
 * Dossiq PublicationDateRule.
 *
 * The day a decision's public notice is published, by the rule the VTH tab
 * sets (board DqVthInstellingen, "Bekendmakingen"): on the decision date, or
 * on the first Wednesday from it.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Publication
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
 * @spec openspec/changes/admin-tabs-carry-the-settings-board-blocks/specs/vth-admin-settings/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Publication;

use DateTimeImmutable;

/**
 * Works out the publication date of a public notice.
 *
 * @spec openspec/changes/admin-tabs-carry-the-settings-board-blocks/specs/vth-admin-settings/spec.md
 */
class PublicationDateRule {
	public const FIRST_WEDNESDAY = 'first-wednesday';
	public const DECISION_DATE = 'decision-date';

	/**
	 * The publication moment for a decision taken at `$decided`.
	 *
	 * An unset or unknown rule publishes on the decision date, which is what
	 * every notice did before the rule existed.
	 *
	 * @param string                 $rule    The `publication_date_rule` setting.
	 * @param DateTimeImmutable|null $decided The decision moment; now when null.
	 *
	 * @return DateTimeImmutable
	 *
	 * @spec openspec/changes/admin-tabs-carry-the-settings-board-blocks/specs/vth-admin-settings/spec.md
	 */
	public function publishOn(string $rule, ?DateTimeImmutable $decided = null): DateTimeImmutable {
		$decided = ($decided ?? new DateTimeImmutable());
		if ($rule !== self::FIRST_WEDNESDAY || $decided->format('N') === '3') {
			return $decided;
		}

		return $decided->modify('next wednesday');
	}//end publishOn()
}//end class
