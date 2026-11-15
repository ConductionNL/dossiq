<?php

/**
 * Contract of WooSources
 *
 * The public surface of WooSources that other components call, so they depend
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
 * Contract of WooSources.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface WooSourcesInterface {

	/**
	 * The Microsoft 365 connection integriq holds: SharePoint, Teams and mail.
	 */
	public const SOURCE_MICROSOFT365 = 'microsoft365';

	/**
	 * Every source with whether it can be searched now, and how.
	 *
	 * A source that cannot answer is listed with the reason, never left out
	 * (REQ-WOO-012 "A source that is not connected").
	 *
	 * @return array<int, array<string, mixed>> Each `{id, label, kind, available, reason, search}`.
	 *
	 * @spec openspec/changes/woo-requests-gather-documents-from-sources/specs/woo-case-type/spec.md#requirement-a-handler-searches-the-organisations-sources-from-a-woo-case-req-woo-012
	 */
	public function list(): array;

	/**
	 * Search integriq's connection for terms in a period, as one person.
	 *
	 * @param string      $terms  The search terms.
	 * @param string|null $from   Start of the period, Y-m-d.
	 * @param string|null $to     End of the period, Y-m-d.
	 * @param string      $userId The person searching; mail and chat stay within what they may read.
	 *
	 * @return array{rows: array<int, array<string, mixed>>, remaining: int, notices: array<int, string>, refusal: string}
	 *         `refusal` is empty when integriq answered.
	 *
	 * @spec openspec/changes/woo-requests-gather-documents-from-sources/specs/woo-case-type/spec.md#requirement-a-handler-searches-the-organisations-sources-from-a-woo-case-req-woo-012
	 */
	public function searchMicrosoft365(string $terms, ?string $from, ?string $to, string $userId): array;
}//end interface
