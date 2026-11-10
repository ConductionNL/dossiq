<?php

/**
 * One copy of a domain with its case types and content templates
 *
 * The domain copied from, the new domain's id and name: the state
 * DomainCopyService::copy() builds once the new domain exists, and its
 * content copy reads.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Starter
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/starter-content-and-templates/specs/case-type-seed-data/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Starter;

/**
 * The state of one domain copy; it never leaves the service that built it.
 *
 * @spec openspec/changes/starter-content-and-templates/specs/case-type-seed-data/spec.md
 */
final class DomainCopy {

	/**
	 * The domain copied from.
	 *
	 * @var string
	 */
	public string $sourceId = '';

	/**
	 * The domain copied from, as stored.
	 *
	 * @var array<string, mixed>
	 */
	public array $source = [];

	/**
	 * The new domain's id.
	 *
	 * @var string
	 */
	public string $newId = '';

	/**
	 * The new domain's name.
	 *
	 * @var string
	 */
	public string $name = '';
}//end class
