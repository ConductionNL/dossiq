<?php

/**
 * Contract of AccrualNoticeService
 *
 * The public surface of AccrualNoticeService that other components call, so they depend
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

use DateTimeImmutable;
use RuntimeException;

/**
 * Contract of AccrualNoticeService.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface AccrualNoticeServiceInterface {

	/**
	 * Why a notice received on this day would be premature, or '' when the term has passed.
	 *
	 * @param array<string, mixed> $instance    The term instance.
	 * @param DateTimeImmutable    $receiptDate The day the notice was received.
	 *
	 * @return string The reason, in the handler's terms, or '' when the notice may be registered.
	 *
	 * @spec openspec/changes/notice-of-default-from-the-case/specs/ingebrekestelling/spec.md#requirement-a-handler-registers-a-notice-of-default-on-the-case-req-ing-case-01
	 */
	public function prematureReason(array $instance, DateTimeImmutable $receiptDate): string;

	/**
	 * Register an ingebrekestelling against a TermijnInstance.
	 *
	 * @param string $termInstanceId TermijnInstance id.
	 * @param DateTimeImmutable $receiptDate Receipt date.
	 * @param string $channel Receipt channel.
	 * @param string $documentLink Document link.
	 * @param string $sender Who sent the notice; empty when not recorded.
	 *
	 * @return array<string, mixed> The ingebrekestelling row (with possibly null/created berekening).
	 *
	 * @throws RuntimeException When the instance is missing.
	 *
	 * @spec openspec/changes/archive/2026-06-13-termijnbewaking-dwangsom-engine-05-ingebrekestelling/tasks.md
	 * @spec openspec/changes/notice-of-default-from-the-case/specs/ingebrekestelling/spec.md#requirement-a-handler-registers-a-notice-of-default-on-the-case-req-ing-case-01
	 */
	public function registerNotice(
		string $termInstanceId,
		DateTimeImmutable $receiptDate,
		string $channel,
		string $documentLink = '',
		string $sender = '',
	): array;

	/**
	 * The statutory decision term of a case, the one a notice of default is given on.
	 *
	 * A case carries several clocks (statutory, planned, internal, phase); only
	 * the statutory term is owed to the applicant, so only it can be late in
	 * the sense of Awb 4:17. The newest statutory instance wins.
	 *
	 * @param string $caseId The case id.
	 *
	 * @return array<string, mixed>|null The instance, or null when the case has none.
	 *
	 * @spec openspec/changes/notice-of-default-from-the-case/specs/ingebrekestelling/spec.md#requirement-a-handler-registers-a-notice-of-default-on-the-case-req-ing-case-01
	 */
	public function statutoryTermOf(string $caseId): ?array;

	/**
	 * The term instance a notice would be registered against.
	 *
	 * @param string $termInstanceId The TermijnInstance id.
	 *
	 * @return array<string, mixed>|null The instance, or null when it does not exist.
	 *
	 * @spec openspec/changes/notice-of-default-from-the-case/specs/ingebrekestelling/spec.md#requirement-a-handler-registers-a-notice-of-default-on-the-case-req-ing-case-01
	 */
	public function termInstance(string $termInstanceId): ?array;
}//end interface
