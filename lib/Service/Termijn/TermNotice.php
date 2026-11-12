<?php

/**
 * One term notice to mail: what it was rendered from, where it goes and what it says.
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
 * A rendered term notice for one recipient.
 *
 * @spec openspec/changes/termijn-notices-send/specs/burger-notifications/spec.md#requirement-a-term-notice-is-mailed-after-integriq-allows-it-req-term-070
 */
final class TermNotice {
	/**
	 * Constructor.
	 *
	 * @param string               $template   The template the notice was rendered from.
	 * @param string               $instanceId The term instance, or '' when there is none.
	 * @param string               $recipient  The address, as the caller holds it.
	 * @param string               $caseRef    The case, so a case-scoped opt-out matches.
	 * @param string               $subject    The subject.
	 * @param string               $body       The body, as plain text.
	 * @param string               $dedupeKey  The caller's name for this notice, or '' to key on its content.
	 * @param array<string, mixed> $case       The case row, when the caller has it.
	 */
	public function __construct(
		public readonly string $template,
		public readonly string $instanceId,
		public readonly string $recipient,
		public readonly string $caseRef,
		public readonly string $subject,
		public readonly string $body,
		public readonly string $dedupeKey = '',
		public readonly array $case = [],
	) {
	}//end __construct()

	/**
	 * The address the notice is mailed to: trimmed and lower case.
	 *
	 * @return string The address.
	 *
	 * @spec openspec/changes/termijn-notices-send/specs/burger-notifications/spec.md#requirement-a-term-notice-is-mailed-after-integriq-allows-it-req-term-070
	 */
	public function address(): string {
		return strtolower(trim($this->recipient));
	}//end address()
}//end class
