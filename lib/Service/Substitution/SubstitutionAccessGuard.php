<?php

/**
 * Procest SubstitutionAccessGuard.
 *
 * Authorization and lookup collaborator for the vervanging/waarneming
 * endpoints. Split out of SubstitutionController so that controller keeps only
 * endpoint shape: who counts as a coordinator, who may manage or see a given
 * substitution, and the system-context reads those guards need all live here.
 *
 * Guards fail closed — an unauthenticated caller resolves to an empty user id,
 * which no ownership or coordinator check can ever satisfy (ADR-005 Rule 3).
 *
 * @category Service
 * @package  OCA\Procest\Service\Substitution
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://procest.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/handler-vervanging-waarneming/spec.md
 */

declare(strict_types=1);

namespace OCA\Procest\Service\Substitution;

use OCA\Procest\Service\SettingsService;
use OCA\Procest\Service\Support\SearchesObjects;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IUserSession;

/**
 * Resolves and authorizes substitution access for the controller layer.
 *
 * @spec openspec/specs/handler-vervanging-waarneming/spec.md
 */
class SubstitutionAccessGuard
{
    use SearchesObjects;

    /**
     * Maximum number of substitution rows fetched per call, matching the
     * pagination pattern used elsewhere in this app (e.g.
     * RaadsinformatieFeedController::FEED_LIMIT).
     *
     * @var int
     */
    private const SUBSTITUTION_LIMIT = 200;

    /**
     * Constructor.
     *
     * @param SettingsService $settingsService Settings/config + ObjectService bridge.
     * @param IUserSession    $userSession     The user session.
     * @param IGroupManager   $groupManager    Group manager (admin checks).
     *
     * @return void
     */
    public function __construct(
        private readonly SettingsService $settingsService,
        private readonly IUserSession $userSession,
        private readonly IGroupManager $groupManager,
    ) {
    }//end __construct()

    /**
     * The signed-in user's UID, or an empty string when unauthenticated.
     *
     * @return string The current UID, empty when there is no session.
     *
     * @spec openspec/specs/handler-vervanging-waarneming/spec.md
     */
    public function currentUid(): string
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return '';
        }

        return $user->getUID();
    }//end currentUid()

    /**
     * Whether a user holds the procest coordinator role (NC admin).
     *
     * Coordinator authority is delegated to Nextcloud admin membership, the
     * same model used elsewhere in procest (e.g. ComplaintController).
     *
     * @param string $userId The user id.
     *
     * @return bool True when the user is a coordinator.
     *
     * @spec openspec/specs/handler-vervanging-waarneming/spec.md
     */
    public function isCoordinator(string $userId): bool
    {
        if ($userId === '') {
            return false;
        }

        return $this->groupManager->isAdmin($userId);
    }//end isCoordinator()

    /**
     * Build a 403 response (fail closed).
     *
     * @param string $message Optional message.
     *
     * @return JSONResponse The 403 response.
     *
     * @spec openspec/specs/handler-vervanging-waarneming/spec.md
     */
    public function forbidden(string $message='Not authorised'): JSONResponse
    {
        return new JSONResponse(['error' => $message], Http::STATUS_FORBIDDEN);
    }//end forbidden()

    /**
     * Whether the user may revoke/manage this substitution.
     *
     * Allowed for the absentee, the original creator, or a coordinator.
     *
     * @param array<string, mixed> $row    The substitution row.
     * @param string               $userId The acting user id.
     *
     * @return bool True when the user may manage the substitution.
     *
     * @spec openspec/specs/handler-vervanging-waarneming/spec.md
     */
    public function mayManage(array $row, string $userId): bool
    {
        $isOwner = ((string) ($row['absentee'] ?? '') === $userId
            || (string) ($row['createdBy'] ?? '') === $userId);
        if ($isOwner === true) {
            return true;
        }

        return $this->isCoordinator(userId: $userId);
    }//end mayManage()

    /**
     * Whether the user may see this substitution's action list.
     *
     * Visible to the absentee, substitute, creator, or a coordinator.
     *
     * @param array<string, mixed> $row    The substitution row.
     * @param string               $userId The acting user id.
     *
     * @return bool True when the user is involved or a coordinator.
     *
     * @spec openspec/specs/handler-vervanging-waarneming/spec.md
     */
    public function mayView(array $row, string $userId): bool
    {
        $involved = in_array(
            $userId,
            [
                (string) ($row['absentee'] ?? ''),
                (string) ($row['substitute'] ?? ''),
                (string) ($row['createdBy'] ?? ''),
            ],
            true
        );
        if ($involved === true) {
            return true;
        }

        return $this->isCoordinator(userId: $userId);
    }//end mayView()

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
    public function listVisibleTo(string $userId): array
    {
        $rows = $this->allSubstitutions();
        if ($this->isCoordinator(userId: $userId) === true) {
            return $rows;
        }

        return array_values(
            array_filter(
                $rows,
                static function (array $row) use ($userId): bool {
                    return (string) ($row['absentee'] ?? '') === $userId
                        || (string) ($row['substitute'] ?? '') === $userId;
                }
            )
        );
    }//end listVisibleTo()

    /**
     * Find a single substitution by id (system-context read for guard checks).
     *
     * @param string $id The substitution UUID.
     *
     * @return array<string, mixed>|null The row, or null when unresolvable.
     *
     * @spec openspec/specs/handler-vervanging-waarneming/spec.md
     */
    public function find(string $id): ?array
    {
        $objectService = $this->settingsService->getObjectService();
        $register      = (string) $this->settingsService->getConfigValue('register');
        $schema        = (string) $this->settingsService->getConfigValue('substitution_schema');
        if ($objectService === null || $register === '' || $schema === '') {
            return null;
        }

        return $this->findObjectAsArray(objectService: $objectService, register: $register, schema: $schema, id: $id);
    }//end find()

    /**
     * Fetch all substitutions (bounded), then filtered per role by the caller.
     *
     * @return array<int, array<string, mixed>> The substitution rows.
     *
     * @spec openspec/changes/performance-hardening-audit-log-and-boot/specs/performance-hardening/spec.md
     */
    private function allSubstitutions(): array
    {
        $objectService = $this->settingsService->getObjectService();
        $register      = (string) $this->settingsService->getConfigValue('register');
        $schema        = (string) $this->settingsService->getConfigValue('substitution_schema');
        if ($objectService === null || $register === '' || $schema === '') {
            return [];
        }

        try {
            return $this->searchObjectsAsArrays(
                objectService: $objectService,
                register: $register,
                schema: $schema,
                filters: ['_limit' => self::SUBSTITUTION_LIMIT]
            );
        } catch (\Throwable $e) {
            return [];
        }
    }//end allSubstitutions()
}//end class
