<?php

/**
 * Contract of DigestPreferences
 *
 * The public surface of DigestPreferences that other components call, so they depend
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
 * Contract of DigestPreferences.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface DigestPreferencesInterface {

	/**
	 * The hour a digest arrives when nobody chose one.
	 *
	 * @var int
	 */
	public const DEFAULT_HOUR = 8;

	/**
	 * This person's digest settings.
	 *
	 * The switch comes from the platform when the platform routes it, so a team
	 * default a lead set is honoured here and in the background job alike. The
	 * local value answers only when nothing routes.
	 *
	 * An unreadable preference reads as the default rather than as off: a
	 * config blip must not silently stop somebody's digest for a week.
	 *
	 * @param string $userId The person.
	 *
	 * @return array{enabled: bool, hour: int, source: string, scope: string} The settings.
	 *
	 * @spec openspec/specs/my-work/spec.md
	 */
	public function forUser(string $userId): array;

	/**
	 * Whether this person is due a digest in this hour.
	 *
	 * @param string  $userId The person.
	 * @param integer $hour   The hour the job is running in.
	 * @param string  $today  Today, `Y-m-d`.
	 *
	 * @return bool TRUE when a digest should be composed for them now.
	 *
	 * @spec openspec/specs/my-work/spec.md
	 */
	public function isDue(string $userId, int $hour, string $today): bool;

	/**
	 * Record that this person's digest went out today.
	 *
	 * @param string $userId The person.
	 * @param string $today  Today, `Y-m-d`.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/my-work/spec.md
	 */
	public function markSent(string $userId, string $today): void;

	/**
	 * Save this person's digest settings.
	 *
	 * The switch goes to the platform first, as this person's own override, so
	 * a team default stays visible underneath it rather than being overwritten.
	 * The local value is written either way, so an instance that loses the
	 * routing keeps the answer this person last gave.
	 *
	 * @param string  $userId  The person.
	 * @param bool    $enabled Whether they want the digest.
	 * @param integer $hour    The hour they want it in, 0 to 23.
	 *
	 * @return array{enabled: bool, hour: int, source: string, scope: string} The settings now stored.
	 *
	 * @spec openspec/specs/my-work/spec.md
	 */
	public function save(string $userId, bool $enabled, int $hour): array;
}//end interface
