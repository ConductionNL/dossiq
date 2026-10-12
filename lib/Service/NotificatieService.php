<?php

/**
 * Dossiq Notificatie Service
 *
 * Handles ZGW notification (NRC) publishing — finds matching subscriptions
 * and delivers notifications via HTTP POST to registered callback URLs.
 *
 * @category Service
 * @package  OCA\Dossiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/zgw-api-mapping/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use DateTime;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use OCA\Dossiq\Service\Outbound\OutboundUrlGuard;
use Psr\Log\LoggerInterface;

/**
 * Service for publishing ZGW notifications to subscribers.
 *
 * @spec openspec/specs/zgw-api-mapping/spec.md
 */
class NotificatieService {

	/**
	 * The OpenRegister ObjectService (loaded dynamically).
	 *
	 * @var object|null
	 */
	private $objectService = null;

	/**
	 * Constructor.
	 *
	 * @param ZgwMappingService $zgwMappingService The ZGW mapping service
	 * @param LoggerInterface $logger The logger
	 * @param OutboundUrlGuard $outboundGuard Decides which address a callback may be delivered to
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ZgwMappingService $zgwMappingService,
		private readonly LoggerInterface $logger,
		private readonly OutboundUrlGuard $outboundGuard,
	) {
		$this->loadOpenRegisterServices();
	}//end __construct()

	/**
	 * Load OpenRegister services dynamically.
	 *
	 * @return void
	 */
	private function loadOpenRegisterServices(): void {
		try {
			$container = \OC::$server;
			$this->objectService = $container->get(
				'OCA\OpenRegister\Service\ObjectService'
			);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'NotificatieService: OpenRegister not available',
				['exception' => $e->getMessage()]
			);
		}
	}//end loadOpenRegisterServices()

	/**
	 * Publish a notification for a ZGW resource change.
	 *
	 * Finds all subscriptions matching the kanaal and delivers the
	 * notification payload via HTTP POST to each callback URL.
	 *
	 * @param string $channel The channel name (e.g. 'zaken', 'documenten')
	 * @param string $hoofdObject The main object URL
	 * @param string $resource The resource name (e.g. 'case', 'status')
	 * @param string $resourceUrl The resource URL
	 * @param string $action The action ('create', 'update', 'destroy')
	 * @param array $characteristics Optional filter attributes for matching
	 *
	 * @return void
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function publish(
		string $channel,
		string $hoofdObject,
		string $resource,
		string $resourceUrl,
		string $action,
		array $characteristics = [],
	): void {
		$notification = [
			'notificationChannel' => $channel,
			'hoofdObject' => $hoofdObject,
			'resource' => $resource,
			'resourceUrl' => $resourceUrl,
			'action' => $action,
			'aanmaakdatum' => (new DateTime())->format('c'),
			'kenmerken' => $characteristics,
		];

		try {
			$this->deliver(notification: $notification);
		} catch (\Exception $e) {
			$this->logger->warning(
				'Failed to deliver notification',
				[
					'notificationChannel' => $channel,
					'action' => $action,
					'exception' => $e->getMessage(),
				]
			);
		}
	}//end publish()

	/**
	 * Find matching subscriptions and deliver the notification.
	 *
	 * @param array $notification The notification payload
	 *
	 * @return void
	 */
	private function deliver(array $notification): void {
		if ($this->objectService === null) {
			return;
		}

		$abonnementMapping = $this->zgwMappingService->getMapping(
			resourceKey: 'abonnement'
		);
		if ($abonnementMapping === null) {
			return;
		}

		$query = $this->objectService->buildSearchQuery(
			requestParams: [],
			register: $abonnementMapping['sourceRegister'],
			schema: $abonnementMapping['sourceSchema']
		);
		$result = $this->objectService->searchObjectsPaginated(query: $query);

		$subscriptions = $result['results'] ?? [];

		foreach ($subscriptions as $subscription) {
			$subData = $subscription;
			if (is_array($subscription) === false) {
				$subData = $subscription->jsonSerialize();
			}

			$this->deliverToSubscription(
				subscription: $subData,
				notification: $notification
			);
		}
	}//end deliver()

	/**
	 * Deliver notification to a single subscription if it matches.
	 *
	 * @param array $subscription The subscription data
	 * @param array $notification The notification payload
	 *
	 * @return void
	 */
	private function deliverToSubscription(array $subscription, array $notification): void {
		$kanalen = $subscription['kanalen'] ?? [];

		// Check if this subscription listens to the notification channel.
		$matches = false;
		foreach ($kanalen as $channelConfig) {
			if (($channelConfig['name'] ?? '') === $notification['notificationChannel']) {
				$matches = true;
				break;
			}
		}

		if ($matches === false) {
			return;
		}

		$callbackUrl = $subscription['callbackUrl'] ?? '';
		$auth = $subscription['auth'] ?? '';

		if ($callbackUrl === '') {
			return;
		}

		// SSRF guard: the callback URL comes from a subscription, so it is
		// resolved before anything connects to it: https only, no address inside
		// the instance's own network.
		$address = $this->outboundGuard->publicAddress(url: $callbackUrl);
		if ($address === null) {
			$this->logger->warning(
				'Notification delivery blocked: callback URL failed SSRF check',
				['callbackUrl' => substr($callbackUrl, 0, 100)]
			);
			return;
		}

		try {
			$headers = ['Content-Type' => 'application/json'];
			if ($auth !== '') {
				$headers['Authorization'] = $auth;
			}

			// Delivered to the address that was inspected, without following a
			// redirect: a redirect is a second URL that nobody inspected.
			$this->httpPost(
				url: $callbackUrl,
				options: ([
					'json' => $notification,
					'headers' => $headers,
					'timeout' => 10,
				] + $this->outboundGuard->requestOptions(url: $callbackUrl, address: $address))
			);

			$this->logger->info(
				'Notification delivered',
				[
					'notificationChannel' => $notification['notificationChannel'],
					'callbackUrl' => $callbackUrl,
				]
			);
		} catch (GuzzleException $e) {
			$this->logger->warning(
				'Notification delivery failed',
				[
					'callbackUrl' => $callbackUrl,
					'exception' => $e->getMessage(),
				]
			);
		}//end try
	}//end deliverToSubscription()

	/**
	 * Make the POST request of one delivery.
	 *
	 * Its own method so a test can see the options a delivery connects with:
	 * Guzzle is Nextcloud's, and is not there when the unit tests run.
	 *
	 * @param string $url The callback URL the guard approved.
	 * @param array<string, mixed> $options The request options, the guard's included.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	protected function httpPost(string $url, array $options): void {
		(new Client())->post($url, $options);
	}//end httpPost()
}//end class
