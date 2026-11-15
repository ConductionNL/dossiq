<?php

/**
 * Dossiq ScreenedMessage.
 *
 * An inbound message after screening: the message, the filter pipeline's
 * verdict on it and the four authentication results.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Email
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git_id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Email;

use OCA\Dossiq\Service\Email\Filters\FilterVerdict;

/**
 * An inbound message with the pipeline's verdict and its authentication results.
 *
 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
 */
final class ScreenedMessage {

	/**
	 * Constructor.
	 *
	 * @param InboundMessage        $message The message.
	 * @param FilterVerdict         $verdict The pipeline's verdict.
	 * @param array<string, string> $results The four authentication results.
	 */
	public function __construct(
		public readonly InboundMessage $message,
		public readonly FilterVerdict $verdict,
		public readonly array $results,
	) {
	}//end __construct()
}//end class
