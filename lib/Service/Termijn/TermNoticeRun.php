<?php

/**
 * One send of a term notice: the notice, the key it is claimed under and its opt-out category.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Termijn
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
 * @spec openspec/changes/termijn-notices-send/specs/burger-notifications/spec.md#requirement-a-term-notice-is-mailed-after-integriq-allows-it-req-term-070
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Termijn;

/**
 * The notice TermNoticeSender is sending, with what it derived once.
 *
 * @spec openspec/changes/termijn-notices-send/specs/burger-notifications/spec.md#requirement-a-term-notice-is-sent-once-per-deadline-req-term-071
 */
final class TermNoticeRun {
	/**
	 * Constructor.
	 *
	 * @param TermNotice $notice   The notice.
	 * @param string     $key      The key it is claimed under.
	 * @param string     $category The opt-out category it is asked under.
	 */
	public function __construct(
		public readonly TermNotice $notice,
		public readonly string $key,
		public readonly string $category,
	) {
	}//end __construct()
}//end class
