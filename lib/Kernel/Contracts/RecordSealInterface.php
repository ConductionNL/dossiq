<?php

/**
 * Contract of RecordSeal
 *
 * The public surface of RecordSeal that other components call, so they depend
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
use OCA\Dossiq\Service\FrozenRecord\FreezeDefinition;

/**
 * Contract of RecordSeal.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface RecordSealInterface {

	/**
	 * Seal a record's declared fields.
	 *
	 * @param FreezeDefinition $definition The freeze entry.
	 * @param array<string, mixed> $record The record as it freezes.
	 * @param DateTimeImmutable|null $moment When it was sealed; now when null.
	 *
	 * @return array{algorithm: string, hash: string, fields: array<int, string>, sealedAt: string} The seal.
	 *
	 * @spec openspec/specs/frozen-record/spec.md
	 */
	public function seal(FreezeDefinition $definition, array $record, ?DateTimeImmutable $moment = null): array;
}//end interface
