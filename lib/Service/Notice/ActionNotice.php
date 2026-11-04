<?php

/**
 * Dossiq ActionNotice.
 *
 * Builds the `notice` an action endpoint answers with (the A1 card notice
 * envelope): `{ variant, role, title, text }`. A card shows it in place of a
 * toast. The role follows the variant: `alert` for an error, `status` else.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Notice
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
 * @spec openspec/changes/case-page-answers-in-card-notices/specs/case-management/spec.md#requirement-an-action-on-the-case-page-answers-with-a-card-notice
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Notice;

/**
 * The card notice envelope.
 *
 * @spec openspec/changes/case-page-answers-in-card-notices/specs/case-management/spec.md#requirement-an-action-on-the-case-page-answers-with-a-card-notice
 */
final class ActionNotice {

	/**
	 * A notice in one variant.
	 *
	 * @param string $variant One of info, warning, error, success.
	 * @param string $title   The bold lead, already translated.
	 * @param string $text    The sentence after it, already translated.
	 *
	 * @return array{variant: string, role: string, title: string, text: string}
	 *
	 * @spec openspec/changes/case-page-answers-in-card-notices/specs/case-management/spec.md#requirement-an-action-on-the-case-page-answers-with-a-card-notice
	 */
	public static function of(string $variant, string $title, string $text): array {
		$role = 'status';
		if ($variant === 'error') {
			$role = 'alert';
		}

		return ['variant' => $variant, 'role' => $role, 'title' => $title, 'text' => $text];
	}//end of()

	/**
	 * A success notice.
	 *
	 * @param string $title The bold lead.
	 * @param string $text  The sentence after it.
	 *
	 * @return array{variant: string, role: string, title: string, text: string}
	 *
	 * @spec openspec/changes/case-page-answers-in-card-notices/specs/case-management/spec.md#requirement-an-action-on-the-case-page-answers-with-a-card-notice
	 */
	public static function success(string $title, string $text): array {
		return self::of(variant: 'success', title: $title, text: $text);
	}//end success()

	/**
	 * An error notice, read out at once (role alert).
	 *
	 * @param string $title The bold lead.
	 * @param string $text  The sentence after it.
	 *
	 * @return array{variant: string, role: string, title: string, text: string}
	 *
	 * @spec openspec/changes/case-page-answers-in-card-notices/specs/case-management/spec.md#requirement-an-action-on-the-case-page-answers-with-a-card-notice
	 */
	public static function error(string $title, string $text): array {
		return self::of(variant: 'error', title: $title, text: $text);
	}//end error()
}//end class
