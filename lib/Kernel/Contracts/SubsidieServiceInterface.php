<?php

/**
 * Contract of SubsidieService
 *
 * The public surface of SubsidieService that other components call, so they depend
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
 * Contract of SubsidieService.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface SubsidieServiceInterface {

	/**
	 * Default AWB 4:13 decision term in weeks when the regeling is silent.
	 */
	public const DEFAULT_AANVRAAG_TERMIJN_WEKEN = 13;

	/**
	 * Create a subsidieaanvraag in status "received", binding the AWB
	 * decision term (REQ-SUB-002).
	 *
	 * @param array<string, mixed> $payload The aanvraag properties.
	 * @param int $termWeken The regeling decision term.
	 *
	 * @return array<string, mixed> The created aanvraag record.
	 *
	 * @throws OCSBadRequestException When OpenRegister is unavailable/unconfigured.
	 *
	 * @spec openspec/specs/subsidieverlening-keten/spec.md#requirement-req-sub-002-awb-termijn-binding-for-each-phase
	 */
	public function createAanvraag(array $payload, int $termWeken = self::DEFAULT_AANVRAAG_TERMIJN_WEKEN): array;

	/**
	 * List subsidieaanvragen, optionally filtered.
	 *
	 * @param array<string, mixed> $filters Optional status/regeling/handler filters.
	 *
	 * @return array<int, array<string, mixed>> The aanvragen.
	 *
	 * @throws OCSBadRequestException When OpenRegister is unavailable/unconfigured.
	 *
	 * @spec openspec/specs/subsidieverlening-keten/spec.md#requirement-req-sub-002-awb-termijn-binding-for-each-phase
	 */
	public function listAanvragen(array $filters = []): array;

	/**
	 * Transition an aanvraag to a new status, enforcing the state machine.
	 *
	 * @param string $id The aanvraag id.
	 * @param string $toStatus The target status.
	 *
	 * @return array<string, mixed> The updated aanvraag record.
	 *
	 * @throws OCSBadRequestException When the transition is illegal or persistence fails.
	 *
	 * @spec openspec/specs/subsidieverlening-keten/spec.md#requirement-req-sub-002-awb-termijn-binding-for-each-phase
	 */
	public function transitionAanvraag(string $id, string $toStatus): array;
}//end interface
