<?php

/**
 * Where a case type and its portal withdrawal target are read: the resolver, the store and the target.
 *
 * @category Service
 * @package  OCA\Dossiq\Listener
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
 * @spec openspec/specs/portal-contribution/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Listener;

use OCA\Dossiq\Service\CaseTypeResolver;
use OCA\Dossiq\Service\CaseTypeStore;
use OCA\Dossiq\Service\CaseType\PortalWithdrawalTarget;

/**
 * Where a case type and its portal withdrawal target are read: the resolver, the store and the target.
 *
 * @spec openspec/specs/portal-contribution/spec.md
 */
final class CaseTypeLookup {
	/**
	 * Constructor.
	 *
	 * @param CaseTypeResolver       $resolver The type's statuses, inherited ones included.
	 * @param CaseTypeStore          $store    The type's active workflow template.
	 * @param PortalWithdrawalTarget $target   Owns the rule.
	 */
	public function __construct(
		public readonly CaseTypeResolver $resolver,
		public readonly CaseTypeStore $store,
		public readonly PortalWithdrawalTarget $target,
	) {
	}//end __construct()
}//end class
