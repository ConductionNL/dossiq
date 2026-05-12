<?php

/**
 * Procest NotifyRoleHandler
 *
 * Resolves a role slug to its members and emits an in-app Nextcloud
 * notification to each. In dry-run mode it returns the resolved recipient
 * list and rendered message without queuing any notifications.
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
 * Handler for `notifyRole` automatic actions.
 */
class NotifyRoleHandler implements ActionHandlerInterface
{
    use HandlesTemplates;

    /**
     * Constructor for NotifyRoleHandler.
     *
     * @param ContainerInterface $container DI container — used to resolve
     *                                      NotificatieService lazily.
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
     */
    public function type(): string
    {
        return 'notifyRole';
    }//end type()

    /**
     * {@inheritDoc}
     */
    public function handle(array $actionConfig, array $case, array $transitionContext): ActionResult
    {
        try {
            $roleSlug = (string) ($actionConfig['roleSlug'] ?? '');
            $message  = $this->renderTemplate(
                (string) ($actionConfig['messageTemplate'] ?? ''),
                $case
            );

            $recipients = $this->resolveRoleMembers($roleSlug, $case);
            $preview    = [
                'roleSlug'   => $roleSlug,
                'recipients' => $recipients,
                'message'    => $message,
            ];

            if (($transitionContext['dryRun'] ?? false) === true) {
                return ActionResult::success($preview);
            }

            if ($roleSlug === '' || $recipients === []) {
                return ActionResult::failure('no_recipients', $preview);
            }

            $notificatie = $this->resolveNotificatieService();
            if ($notificatie === null) {
                return ActionResult::failure('notificatie_unavailable', $preview);
            }

            foreach ($recipients as $userId) {
                if (method_exists($notificatie, 'notifyUser') === true) {
                    // @phpstan-ignore-next-line — signature owned by service.
                    $notificatie->notifyUser($userId, $message);
                }
            }

            return ActionResult::success($preview);
        } catch (\Throwable $e) {
            $this->logger->error(
                'NotifyRoleHandler: failed to dispatch notification',
                [
                    'app'       => Application::APP_ID,
                    'slug'      => (string) ($actionConfig['slug'] ?? ''),
                    'exception' => $e->getMessage(),
                ]
            );
            return ActionResult::failure('notify_role_failed');
        }//end try
    }//end handle()

    /**
     * Resolve a role slug to a list of user identifiers on the case.
     *
     * V1 strategy: look up `case.<roleSlug>` for a single user, or
     * `case.<roleSlug>Members[]` for a collection. RoleResolverService will
     * supersede this lookup once role-based-step-routing lands.
     *
     * @param string $roleSlug Role slug.
     * @param array  $case     Case object.
     *
     * @return array<int, string>
     */
    private function resolveRoleMembers(string $roleSlug, array $case): array
    {
        if ($roleSlug === '') {
            return [];
        }

        $single = ($case[$roleSlug] ?? null);
        if (is_string($single) === true && $single !== '') {
            return [$single];
        }

        if (is_array($single) === true) {
            $id = (string) ($single['id'] ?? ($single['userId'] ?? ''));
            if ($id !== '') {
                return [$id];
            }
        }

        $multiKey = $roleSlug.'Members';
        $multi    = ($case[$multiKey] ?? null);
        if (is_array($multi) === true) {
            $out = [];
            foreach ($multi as $member) {
                if (is_string($member) === true && $member !== '') {
                    $out[] = $member;
                } else if (is_array($member) === true) {
                    $id = (string) ($member['id'] ?? ($member['userId'] ?? ''));
                    if ($id !== '') {
                        $out[] = $id;
                    }
                }
            }

            return $out;
        }

        return [];
    }//end resolveRoleMembers()

    /**
     * Resolve NotificatieService lazily.
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
