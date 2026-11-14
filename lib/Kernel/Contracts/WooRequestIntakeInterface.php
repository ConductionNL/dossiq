<?php

/**
 * Contract of WooRequestIntake
 *
 * The public surface of WooRequestIntake that other components call, so they depend
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

use OCA\Dossiq\Woo\WooRequestRefused;

/**
 * Contract of WooRequestIntake.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface WooRequestIntakeInterface {

	/**
	 * The seeded Woo request case type (register.d/81-woo-verzoek.json).
	 */
	public const CASE_TYPE_ID = '3c0f5a00-0000-4000-a000-00000000a001';

	/**
	 * The identifier every version of the Woo request case type shares (the
	 * version chain is read by identifier).
	 *
	 * @var string
	 */
	public const CASE_TYPE_IDENTIFIER = 'woo-verzoek';

	/**
	 * Receive a Woo request in opencatalogi's shape and open its case.
	 *
	 * The form submit creates the case directly (decision 179): nothing is
	 * queued in between, and a request that cannot become a valid case is
	 * refused here with nothing written.
	 *
	 * @param array<string, mixed> $answers    `{requestedInformation, requesterName?, requesterEmail?,
	 *                                         requesterPhone?, requesterAddress?, channel?, subjectRef?,
	 *                                         originReference?}`.
	 * @param string               $receivedAt When the requester sent it (ISO 8601); now when empty or unreadable.
	 * @param string               $origin     `portal-form` or `opencatalogi`.
	 *
	 * @return array{outcome: string, requestId: string, reference: string, dueAt: string, message: string, caseUrl: string}
	 *
	 * @spec openspec/changes/woo-request-takes-over-from-opencatalogi/specs/woo-request-intake/spec.md#requirement-dossiq-receives-a-woo-request-in-opencatalogis-shape-req-wto-001
	 * @spec openspec/changes/woo-request-takes-over-from-opencatalogi/specs/woo-request-intake/spec.md#requirement-armed-means-a-term-runs-counted-from-when-the-requester-sent-it-req-wto-002
	 */
	public function receive(array $answers, string $receivedAt = '', string $origin = 'portal-form'): array;

	/**
	 * Open a Woo request.
	 *
	 * @param array<string, mixed> $request `{subjectRef, collectionId?, onderwerp, omschrijving,
	 *                                      periodeVan, periodeTot, origin, originReference}`.
	 *
	 * @return array{caseId: string, caseUrl: string, identifier?: string, deadline?: string}
	 *
	 * @throws WooRequestRefused When the request is unusable, the dossier belongs to someone else,
	 *                           or the register or case type is missing.
	 *
	 * @spec openspec/specs/woo-request-intake/spec.md#requirement-one-service-creates-every-woo-request-req-wri-002
	 * @spec openspec/specs/woo-request-intake/spec.md#requirement-only-the-owners-dossier-starts-a-request-req-wri-003
	 * @spec openspec/specs/woo-request-intake/spec.md#requirement-the-dossier-records-the-request-it-started-req-wri-004
	 * @spec openspec/changes/portal-submission-is-made-once/specs/woo-request-intake/spec.md#requirement-the-dossier-is-linked-to-the-case-after-the-case-is-answered-req-psm-002
	 */
	public function start(array $request): array;
}//end interface
