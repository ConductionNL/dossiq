<?php

/**
 * Contract of RetentionClocks
 *
 * The public surface of RetentionClocks that other components call, so they depend
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
 * Contract of RetentionClocks.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface RetentionClocksInterface {

	/**
	 * Both clocks of one case, each labelled and each naming its rule.
	 *
	 * @param array<string, mixed> $case The case payload.
	 *
	 * @return array{lawfulPurpose: array<string, mixed>, archive: array<string, mixed>, disagree: bool}
	 *
	 * @spec openspec/specs/case-management/spec.md
	 */
	public function clocksFor(array $case): array;
}//end interface
