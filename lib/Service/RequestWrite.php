<?php

/**
 * Dossiq aanvullingsverzoek write.
 *
 * One write of an aanvullingsverzoek record, built by
 * `AanvullingsverzoekService::write()` and never handed out: the object
 * service and register it writes to, the request fields and, for a change,
 * the id of the request it changes.
 *
 * @category Service
 * @package  OCA\Dossiq\Service
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
 * @spec openspec/changes/aanvullingsverzoek-as-a-record/specs/termijn-pause-extension/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

/**
 * What one request write writes, and where.
 *
 * @spec openspec/changes/aanvullingsverzoek-as-a-record/specs/termijn-pause-extension/spec.md
 */
final class RequestWrite {
	/**
	 * Constructor.
	 *
	 * @param object $objectService OpenRegister's object service.
	 * @param string $register      The register that holds the requests.
	 */
	public function __construct(
		public readonly object $objectService,
		public readonly string $register,
	) {
	}//end __construct()

	/**
	 * The request fields: the whole request, or the changes.
	 *
	 * @var array<string, mixed>
	 */
	public array $request = [];

	/**
	 * The trimmed id of the request changed, or '' to create one.
	 *
	 * @var string
	 */
	public string $id = '';
}//end class
