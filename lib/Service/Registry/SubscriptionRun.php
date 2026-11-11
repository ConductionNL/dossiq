<?php

/**
 * One requester's subscription request at its source register.
 *
 * Built by `RequesterRegistrySubscription::subscribe()` and never handed out:
 * OpenRegister's registry service, schema mapper and object service, and the
 * requester. Assigned field by field by the subscriber that builds it.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Registry
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Registry;

/**
 * What one subscription request asks with.
 *
 * @spec openspec/specs/case-management/spec.md
 */
final class SubscriptionRun {
	/**
	 * OpenRegister's registry service.
	 *
	 * @var object
	 */
	public object $registry;

	/**
	 * OpenRegister's schema mapper.
	 *
	 * @var object
	 */
	public object $schemas;

	/**
	 * OpenRegister's object service.
	 *
	 * @var object
	 */
	public object $objects;

	/**
	 * The requester's object UUID.
	 *
	 * @var string
	 */
	public string $objectUuid = '';
}//end class
