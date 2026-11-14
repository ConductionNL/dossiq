<?php

/**
 * Contract of WooCollectionQueries
 *
 * The public surface of WooCollectionQueries that other components call, so they depend
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

use OCA\Dossiq\Woo\WooCorpusRefused;

/**
 * Contract of WooCollectionQueries.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface WooCollectionQueriesInterface {

	/**
	 * Every stored query of the case.
	 *
	 * @param string $caseId The case uuid.
	 *
	 * @return array<int, array<string, mixed>> The queries.
	 *
	 * @spec openspec/changes/woo-request-corpus-collection/specs/woo-case-type/spec.md#requirement-the-selecting-query-is-saved-and-can-be-re-run-req-wrc-004
	 */
	public function forCase(string $caseId): array;

	/**
	 * Run a stored query again as the caller and mark what is new.
	 *
	 * @param string $caseId  The case uuid.
	 * @param string $queryId The stored query's uuid.
	 * @param string $userId  The caller.
	 *
	 * @return array{rows: array<int, array<string, mixed>>, refusal: string} Each row with `new`.
	 *
	 * @throws WooCorpusRefused 404 when the query is not one of this case.
	 *
	 * @spec openspec/changes/woo-request-corpus-collection/specs/woo-case-type/spec.md#requirement-the-selecting-query-is-saved-and-can-be-re-run-req-wrc-004
	 */
	public function rerun(string $caseId, string $queryId, string $userId): array;

	/**
	 * Store one search.
	 *
	 * @param string               $caseId The case uuid.
	 * @param array<string, mixed> $search `{source, terms, periodFrom, periodTo, filters, resultKeys}`.
	 * @param string               $userId Who ran it.
	 *
	 * @return array<string, mixed> The query as stored.
	 *
	 * @throws WooCorpusRefused 400 for an unknown source.
	 *
	 * @spec openspec/changes/woo-request-corpus-collection/specs/woo-case-type/spec.md#requirement-the-selecting-query-is-saved-and-can-be-re-run-req-wrc-004
	 */
	public function store(string $caseId, array $search, string $userId): array;
}//end interface
