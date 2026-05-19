<?php

/**
 * Procest Informatieobject Access Guard
 *
 * Enforces vertrouwelijkheidaanduiding-based access control on read, share,
 * and publish operations. Ordinal comparison against an 8-level ZGW DRC enum.
 *
 * @category Service
 * @package  OCA\Procest\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/document-zaakdossier/tasks.md#task-T03
 */

declare(strict_types=1);

namespace OCA\Procest\Service;

use OCP\IUser;
use OCP\IGroupManager;
use Psr\Log\LoggerInterface;

/**
 * Guards informatieobject access based on vertrouwelijkheidaanduiding.
 *
 * @spec openspec/changes/document-zaakdossier/tasks.md#task-T03
 */
class InformatieobjectAccessGuard
{

    /**
     * ZGW vertrouwelijkheidaanduiding enum ordered from least to most confidential.
     */
    private const CLASSIFICATION_ORDER = [
        'openbaar',
        'beperkt_openbaar',
        'intern',
        'zaakvertrouwelijk',
        'vertrouwelijk',
        'confidentieel',
        'geheim',
        'zeer_geheim',
    ];

    /**
     * Minimum classification for public share rejection.
     */
    private const PUBLIC_SHARE_THRESHOLD = 'vertrouwelijk';

    /**
     * Nextcloud group prefix for clearance levels (e.g. dossier_clearance_vertrouwelijk).
     */
    private const CLEARANCE_GROUP_PREFIX = 'dossier_clearance_';

    /**
     * Constructor.
     *
     * @param IGroupManager   $groupManager The group manager
     * @param LoggerInterface $logger       The logger
     */
    public function __construct(
        private readonly IGroupManager $groupManager,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Check whether the user may read this informatieobject.
     *
     * @param IUser               $user             The user
     * @param array<string,mixed> $informatieobject The informatieobject record
     *
     * @return bool True if access is allowed
     *
     * @spec openspec/changes/document-zaakdossier/tasks.md#task-T03
     */
    public function canRead(IUser $user, array $informatieobject): bool
    {
        $classification = $informatieobject['vertrouwelijkheidaanduiding'] ?? 'openbaar';
        $classIndex     = array_search($classification, self::CLASSIFICATION_ORDER, strict: true);

        if ($classIndex === false) {
            return true;
        }

        if ($classIndex === 0) {
            return true;
        }

        $uid = $user->getUID();

        if ($this->groupManager->isAdmin($uid) === true) {
            return true;
        }

        foreach (self::CLASSIFICATION_ORDER as $level => $levelName) {
            $groupName = self::CLEARANCE_GROUP_PREFIX.$levelName;
            if ($this->groupManager->isInGroup($uid, $groupName) === true && $level >= $classIndex) {
                return true;
            }
        }

        return $classIndex <= 2;
    }//end canRead()

    /**
     * Check whether the document may be published as a public share link.
     *
     * @param array<string,mixed> $informatieobject The informatieobject record
     *
     * @return bool False if classification is too high for public sharing
     *
     * @spec openspec/changes/document-zaakdossier/tasks.md#task-T03
     */
    public function canPublish(array $informatieobject): bool
    {
        $classification = $informatieobject['vertrouwelijkheidaanduiding'] ?? 'openbaar';
        $classIndex     = array_search($classification, self::CLASSIFICATION_ORDER, strict: true);
        $threshold      = array_search(self::PUBLIC_SHARE_THRESHOLD, self::CLASSIFICATION_ORDER, strict: true);

        if ($classIndex === false || $threshold === false) {
            return true;
        }

        return $classIndex < $threshold;
    }//end canPublish()

    /**
     * Filter a list of informatieobjecten to only those the user may read.
     *
     * @param IUser                          $user               The user
     * @param array<int,array<string,mixed>> $informatieobjecten The records to filter
     *
     * @return array<int,array<string,mixed>> Permitted records
     *
     * @spec openspec/changes/document-zaakdossier/tasks.md#task-T03
     */
    public function filterDossierForUser(IUser $user, array $informatieobjecten): array
    {
        return array_values(
                array_filter(
            $informatieobjecten,
            fn(array $io) => $this->canRead(user: $user, informatieobject: $io),
        )
                );
    }//end filterDossierForUser()

    /**
     * Assert the user may read this informatieobject or throw.
     *
     * @param IUser               $user             The requesting user
     * @param array<string,mixed> $informatieobject The informatieobject
     *
     * @return void
     *
     * @throws \OCP\Files\NotPermittedException when denied
     *
     * @spec openspec/changes/document-zaakdossier/tasks.md#task-T03
     */
    public function requireRead(IUser $user, array $informatieobject): void
    {
        if ($this->canRead(user: $user, informatieobject: $informatieobject) === false) {
            throw new \OCP\Files\NotPermittedException(
                'Access denied: insufficient clearance for vertrouwelijkheidaanduiding '
                .($informatieobject['vertrouwelijkheidaanduiding'] ?? 'unknown')
            );
        }
    }//end requireRead()
}//end class
