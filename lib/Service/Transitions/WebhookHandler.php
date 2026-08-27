<?php

/**
 * Dossiq webhook action handler.
 *
 * Action config shape: `{type: 'webhook', url: '<https-url>', headers?: {...}}`.
 * Posts a JSON payload `{case, transition}` to the configured URL with a
 * 5-second timeout. Non-2xx responses produce `ok: false`.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Transitions
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Transitions;

use OCP\Http\Client\IClientService;
use Psr\Log\LoggerInterface;

/**
 * Built-in handler for `webhook` automatic actions.
 *
 * @spec openspec/changes/status-transition-engine/tasks.md#T08
 */
class WebhookHandler implements ActionHandlerInterface {
	/**
	 * Constructor.
	 *
	 * @param IClientService $clientService Nextcloud HTTP client factory
	 * @param LoggerInterface $logger Logger
	 */
	public function __construct(
		private readonly IClientService $clientService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Handle the webhook action.
	 *
	 * @param array<string, mixed> $actionConfig Action configuration
	 * @param array<string, mixed> $case Case object
	 * @param array<string, mixed> $transitionContext Transition context
	 *
	 * @return ActionResult
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 */
	public function handle(array $actionConfig, array $case, array $transitionContext): ActionResult {
		try {
			$url = (string)($actionConfig['url'] ?? '');
			if ($url === '' || (str_starts_with($url, 'http://') === false && str_starts_with($url, 'https://') === false)) {
				return new ActionResult(succeeded: false, error: 'webhook_invalid_url');
			}

			$client = $this->clientService->newClient();
			$headers = $actionConfig['headers'] ?? [];
			if (is_array($headers) === false) {
				$headers = [];
			}

			$response = $client->post(
				$url,
				[
					'json' => [
						'case' => $case,
						'transition' => $transitionContext,
					],
					'headers' => $headers,
					'timeout' => 5,
				],
			);

			$status = (int)$response->getStatusCode();
			if ($status >= 200 && $status < 300) {
				return new ActionResult(succeeded: true, data: ['status' => $status]);
			}

			return new ActionResult(succeeded: false, error: 'webhook_non_2xx', data: ['status' => $status]);
		} catch (\Throwable $e) {
			$this->logger->error(
				'WebhookHandler failed',
				['exception' => $e->getMessage(), 'context' => $transitionContext],
			);
			return new ActionResult(succeeded: false, error: 'webhook_failed');
		}//end try
	}//end handle()
}//end class
