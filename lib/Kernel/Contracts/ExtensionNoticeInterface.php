<?php

/**
 * Contract of ExtensionNotice
 *
 * The public surface of ExtensionNotice that other components call, so they depend
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

/**
 * Contract of ExtensionNotice.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ExtensionNoticeInterface {

	/**
	 * Tell the requester of a case that its term was extended.
	 *
	 * @param string $caseId     The case UUID.
	 * @param string $instanceId The extended term instance.
	 * @param string $reason     Why the term was extended.
	 * @param string $newEnd     The new end date (Y-m-d).
	 *
	 * @return array{noticeStatus: string, noticeChannel: string, noticeReasonCode: string, noticeReason: string}
	 *
	 * @spec openspec/changes/woo-requester-notices-really-go-out/specs/burger-notifications/spec.md#requirement-an-extension-reaches-the-requester-with-its-reason-req-wrn-005
	 */
	public function tell(string $caseId, string $instanceId, string $reason, string $newEnd): array;
}//end interface
