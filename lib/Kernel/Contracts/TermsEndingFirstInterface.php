<?php

/**
 * Contract of TermsEndingFirst
 *
 * The public surface of TermsEndingFirst that other components call, so they depend
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

use DateTimeImmutable;
use Throwable;

/**
 * Contract of TermsEndingFirst.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface TermsEndingFirstInterface {

	public const DEFAULT_LIMIT = 5;

	/**
	 * The running statutory terms, soonest end first.
	 *
	 * @param int                    $limit How many to answer, clamped to 1..MAX_LIMIT.
	 * @param DateTimeImmutable|null $now   The moment to judge against.
	 *
	 * @return array{available: bool, terms: list<array<string, mixed>>}
	 *
	 * @throws Throwable When the terms cannot be read; an empty list would read "nothing is running".
	 *
	 * @spec openspec/changes/lists-and-dashboards-as-drawn/specs/termijn-reporting/spec.md#requirement-the-term-dashboard-lists-the-terms-that-end-first
	 */
	public function list(int $limit = self::DEFAULT_LIMIT, ?DateTimeImmutable $now = null): array;
}//end interface
