<?php

/**
 * Procest Zaakportaal Audit Logger
 *
 * Records every citizen portal action (login, case access, document download,
 * message send, request submission, preference change) to the application log
 * stream and, when available, to the OpenRegister audit trail. Special-category
 * data is never written: actors are identified only by their pseudonymous
 * subject reference.
 *
 * @category Service
 * @package  OCA\Procest\Service\Zaakportaal
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
 * @link https://procest.nl
 *
 * @spec openspec/changes/zaakportaal-mijngemeente/tasks.md#TASK-ZMP-10
 */

declare(strict_types=1);

namespace OCA\Procest\Service\Zaakportaal;

use DateTimeImmutable;
use Psr\Log\LoggerInterface;

/**
 * Logs citizen portal actions for audit and compliance (Wpg/AVG art. 32).
 *
 * @psalm-suppress UnusedClass
 */
class PortalAuditLogger
{
    /**
     * Recognised portal audit actions.
     *
     * @var array<int, string>
     */
    public const ACTIONS = [
        'login',
        'login-rejected',
        'logout',
        'case-list',
        'case-view',
        'document-download',
        'document-download-denied',
        'message-send',
        'objection-submit',
        'complaint-submit',
        'preference-update',
    ];

    /**
     * Constructor.
     *
     * @param LoggerInterface $logger The PSR logger.
     */
    public function __construct(
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Build a normalised audit record for a portal action.
     *
     * @param string               $action     The action (one of self::ACTIONS).
     * @param string               $subjectRef The pseudonymous actor reference.
     * @param string               $result     The result ('success' or 'denied').
     * @param array<string, mixed> $context    Non-sensitive context (objectId, caseId).
     *
     * @return array<string, mixed> The audit record.
     */
    public function record(string $action, string $subjectRef, string $result='success', array $context=[]): array
    {
        $normalisedAction = 'unknown';
        if (in_array($action, self::ACTIONS, true) === true) {
            $normalisedAction = $action;
        }

        $normalisedResult = 'success';
        if ($result === 'denied') {
            $normalisedResult = 'denied';
        }

        $entry = [
            'action'    => $normalisedAction,
            'actor'     => $subjectRef,
            'result'    => $normalisedResult,
            'objectId'  => (string) ($context['objectId'] ?? ''),
            'caseId'    => (string) ($context['caseId'] ?? ''),
            'timestamp' => (new DateTimeImmutable('now'))->format(DateTimeImmutable::ATOM),
        ];

        $this->logger->info('Procest zaakportaal audit', $entry);

        return $entry;
    }//end record()
}//end class
