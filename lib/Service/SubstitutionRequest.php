<?php

/**
 * A substitution somebody asked to create
 *
 * Who is covered, by whom, for which period and scope, why, and who asked:
 * the input of SubstitutionService::create(), carried as one value through
 * its validation and the row it stores.
 *
 * @category Service
 * @package  OCA\Dossiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/handler-vervanging-waarneming/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

/**
 * The fields of one substitution to create; it never leaves the service that built it.
 *
 * @spec openspec/specs/handler-vervanging-waarneming/spec.md
 */
final class SubstitutionRequest {

	/**
	 * Handler being covered (user id), trimmed.
	 *
	 * @var string
	 */
	public string $absentee = '';

	/**
	 * Waarnemer (user id), trimmed.
	 *
	 * @var string
	 */
	public string $substitute = '';

	/**
	 * Inclusive start (Y-m-d).
	 *
	 * @var string
	 */
	public string $startDate = '';

	/**
	 * Inclusive end (Y-m-d).
	 *
	 * @var string
	 */
	public string $endDate = '';

	/**
	 * One of all|caseTypes|cases.
	 *
	 * @var string
	 */
	public string $scope = 'all';

	/**
	 * CaseType/case UUIDs when narrowed.
	 *
	 * @var array<int, string>
	 */
	public array $scopeRefs = [];

	/**
	 * One of verlof|ziekte|anders.
	 *
	 * @var string
	 */
	public string $reason = 'verlof';

	/**
	 * Creating user id (self or coordinator), or '' for the absentee.
	 *
	 * @var string
	 */
	public string $createdBy = '';

	/**
	 * Optional free-text comment.
	 *
	 * @var string
	 */
	public string $comment = '';
}//end class
