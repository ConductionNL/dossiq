<?php

/**
 * Contract of InboundMailIntake
 *
 * The public surface of InboundMailIntake that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Dossiq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/dossiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Kernel\Contracts;

use OCA\Dossiq\Service\Email\Filters\FilterVerdict;
use OCA\Dossiq\Service\Email\InboundMessage;

/**
 * Contract of InboundMailIntake.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface InboundMailIntakeInterface {

	/**
	 * File an accepted message on a case, or in the intake inbox.
	 *
	 * @param InboundMessage        $message The message.
	 * @param FilterVerdict         $verdict The pipeline's verdict.
	 * @param array<string, string> $results The four authentication results.
	 * @param string                $caseId  The case the subject named, or ''.
	 *
	 * @return string One of the {@see IntakeLog} outcome values.
	 *
	 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
	 */
	public function fileOnCase(
		InboundMessage $message,
		FilterVerdict $verdict,
		array $results,
		string $caseId,
	): string;

	/**
	 * Take one message through the whole path.
	 *
	 * @param InboundMessage $message The message.
	 *
	 * @return string One of the {@see IntakeLog} outcome values.
	 *
	 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
	 */
	public function process(InboundMessage $message): string;

	/**
	 * Take one batch of messages through the whole path.
	 *
	 * @param array<int, array<string, mixed>> $rows      The message rows.
	 * @param integer                          $accountId The account they came from.
	 * @param string                           $mailbox   The folder they sit in.
	 *
	 * @return array<string, int> A count per outcome.
	 *
	 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
	 */
	public function processBatch(array $rows, int $accountId, string $mailbox): array;
}//end interface
