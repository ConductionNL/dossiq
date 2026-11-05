<?php

/**
 * Dossiq CaseActNotices.
 *
 * The card notices the case page acts answer with (the A1 card notice
 * envelope): `{ variant, role, title, text }`, in the reader's language. A
 * card shows the notice in place of a toast. The role follows the variant:
 * `alert` for an error, `status` otherwise.
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

use OCP\IL10N;

/**
 * Builds the card notices of the case page acts.
 *
 * @spec openspec/changes/case-page-answers-in-card-notices/specs/case-management/spec.md#requirement-an-action-on-the-case-page-answers-with-a-card-notice
 */
class CaseActNotices {

	/**
	 * Constructor.
	 *
	 * @param IL10N $l10n Translates the notices.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IL10N $l10n,
	) {
	}//end __construct()

	/**
	 * A task was completed.
	 *
	 * @param string $task The task's title.
	 *
	 * @return array{variant: string, role: string, title: string, text: string}
	 *
	 * @spec openspec/changes/case-page-answers-in-card-notices/specs/case-management/spec.md#requirement-an-action-on-the-case-page-answers-with-a-card-notice
	 */
	public function taskCompleted(string $task): array {
		return $this->build(
			variant: 'success',
			title: $this->l10n->t('Task %s completed.', [$task]),
			text: $this->l10n->t('You stay on the case; the list is updated.')
		);
	}//end taskCompleted()

	/**
	 * The engine refused to cancel a task, for its own reason.
	 *
	 * @param string $task   The task's title.
	 * @param string $reason The engine's reason.
	 *
	 * @return array{variant: string, role: string, title: string, text: string}
	 *
	 * @spec openspec/changes/case-page-answers-in-card-notices/specs/case-management/spec.md#requirement-an-action-on-the-case-page-answers-with-a-card-notice
	 */
	public function cancelRefused(string $task, string $reason): array {
		return $this->build(
			variant: 'error',
			title: $this->l10n->t('Cancelling refused for %s:', [$task]),
			text: $reason
		);
	}//end cancelRefused()

	/**
	 * A subject link the RGBZ forbids.
	 *
	 * @param string $rule   `subject_taken` or `subject_cycle`.
	 * @param string $target The case that would hang as the subject.
	 * @param string $other  The case it already hangs under, or the case that would hold it.
	 *
	 * @return array{variant: string, role: string, title: string, text: string}
	 *
	 * @spec openspec/changes/case-page-answers-in-card-notices/specs/related-case-linking/spec.md#requirement-a-link-the-rgbz-forbids-is-refused-in-a-card-notice
	 */
	public function linkRefused(string $rule, string $target, string $other): array {
		$text = $this->l10n->t('%1$s already hangs as the subject under %2$s. A case can hang as the subject under one case only.', [$target, $other]);
		if ($rule === 'subject_cycle') {
			$text = $this->l10n->t('%1$s already hangs, directly or further up, under %2$s. A subject cannot hang under its own subject.', [$other, $target]);
		}

		return $this->build(variant: 'error', title: $this->l10n->t('Not linked:'), text: $text);
	}//end linkRefused()

	/**
	 * OpenRegister refused to revoke a share.
	 *
	 * @return array{variant: string, role: string, title: string, text: string}
	 *
	 * @spec openspec/changes/case-page-answers-in-card-notices/specs/case-share-via-shares-leaf/spec.md#requirement-the-case-lists-its-shares-and-a-refused-revoke-is-a-card-notice
	 */
	public function revokeRefused(): array {
		return $this->build(
			variant: 'error',
			title: $this->l10n->t('Revoking refused by OpenRegister:'),
			text: $this->l10n->t('a link is revoked by the colleague who created it.')
		);
	}//end revokeRefused()

	/**
	 * One notice.
	 *
	 * @param string $variant One of info, warning, error, success.
	 * @param string $title   The bold lead.
	 * @param string $text    The sentence after it.
	 *
	 * @return array{variant: string, role: string, title: string, text: string}
	 */
	private function build(string $variant, string $title, string $text): array {
		$role = 'status';
		if ($variant === 'error') {
			$role = 'alert';
		}

		return ['variant' => $variant, 'role' => $role, 'title' => $title, 'text' => $text];
	}//end build()
}//end class
