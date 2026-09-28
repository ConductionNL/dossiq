<?php

/**
 * The webhook translations: dossiq's webhook and callWebhook as Integriq source calls.
 *
 * Integriq owns outbound calls (hydra ADR-094). Its step,
 * `openconnector.source-call`, calls a configured Source with an endpoint
 * relative to it, and never takes a URL. A retired webhook step holds a URL, so
 * the URL is split: its base (scheme, host and port) becomes a Source, which
 * Integriq finds or creates when asked by {@see self::SOURCE_EVENT}, and its
 * path and query become the step's endpoint.
 *
 * THE BODY IS WHAT THE STEP SENT. `dossiq.webhook` posted `{case, transition}`
 * and `dossiq.action.callWebhook` posted `{case}` or its rendered payload
 * template. `{{ @item }}` is Integriq's name for the whole item, which is the
 * case. In a stored flow the old `transition` was the engine's run context, not
 * a transition, and no step can reach it, so a rewritten flow step posts
 * `{case}`. A case type's declared webhook runs through
 * {@see RetiredActionRunner}, which knows the real transition and hands it in
 * under {@see self::TRANSITION_KEY}, so that one still posts both.
 *
 * WHAT IT REFUSES it refuses with {@see UnmappableStep}: no URL, a URL that is
 * not http or https, a URL chosen per tenant at run time, a credential header
 * (Integriq takes those from the Source), and every case where Integriq is
 * absent or does not answer with a Source.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Flow
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/webhook-steps-through-integriq/specs/webhook-steps-through-integriq/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Flow;

use OCA\Dossiq\AppInfo\Application;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IUserSession;
use Throwable;

/**
 * Builds `openconnector.source-call` steps from retired webhook steps.
 *
 * @spec openspec/changes/webhook-steps-through-integriq/specs/webhook-steps-through-integriq/spec.md
 */
class RetiredWebhookSteps {

	/**
	 * Integriq's source-call step. Its id keeps the pre-rename app name, frozen in stored flows.
	 *
	 * @var string
	 */
	public const SOURCE_CALL = 'openconnector.source-call';

	/**
	 * Integriq's find-or-create command, named rather than imported so dossiq runs without Integriq.
	 *
	 * @var string
	 */
	public const SOURCE_EVENT = 'OCA\\Integriq\\Event\\SourceRequestedEvent';

	/**
	 * The config key {@see RetiredActionRunner} hands a declared webhook's transition context in.
	 *
	 * @var string
	 */
	public const TRANSITION_KEY = '_transition';

	/**
	 * The whole item in Integriq's flow template: the case.
	 *
	 * @var string
	 */
	public const WHOLE_ITEM = '{{ @item }}';

	/**
	 * The timeouts the retired steps used, in seconds.
	 *
	 * @var int
	 */
	private const WEBHOOK_TIMEOUT = 5;
	private const CALL_WEBHOOK_TIMEOUT = 10;

	/**
	 * Where the retired steps put their result on the item.
	 *
	 * @var string
	 */
	private const DEFAULT_OUTPUT = 'actionResult';

	/**
	 * Header names Integriq refuses on a step, normalised: authentication comes from the Source.
	 *
	 * @var array<int, string>
	 */
	private const CREDENTIAL_HEADERS = ['authorization', 'proxyauthorization', 'cookie', 'setcookie', 'xapikey', 'apikey'];

	/**
	 * Constructor.
	 *
	 * @param IEventDispatcher      $dispatcher  Carries the Source request to Integriq.
	 * @param IUserSession          $userSession Who asked, for Integriq's record of the request.
	 * @param RetiredTemplateSyntax $syntax      Template rewrites.
	 */
	public function __construct(
		private readonly IEventDispatcher $dispatcher,
		private readonly IUserSession $userSession,
		private readonly RetiredTemplateSyntax $syntax,
	) {
	}//end __construct()

	/**
	 * `dossiq.webhook`: a transition's POST of the case and the transition to a URL.
	 *
	 * @param array<string, mixed> $config The retired configuration.
	 *
	 * @return array{type: string, config: array<string, mixed>} The source-call step.
	 *
	 * @throws UnmappableStep When the step cannot be carried over.
	 *
	 * @spec openspec/changes/webhook-steps-through-integriq/specs/webhook-steps-through-integriq/spec.md
	 */
	public function transition(array $config): array {
		$body = ['case' => self::WHOLE_ITEM];
		if (is_array($config[self::TRANSITION_KEY] ?? null) === true) {
			$body['transition'] = $config[self::TRANSITION_KEY];
		}

		$step = $this->call(url: trim((string)($config['url'] ?? '')), timeout: self::WEBHOOK_TIMEOUT, output: $config);
		$step['config']['body'] = $body;

		$headers = $this->headers(headers: ($config['headers'] ?? []));
		if ($headers !== []) {
			$step['config']['headers'] = $headers;
		}

		return $step;
	}//end transition()

	/**
	 * `dossiq.action.callWebhook`: a POST of the case, or of a rendered payload template.
	 *
	 * @param array<string, mixed> $config The retired configuration.
	 *
	 * @return array{type: string, config: array<string, mixed>} The source-call step.
	 *
	 * @throws UnmappableStep When the step cannot be carried over.
	 *
	 * @spec openspec/changes/webhook-steps-through-integriq/specs/webhook-steps-through-integriq/spec.md
	 */
	public function action(array $config): array {
		$timeout = (int)($config['timeoutSec'] ?? self::CALL_WEBHOOK_TIMEOUT);
		if ($timeout <= 0) {
			$timeout = self::CALL_WEBHOOK_TIMEOUT;
		}

		$step = $this->call(url: $this->actionUrl(config: $config), timeout: $timeout, output: $config);

		$template = (string)($config['payloadTemplate'] ?? '');
		if ($template === '') {
			$step['config']['body'] = ['case' => self::WHOLE_ITEM];
			return $step;
		}

		// The template was sent as written, as JSON, so it stays a string body
		// with the content type the retired step set.
		$step['config']['body'] = $this->syntax->forSourceCall(template: $template);
		$step['config']['headers'] = ['Content-Type' => 'application/json'];

		return $step;
	}//end action()

	/**
	 * The URL a callWebhook step called: `urlSlug` in `urlMap`, else `url`.
	 *
	 * An entry keyed by tenant was chosen at run time from the transition's
	 * tenant, so a stored step cannot name one URL for it.
	 *
	 * @param array<string, mixed> $config The retired configuration.
	 *
	 * @return string The URL.
	 *
	 * @throws UnmappableStep When the URL depends on the tenant.
	 */
	private function actionUrl(array $config): string {
		$slug = trim((string)($config['urlSlug'] ?? ''));
		$map = (array)($config['urlMap'] ?? []);
		if ($slug !== '') {
			foreach ($map as $key => $entry) {
				if (is_array($entry) === true && isset($entry[$slug]) === true) {
					throw new UnmappableStep(message: 'its URL "' . $slug . '" is chosen per tenant ("' . (string)$key . '") when the step runs, and a source-call step names one source');
				}
			}

			if (is_scalar($map[$slug] ?? null) === true) {
				return trim((string)$map[$slug]);
			}
		}

		return trim((string)($config['url'] ?? ''));
	}//end actionUrl()

	/**
	 * A POST source-call step to the URL: its base as a Source, its path and query as the endpoint.
	 *
	 * @param string               $url     The URL the retired step called.
	 * @param int                  $timeout The timeout a newly created Source gets.
	 * @param array<string, mixed> $output  The retired configuration, for its `output` key.
	 *
	 * @return array{type: string, config: array<string, mixed>} The step, without a body.
	 *
	 * @throws UnmappableStep When the URL is unusable or Integriq gives no Source.
	 */
	private function call(string $url, int $timeout, array $output): array {
		if ($url === '') {
			throw new UnmappableStep(message: 'the step names no URL');
		}

		$parts = parse_url($url);
		if (is_array($parts) === false) {
			$parts = [];
		}

		$scheme = strtolower((string)($parts['scheme'] ?? ''));
		if (in_array($scheme, ['http', 'https'], true) === false || (string)($parts['host'] ?? '') === '') {
			throw new UnmappableStep(message: 'its URL is not an http or https URL with a host');
		}

		if (isset($parts['user']) === true || isset($parts['pass']) === true) {
			throw new UnmappableStep(message: 'its URL carries a user name or password, which belong on the source in Integriq');
		}

		$base = $scheme . '://' . strtolower((string)$parts['host']);
		if (isset($parts['port']) === true) {
			$base .= ':' . (int)$parts['port'];
		}

		$endpoint = (string)($parts['path'] ?? '');
		if ($endpoint === '' || str_starts_with($endpoint, '/') === false) {
			$endpoint = '/' . $endpoint;
		}

		if (isset($parts['query']) === true) {
			$endpoint .= '?' . $parts['query'];
		}

		$outputKey = trim((string)($output['output'] ?? ''));
		if ($outputKey === '') {
			$outputKey = self::DEFAULT_OUTPUT;
		}

		return [
			'type' => self::SOURCE_CALL,
			'config' => [
				'source' => $this->source(base: $base, timeout: $timeout),
				'endpoint' => $endpoint,
				'method' => 'POST',
				'output' => $outputKey,
			],
		];
	}//end call()

	/**
	 * The uuid of Integriq's Source for a base URL, found or created on request.
	 *
	 * @param string $base    The base URL.
	 * @param int    $timeout The timeout a newly created Source gets.
	 *
	 * @return string The Source uuid.
	 *
	 * @throws UnmappableStep When Integriq is absent or gives no Source.
	 */
	private function source(string $base, int $timeout): string {
		$eventClass = '\\' . self::SOURCE_EVENT;
		if (class_exists($eventClass) === false) {
			throw new UnmappableStep(message: 'it calls ' . $base . ', and Integriq, which now makes outbound calls, is not installed to provide a source for it');
		}

		$event = new $eventClass(
			sourceApp: Application::APP_ID,
			baseUrl: $base,
			purpose: 'the webhook steps dossiq handed to Integriq',
			timeoutSeconds: $timeout,
			userId: $this->userSession->getUser()?->getUID(),
		);

		try {
			$this->dispatcher->dispatchTyped($event);
		} catch (Throwable $e) {
			throw new UnmappableStep(message: 'Integriq could not provide a source for ' . $base . ': ' . $e->getMessage());
		}

		$sourceId = trim((string)$event->getSourceId());
		if ($event->isHandled() === false || $sourceId === '') {
			$reason = trim((string)$event->getRefusal());
			if ($reason === '') {
				$reason = 'nothing answered the request';
			}

			throw new UnmappableStep(message: 'Integriq gave no source for ' . $base . ': ' . $reason);
		}

		return $sourceId;
	}//end source()

	/**
	 * The step's own request headers, refusing a credential header.
	 *
	 * @param mixed $headers The retired step's headers.
	 *
	 * @return array<string, string> The headers.
	 *
	 * @throws UnmappableStep When a header carries credentials.
	 */
	private function headers(mixed $headers): array {
		if (is_array($headers) === false) {
			return [];
		}

		$kept = [];
		foreach ($headers as $name => $value) {
			if (is_string($name) === false || is_scalar($value) === false) {
				continue;
			}

			$normalised = strtolower((string)preg_replace('/[^A-Za-z0-9]/', '', $name));
			if (in_array($normalised, self::CREDENTIAL_HEADERS, true) === true) {
				throw new UnmappableStep(message: 'it sends the "' . $name . '" header, and Integriq takes credentials from the source; set it there');
			}

			$kept[$name] = (string)$value;
		}

		return $kept;
	}//end headers()
}//end class
