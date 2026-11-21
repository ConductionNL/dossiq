<?php

/**
 * Contract of DailyDigestComposer
 *
 * The public surface of DailyDigestComposer that other components call, so they depend
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

/**
 * Contract of DailyDigestComposer.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface DailyDigestComposerInterface {

	/**
	 * Compose the digest for one person, or nothing when there is nothing to say.
	 *
	 * @param string            $userId The person.
	 * @param DateTimeImmutable $now    The moment the digest is for.
	 *
	 * @return array<string, mixed>|null The digest, or null when the queue is empty.
	 *
	 * @spec openspec/specs/my-work/spec.md
	 */
	public function compose(string $userId, DateTimeImmutable $now): ?array;
}//end interface
