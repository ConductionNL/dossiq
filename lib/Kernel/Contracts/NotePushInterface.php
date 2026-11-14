<?php

/**
 * Contract of NotePush
 *
 * The public surface of NotePush that other components call, so they depend
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
 * Contract of NotePush.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface NotePushInterface {

	/**
	 * Send one note, or say why it stayed here.
	 *
	 * @param string               $caseId The case the note is on.
	 * @param array<string, mixed> $note   The note as OpenRegister answers it.
	 *
	 * @return array{outcome: string, reason: string, receiverUrl: string, caseRecord: string} What happened.
	 *
	 * @spec openspec/specs/zgw-api-mapping/spec.md
	 */
	public function push(string $caseId, array $note): array;
}//end interface
