<?php

/**
 * Contract of TermijnNotificationService
 *
 * The public surface of TermijnNotificationService that other components call, so they depend
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

use OCA\Dossiq\Exception\NoticeNotSentException;

/**
 * Contract of TermijnNotificationService.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface TermijnNotificationServiceInterface {

	/**
	 * Send a templated termijnbewaking notification.
	 *
	 * @param string $type Template type.
	 * @param string $termInstanceId Instance id.
	 * @param string $recipientUserId The address the caller holds for the requester, or ''.
	 * @param array<string, mixed> $context Extra context (zaak ref, dates, amounts).
	 * @param array<string, mixed> $requester The case row, when the caller has it: the sender
	 *        then also offers the portal inbox and digital post. [] keeps to the address.
	 * @return array<string, mixed> Dispatched payload (with rendered subject +
	 *                              body and the `dispatch` delivery result, status `sent`).
	 * @throws NoticeNotSentException When nothing was sent; its getDelivery() is the
	 *         not-sent result. A throw rather than a returned `not-sent`, because every
	 *         caller already treats it as "nothing went out" and a caller that forgets to
	 *         read a status cannot record a notice nobody received as sent.
	 * @spec openspec/changes/archive/2026-06-13-termijnbewaking-dwangsom-engine-08-burger-notifications/tasks.md
	 * @spec openspec/changes/termijn-notices-send/specs/burger-notifications/spec.md#requirement-a-term-notice-is-mailed-after-integriq-allows-it-req-term-070
	 * @spec openspec/changes/woo-requester-notices-really-go-out/specs/burger-notifications/spec.md#requirement-a-requester-notice-goes-out-through-a-real-channel-or-is-recorded-as-not-sent-req-wrn-001
	 * @spec openspec/specs/burger-notifications/spec.md#requirement-a-case-type-may-send-its-acknowledgement-by-e-mail-as-well-req-ack-copy-001
	 */
	public function sendTermijnNotification(
		string $type,
		string $termInstanceId,
		string $recipientUserId,
		array $context = [],
		array $requester = [],
	): array;
}//end interface
