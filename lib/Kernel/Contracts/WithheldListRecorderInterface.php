<?php

/**
 * Contract of WithheldListRecorder
 *
 * The public surface of WithheldListRecorder that other components call, so they depend
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
 * Contract of WithheldListRecorder.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface WithheldListRecorderInterface {

	/**
	 * Opencatalogi is installed but has no record door.
	 */
	public const REASON_TOO_OLD = 'opencatalogi-too-old';

	/**
	 * The one sentence the case shows about its withheld list, '' when all went in.
	 *
	 * @param array<string, mixed> $answer What record() answered.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/woo-decision-records-what-was-withheld/specs/woo-publication-via-opencatalogi/spec.md#requirement-without-opencatalogis-receiving-side-nothing-is-recorded-req-wrw-002
	 */
	public function describe(array $answer): string;

	/**
	 * Record the whole withheld list of a publication. An empty list clears it.
	 *
	 * @param string                                            $publicationId The publication.
	 * @param list<array{position: int, grounds: list<string>}> $entries       The entries.
	 *
	 * @return array<string, mixed> `{status: 'recorded', recorded, refused}` or `{status: 'not-recorded', reason}`.
	 *
	 * @spec openspec/changes/woo-decision-records-what-was-withheld/specs/publication-withheld-list/spec.md#requirement-a-publication-records-what-it-withheld-req-pwl-001
	 */
	public function record(string $publicationId, array $entries): array;
}//end interface
