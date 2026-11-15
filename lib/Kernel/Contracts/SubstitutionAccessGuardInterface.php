<?php

/**
 * Contract of SubstitutionAccessGuard
 *
 * The public surface of SubstitutionAccessGuard that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Dossiq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/dossiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Kernel\Contracts;

use OCP\AppFramework\Http\JSONResponse;

/**
 * Contract of SubstitutionAccessGuard.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface SubstitutionAccessGuardInterface {

	/**
	 * The signed-in user's UID, or an empty string when unauthenticated.
	 *
	 * @return string The current UID, empty when there is no session.
	 *
	 * @spec openspec/specs/handler-vervanging-waarneming/spec.md
	 */
	public function currentUid(): string;

	/**
	 * Build a 403 response (fail closed).
	 *
	 * @param string $message Optional message.
	 *
	 * @return JSONResponse The 403 response.
	 *
	 * @spec openspec/specs/handler-vervanging-waarneming/spec.md
	 */
	public function forbidden(string $message = 'Not authorised'): JSONResponse;

	/**
	 * The substitutions one user may see.
	 *
	 * Coordinators see all; a regular user sees only substitutions where they
	 * are the absentee or the substitute.
	 *
	 * @param string $userId The acting user id.
	 *
	 * @return array<int, array<string, mixed>> The visible substitution rows.
	 *
	 * @spec openspec/specs/handler-vervanging-waarneming/spec.md
	 */
	public function listVisibleTo(string $userId): array;

	/**
	 * Whether a user holds the dossiq coordinator role (NC admin).
	 *
	 * Coordinator authority is delegated to Nextcloud admin membership, the
	 * same model used elsewhere in dossiq (e.g. ComplaintController).
	 *
	 * @param string $userId The user id.
	 *
	 * @return bool True when the user is a coordinator.
	 *
	 * @spec openspec/specs/handler-vervanging-waarneming/spec.md
	 */
	public function isCoordinator(string $userId): bool;

	/**
	 * Find a single substitution by id (system-context read for guard checks).
	 *
	 * @param string $id The substitution UUID.
	 *
	 * @return array<string, mixed>|null The row, or null when unresolvable.
	 *
	 * @spec openspec/specs/handler-vervanging-waarneming/spec.md
	 */
	public function find(string $id): ?array;

	/**
	 * Whether the user may revoke/manage this substitution.
	 *
	 * Allowed for the absentee, the original creator, or a coordinator.
	 *
	 * @param array<string, mixed> $row The substitution row.
	 * @param string $userId The acting user id.
	 *
	 * @return bool True when the user may manage the substitution.
	 *
	 * @spec openspec/specs/handler-vervanging-waarneming/spec.md
	 */
	public function mayManage(array $row, string $userId): bool;

	/**
	 * Whether the user may see this substitution's action list.
	 *
	 * Visible to the absentee, substitute, creator, or a coordinator.
	 *
	 * @param array<string, mixed> $row The substitution row.
	 * @param string $userId The acting user id.
	 *
	 * @return bool True when the user is involved or a coordinator.
	 *
	 * @spec openspec/specs/handler-vervanging-waarneming/spec.md
	 */
	public function mayView(array $row, string $userId): bool;
}//end interface
