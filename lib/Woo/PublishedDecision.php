<?php

/**
 * A Woo decision just published: the case it decided, as it was before the publish, and its publication.
 *
 * @category Woo
 * @package  OCA\Dossiq\Woo
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
 * @spec openspec/specs/portal-contribution/spec.md#requirement-the-decision-notice-says-what-happened
 */

declare(strict_types=1);

namespace OCA\Dossiq\Woo;

/**
 * A Woo decision just published: the case it decided, as it was before the publish, and its publication.
 *
 * @spec openspec/specs/portal-contribution/spec.md#requirement-the-decision-notice-says-what-happened
 */
final class PublishedDecision {

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $case          The case, as it was before the publish.
	 * @param string               $caseId        The case uuid.
	 * @param string               $publicationId The publication uuid in opencatalogi.
	 *
	 * @spec openspec/specs/portal-contribution/spec.md#requirement-the-decision-notice-says-what-happened
	 */
	public function __construct(
		public readonly array $case,
		public readonly string $caseId,
		public readonly string $publicationId,
	) {
	}//end __construct()
}//end class
