<?php

/**
 * Procest SendEmailHandler
 *
 * Renders subject + body templates against the case and (in live mode)
 * dispatches the email via NotificatieService. In dry-run mode it returns
 * the rendered preview without contacting the mail subsystem.
 *
 * @category Service
 * @package  OCA\Procest\Service\Actions
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2024 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://procest.nl
 */

declare(strict_types=1);

namespace OCA\Procest\Service\Actions;

use OCA\Procest\AppInfo\Application;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Handler for `sendEmail` automatic actions.
 */
class SendEmailHandler implements ActionHandlerInterface
{
    use HandlesTemplates;

    /**
     * Constructor for SendEmailHandler.
     *
     * @param ContainerInterface $container DI container — used to resolve
     *                                      NotificatieService lazily (it is
     *                                      not always available, e.g. during
     *                                      dry-run unit tests).
     * @param LoggerInterface    $logger    PSR-3 logger.
     *
     * @return void
     */
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * {@inheritDoc}
     *
     * @return string The action type slug handled by this handler.
     */
    public function type(): string
    {
        return 'sendEmail';
    }//end type()

    /**
     * {@inheritDoc}
     *
     * @param array $actionConfig      Resolved action config array.
     * @param array $case              The full case object.
     * @param array $transitionContext Transition context (carries dryRun).
     *
     * @return ActionResult The outcome of sending the email.
     */
    public function handle(array $actionConfig, array $case, array $transitionContext): ActionResult
    {
        try {
            $subject   = $this->renderTemplate(
                template: (string) ($actionConfig['subjectTemplate'] ?? ''),
                case: $case
            );
            $body      = $this->renderTemplate(
                template: (string) ($actionConfig['bodyTemplate'] ?? ''),
                case: $case
            );
            $recipient = $this->resolveRecipient(
                recipientRef: (string) ($actionConfig['recipientRef'] ?? ''),
                case: $case
            );

            $preview = [
                'recipient' => $recipient,
                'subject'   => $subject,
                'body'      => $body,
            ];

            if (($transitionContext['dryRun'] ?? false) === true) {
                return ActionResult::success($preview);
            }

            if ($recipient === '') {
                return ActionResult::failure('missing_recipient', $preview);
            }

            $notificatie = $this->resolveNotificatieService();
            if ($notificatie === null) {
                return ActionResult::failure('notificatie_unavailable', $preview);
            }

            // @phpstan-ignore-next-line — NotificatieService::sendEmail is
            // resolved lazily; signature is owned by the service itself.
            $notificatie->sendEmail($recipient, $subject, $body);

            return ActionResult::success($preview);
        } catch (\Throwable $e) {
            $this->logger->error(
                'SendEmailHandler: failed to dispatch email',
                [
                    'app'       => Application::APP_ID,
                    'slug'      => (string) ($actionConfig['slug'] ?? ''),
                    'exception' => $e->getMessage(),
                ]
            );
            return ActionResult::failure('email_dispatch_failed');
        }//end try
    }//end handle()

    /**
     * Resolve NotificatieService from the container without a hard dep.
     *
     * @return object|null
     */
    private function resolveNotificatieService(): ?object
    {
        try {
            return $this->container->get('OCA\Procest\Service\NotificatieService');
        } catch (\Throwable $e) {
            return null;
        }
    }//end resolveNotificatieService()
}//end class
