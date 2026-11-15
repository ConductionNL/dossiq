<?php

/**
 * Contract of ExternalConsultationLinkService
 *
 * The public surface of ExternalConsultationLinkService that other components call, so they depend
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

use RuntimeException;

/**
 * Contract of ExternalConsultationLinkService.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ExternalConsultationLinkServiceInterface {

	/**
	 * Collect the advisory body's comment onto the consultation.
	 *
	 * Only comments written as this consultation's own link count. A
	 * colleague's note on the same case is not advice, and a comment already
	 * collected is not collected twice.
	 *
	 * The verdict is the handler's to name: the body writes prose, and one of
	 * four codified outcomes is a judgement about that prose. The prose is
	 * stored verbatim beside it, so the classification can always be checked
	 * against what was actually written.
	 *
	 * @param string $consultationId The consultation UUID.
	 * @param string $advice The codified outcome the handler reads in the comment.
	 *
	 * @return array<string, mixed> What was recorded, or `collected => false` when there was nothing new.
	 *
	 * @throws RuntimeException When the consultation or its share is not there.
	 *
	 * @spec openspec/specs/case-share-via-shares-leaf/spec.md#requirement-an-external-consultation-rides-the-links-comment-capability-req-cal-03
	 */
	public function collect(string $consultationId, string $advice): array;

	/**
	 * Invite the advisory body on a consultation, over a case access link.
	 *
	 * The link expires on the consultation's own deadline, so an advisory
	 * body that misses it loses the link rather than keeping the case open
	 * indefinitely.
	 *
	 * @param string $consultationId The consultation UUID.
	 * @param string $userId The handler inviting them.
	 * @param string|null $password An optional password, checked at use.
	 *
	 * @return array<string, mixed> The share, the address to send (`responseUrl`) and the message that carries it.
	 *
	 * @throws RuntimeException When the consultation cannot carry a link.
	 *
	 * @spec openspec/specs/case-share-via-shares-leaf/spec.md#requirement-an-external-consultation-rides-the-links-comment-capability-req-cal-03
	 */
	public function invite(string $consultationId, string $userId, ?string $password = null): array;
}//end interface
