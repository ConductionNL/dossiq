<?php

/**
 * One hearing session being planned or waived on a case
 *
 * Where it is stored, which declared session it is, on which case, who
 * chairs it, what the caller sent along, and when: the state
 * SessionService::schedule() and SessionService::waive() build and read.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Session
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
 * @spec openspec/changes/case-session-with-parties-and-minutes/specs/case-session-with-parties-and-minutes/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Session;

use DateTimeImmutable;

/**
 * The state of one session write; it never leaves the service that built it.
 *
 * @spec openspec/changes/case-session-with-parties-and-minutes/specs/case-session-with-parties-and-minutes/spec.md
 */
final class SessionWrite {

	/**
	 * The OpenRegister object service.
	 *
	 * @var object
	 */
	public object $objectService;

	/**
	 * The register.
	 *
	 * @var string
	 */
	public string $register = '';

	/**
	 * The declared session.
	 *
	 * @var SessionDefinition
	 */
	public SessionDefinition $definition;

	/**
	 * The case uuid.
	 *
	 * @var string
	 */
	public string $caseId = '';

	/**
	 * Who chairs the session, or records its waiver.
	 *
	 * @var string
	 */
	public string $chairpersonId = '';

	/**
	 * What the caller sent along.
	 *
	 * @var array<string, mixed>
	 */
	public array $payload = [];

	/**
	 * When this is happening.
	 *
	 * @var DateTimeImmutable
	 */
	public DateTimeImmutable $now;

	/**
	 * When the session is planned.
	 *
	 * @var DateTimeImmutable
	 */
	public DateTimeImmutable $scheduled;

	/**
	 * Who is invited.
	 *
	 * @var array<int, mixed>
	 */
	public array $invitees = [];
}//end class
