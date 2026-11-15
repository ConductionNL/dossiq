<?php

/**
 * Contract of BeschikkingService
 *
 * The public surface of BeschikkingService that other components call, so they depend
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

use OCP\AppFramework\OCS\OCSBadRequestException;

/**
 * Contract of BeschikkingService.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface SubsidieBeschikkingServiceInterface {

	/**
	 * Create a draft beschikking with a generated beschikkingnummer.
	 *
	 * @param string $requestId The application id.
	 * @param array<string, mixed> $payload The beschikking properties.
	 * @param int $sequence The running beschikking sequence.
	 *
	 * @return array<string, mixed> The created beschikking record.
	 *
	 * @throws OCSBadRequestException When validation/persistence fails.
	 *
	 * @spec openspec/specs/subsidieverlening-keten/spec.md#requirement-req-sub-001-multi-year-beschikking-with-voorschot-schema
	 */
	public function createDraft(string $requestId, array $payload, int $sequence): array;

	/**
	 * Publish a beschikking, stamping the publicatiedatum and bezwaartermijn.
	 *
	 * @param string $decisionId The beschikking id.
	 *
	 * @return array<string, mixed> The published beschikking record.
	 *
	 * @throws OCSBadRequestException When the beschikking is unsigned or persistence fails.
	 *
	 * @spec openspec/specs/subsidieverlening-keten/spec.md#requirement-req-sub-006-subsidieregister-publication-feed
	 */
	public function publish(string $decisionId): array;

	/**
	 * Record a digital signature on a beschikking (REQ — security policy).
	 * The signer identity is always derived from the session, never trusted
	 * from the request body.
	 *
	 * @param string $decisionId The beschikking id.
	 *
	 * @return array<string, mixed> The signed beschikking record.
	 *
	 * @throws OCSBadRequestException When unauthenticated or persistence fails.
	 *
	 * @spec openspec/specs/subsidieverlening-keten/spec.md#requirement-req-sub-001-multi-year-beschikking-with-voorschot-schema
	 */
	public function sign(string $decisionId): array;
}//end interface
