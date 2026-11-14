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

use OCA\Dossiq\Kernel\Contracts\CaseTypeResolverInterface;
use OCA\Dossiq\Kernel\Contracts\CaseTypeStoreInterface;
use OCA\Dossiq\Kernel\Contracts\PortalWithdrawalTargetInterface;

/**
 * Where a case type and its portal withdrawal target are read: the resolver, the store and the target.
 *
 * @spec openspec/specs/portal-contribution/spec.md
 */
final class CaseTypeLookup {
	/**
	 * Constructor.
	 *
	 * @param CaseTypeResolverInterface       $resolver The type's statuses, inherited ones included.
	 * @param CaseTypeStoreInterface          $store    The type's active workflow template.
	 * @param PortalWithdrawalTargetInterface $target   Owns the rule.
	 */
	public function __construct(
		public readonly CaseTypeResolverInterface $resolver,
		public readonly CaseTypeStoreInterface $store,
		public readonly PortalWithdrawalTargetInterface $target,
	) {
	}//end __construct()
}//end class
