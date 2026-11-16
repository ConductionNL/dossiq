<?php

/**
 * One stored hearing session being changed
 *
 * Where it is stored, its id, what is stored and which declared session it
 * is: the state SessionService::recordAttendance() and
 * SessionService::addMinutes() read before they patch it.
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

/**
 * A stored session as read for a change; it never leaves the service that read it.
 *
 * @spec openspec/changes/case-session-with-parties-and-minutes/specs/case-session-with-parties-and-minutes/spec.md
 */
final class StoredSession {

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
	 * The session id it was read by.
	 *
	 * @var string
	 */
	public string $id = '';

	/**
	 * The stored session.
	 *
	 * @var array<string, mixed>
	 */
	public array $current = [];

	/**
	 * The declared session.
	 *
	 * @var SessionDefinition
	 */
	public SessionDefinition $definition;
}//end class
