<?php

/**
 * Contract of ZgwBusinessRulesService
 *
 * The public surface of ZgwBusinessRulesService that other components call, so they depend
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
 * Contract of ZgwBusinessRulesService.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ZgwBusinessRulesServiceInterface {

	/**
	 * Validate and enrich a request body before saving.
	 *
	 * @param string $zgwApi The ZGW API group (e.g. 'zaken', 'besluiten')
	 * @param string $resource The ZGW resource name (e.g. 'zaken', 'besluiten')
	 * @param string $action The action ('create', 'update', 'patch', 'destroy')
	 * @param array $body The ZGW request body (Dutch field names)
	 * @param array|null $existingObject The existing object data (for update/patch/destroy)
	 * @param object|null $objectService The OpenRegister ObjectService
	 * @param array|null $mappingConfig The mapping config
	 * @param bool|null $parentCaseTypeDraft Whether the parent zaaktype isDraft (for ztc-010)
	 * @param bool|null $caseClosed Whether the (parent) zaak is closed (for zrc-007)
	 * @param bool $hasGeforceerd Whether consumer has geforceerd-bijwerken scope
	 *
	 * @return array{valid: bool, status: int, detail: string, enrichedBody: array}
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList)
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag)    — ZGW scope flag from middleware
	 *
	 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
	 */
	public function validate(
		string $zgwApi,
		string $resource,
		string $action,
		array $body,
		?array $existingObject = null,
		?object $objectService = null,
		?array $mappingConfig = null,
		?bool $parentCaseTypeDraft = null,
		?bool $caseClosed = null,
		bool $hasGeforceerd = true,
	): array;
}//end interface
