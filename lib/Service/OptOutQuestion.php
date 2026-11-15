<?php

/**
 * Whether one recipient may be sent one message about a case, as the opt-out gate asks it.
 *
 * Built by `OptOutGate::ask()` and handed to the steps that read integriq's answer or fall back when
 * there is none: the address, the message category and the case.
 *
 * @category Service
 * @package  OCA\Dossiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

/**
 * Whether one recipient may be sent one message about a case, as the opt-out gate asks it.
 *
 * @spec openspec/specs/case-message-opt-out/spec.md#requirement-case-mail-asks-integriq-before-it-is-sent-req-coo-001
 */
final class OptOutQuestion {

	/**
	 * Constructor.
	 *
	 * @param string $recipient The address as it will be used.
	 * @param string $category  What kind of message this is.
	 * @param string $caseRef   The case it is about.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly string $recipient,
		public readonly string $category,
		public readonly string $caseRef,
	) {
	}//end __construct()
}//end class
