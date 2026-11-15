<?php

/**
 * Dossiq BezwaarEntryCommand.
 *
 * One Awb / AVG entry an act on a bezwaar record writes: the record it is
 * about (register, schema, uuid), the event, its payload and its tag.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Bezwaar
 *
 * @author    Conduction Development Team <dev@conduction.nl>
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
 * @spec openspec/specs/bezwaar-awb-audit-trail/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Bezwaar;

/**
 * The entry BezwaarAuditTrail writes for one act.
 *
 * @spec openspec/specs/bezwaar-awb-audit-trail/spec.md
 */
final class BezwaarEntryCommand {

	/**
	 * Constructor.
	 *
	 * @param string               $register   The register the record lives in.
	 * @param string               $schema     The record's schema.
	 * @param string               $objectUuid The record's uuid.
	 * @param string               $event      Event slug; the action is `dossiq.bezwaar.<event>`.
	 * @param array<string, mixed> $payload    Structured payload.
	 * @param string               $tag        Awb / AVG tag, or '' for an untagged entry.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly string $register,
		public readonly string $schema,
		public readonly string $objectUuid,
		public readonly string $event,
		public readonly array $payload,
		public readonly string $tag,
	) {
	}//end __construct()
}//end class
