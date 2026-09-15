<?php

/**
 * Stub of OpenRegister's BulkActionInterface.
 *
 * Dossiq's four case bulk actions IMPLEMENT this interface, so without the
 * stub they cannot be loaded at all in a unit test on a host where
 * OpenRegister is absent. Mirrors openregister
 * `lib/BulkAction/BulkActionInterface.php` verbatim; if that contract changes,
 * this changes with it.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Stub
 * @package  OCA\OpenRegister\BulkAction
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\BulkAction;

use OCA\OpenRegister\Db\ObjectEntity;
use OCP\IUser;

/**
 * The contract every bulk action implements.
 */
interface BulkActionInterface {

	/**
	 * Refuse a selection that spans more than one schema version.
	 *
	 * @var string
	 */
	public const GUARD_HOMOGENEITY = 'homogeneity';

	/**
	 * The action's stable id, in `app:action` form.
	 *
	 * @return string The action id.
	 */
	public function getId(): string;

	/**
	 * The label an operator reads.
	 *
	 * @return string The label.
	 */
	public function getLabel(): string;

	/**
	 * One sentence saying what the action does.
	 *
	 * @return string The description.
	 */
	public function getDescription(): string;

	/**
	 * Whether the job may not commit without a written reason.
	 *
	 * @return bool True when a justification is required.
	 */
	public function requiresJustification(): bool;

	/**
	 * The guards the engine enforces before the first object is touched.
	 *
	 * @return array<int, string> The guard names.
	 */
	public function getGuards(): array;

	/**
	 * Check the parameters before a job is created.
	 *
	 * @param array<string, mixed> $parameters The parameters the caller sent.
	 *
	 * @return void
	 *
	 * @throws \InvalidArgumentException When the parameters do not make sense.
	 */
	public function validateParameters(array $parameters): void;

	/**
	 * What the action would do, or does, to one object.
	 *
	 * @param ObjectEntity         $object     The object to act on.
	 * @param array<string, mixed> $parameters The job's parameters.
	 * @param bool                 $commit     False to rehearse, true to write.
	 * @param IUser|null           $actor      The user the job runs as.
	 *
	 * @return BulkActionResult What happened, or what would happen.
	 */
	public function apply(ObjectEntity $object, array $parameters, bool $commit, ?IUser $actor = null): BulkActionResult;
}//end interface
