<?php

/**
 * Contract of PortalWooAnswer
 *
 * The public surface of PortalWooAnswer that other components call, so they depend
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

use OCA\Dossiq\Portal\WooAnswerSubmission;

/**
 * Contract of PortalWooAnswer.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PortalWooAnswerInterface {

	/**
	 * Record the answer, or refuse with nothing written.
	 *
	 * Every request that is not the caller's, not open, or not the open
	 * request of a case of the same subject answers 404: the caller learns
	 * nothing about a request that is not theirs.
	 *
	 * @param WooAnswerSubmission $submission The subject portaliq vouches for, the request, the answer and its files.
	 *
	 * @return array{status: int, body: array<string, mixed>} The HTTP status and body.
	 *
	 * @spec openspec/changes/woo-dossier-shared-with-the-requester/specs/portal-contribution/spec.md#requirement-the-requester-answers-a-question-from-mijn-zaken-and-the-answer-lands-on-the-open-request-req-wds-001
	 */
	public function submit(WooAnswerSubmission $submission): array;
}//end interface
