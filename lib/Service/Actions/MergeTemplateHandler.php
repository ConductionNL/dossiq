<?php

/**
 * Procest MergeTemplateHandler
 *
 * Renders a text/markdown template into a case field via ObjectService.
 * In dry-run mode it returns the rendered content + target field without
 * persisting any update.
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
 *
 * @spec openspec/changes/retrofit-2026-05-24-automatic-actions/tasks.md#task-4
 */

declare(strict_types=1);

namespace OCA\Procest\Service\Actions;

use OCA\Procest\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Handler for `mergeTemplate` automatic actions.
 */
class MergeTemplateHandler implements ActionHandlerInterface
{
    use HandlesTemplates;

    /**
     * Constructor for MergeTemplateHandler.
     *
     * @param ContainerInterface $container DI container — used to resolve
     *                                      OpenRegister ObjectService.
     * @param IAppConfig         $appConfig App config — supplies register +
     *                                      case_schema keys for the save.
     * @param LoggerInterface    $logger    PSR-3 logger.
     *
     * @return void
     */
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly IAppConfig $appConfig,
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
        return 'mergeTemplate';
    }//end type()

    /**
     * {@inheritDoc}
     *
     * @param array $actionConfig      Resolved action config array.
     * @param array $case              The full case object.
     * @param array $transitionContext Transition context (carries dryRun).
     *
     * @return ActionResult The outcome of the template merge.
     */
    public function handle(array $actionConfig, array $case, array $transitionContext): ActionResult
    {
        try {
            $template    = (string) ($actionConfig['template'] ?? ($actionConfig['templateSlug'] ?? ''));
            $targetField = (string) ($actionConfig['targetField'] ?? '');
            $rendered    = $this->renderTemplate(template: $template, case: $case);

            $preview = [
                'targetField' => $targetField,
                'rendered'    => $rendered,
            ];

            if (($transitionContext['dryRun'] ?? false) === true) {
                return ActionResult::success($preview);
            }

            if ($targetField === '') {
                return ActionResult::failure('missing_target_field', $preview);
            }

            $objectService = $this->resolveObjectService();
            if ($objectService === null) {
                return ActionResult::failure('object_service_unavailable', $preview);
            }

            $register = $this->appConfig->getValueString(
                Application::APP_ID,
                'register',
                ''
            );
            $schema   = $this->appConfig->getValueString(
                Application::APP_ID,
                'case_schema',
                ''
            );

            if ($register === '' || $schema === '') {
                return ActionResult::failure('case_schema_unconfigured', $preview);
            }

            $updated = array_merge($case, [$targetField => $rendered]);

            // ObjectService::saveObject 3-arg signature: ($object, $register, $schema).
            // First arg is the entity/array per project convention.
            // @phpstan-ignore-next-line — signature owned by OpenRegister.
            $objectService->saveObject($updated, $register, $schema);

            return ActionResult::success($preview);
        } catch (\Throwable $e) {
            $this->logger->error(
                'MergeTemplateHandler: failed to merge template',
                [
                    'app'       => Application::APP_ID,
                    'slug'      => (string) ($actionConfig['slug'] ?? ''),
                    'exception' => $e->getMessage(),
                ]
            );
            return ActionResult::failure('merge_template_failed');
        }//end try
    }//end handle()

    /**
     * Resolve OpenRegister ObjectService lazily.
     *
     * @return object|null
     */
    private function resolveObjectService(): ?object
    {
        try {
            return $this->container->get('OCA\OpenRegister\Service\ObjectService');
        } catch (\Throwable $e) {
            return null;
        }
    }//end resolveObjectService()
}//end class
