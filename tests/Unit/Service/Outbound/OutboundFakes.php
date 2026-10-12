<?php

/**
 * Fakes for the outbound-URL guard suites
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Outbound
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
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Outbound;

use OCA\Dossiq\Service\FieldValidator;
use OCA\Dossiq\Service\NotificatieService;
use OCA\Dossiq\Service\Outbound\HostResolver;
use OCA\Dossiq\Service\Outbound\OutboundUrlGuard;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\ZgwMappingService;
use OCA\Dossiq\Service\ZgwRulesBase;
use Psr\Log\LoggerInterface;

/**
 * A name lookup that answers from a table, and the two callers of the guard
 * with the request itself replaced by a recorder.
 */
trait OutboundFakes {
	/**
	 * Every request a caller made: its URL and its options.
	 *
	 * @var \ArrayObject<int, array{url: string, options: array}>|null
	 */
	private ?\ArrayObject $sent = null;

	/**
	 * A guard whose names answer from a table instead of from DNS.
	 *
	 * @param array<string, string[]>|null $names Addresses by host name; null for the real lookup.
	 * @param LoggerInterface|null $logger The logger; a mock when left out.
	 *
	 * @return OutboundUrlGuard
	 */
	private function guard(?array $names, ?LoggerInterface $logger = null): OutboundUrlGuard {
		$logger ??= $this->createMock(LoggerInterface::class);
		if ($names === null) {
			return new OutboundUrlGuard($logger);
		}

		$resolver = new class($names) extends HostResolver {
			/**
			 * @param array<string, string[]> $names Addresses by host name.
			 */
			public function __construct(private array $names) {
			}

			public function addressesOf(string $host): array {
				return ($this->names[$host] ?? []);
			}
		};

		return new OutboundUrlGuard($logger, $resolver);
	}//end guard()

	/**
	 * Fetch a URL the way a ZGW rules class does.
	 *
	 * @param string $url The URL.
	 * @param OutboundUrlGuard $guard The guard the rules class asks.
	 * @param string $body What the far side answers with.
	 *
	 * @return array|null What fetchExternalUrl() answered.
	 */
	private function zgwFetch(string $url, OutboundUrlGuard $guard, string $body = '{"reached": true}'): ?array {
		$this->sent = new \ArrayObject();
		$rules = new class($this->createMock(LoggerInterface::class), $this->createMock(SettingsService::class), new FieldValidator()) extends ZgwRulesBase {
			public ?OutboundUrlGuard $fakeGuard = null;

			public ?\ArrayObject $sent = null;

			public string $body = '';

			protected function outboundGuard(): OutboundUrlGuard {
				return $this->fakeGuard;
			}

			protected function httpGet(string $url, array $options): string {
				$this->sent->append(['url' => $url, 'options' => $options]);

				return $this->body;
			}

			public function fetch(string $url): ?array {
				return $this->fetchExternalUrl(url: $url);
			}
		};
		$rules->fakeGuard = $guard;
		$rules->sent = $this->sent;
		$rules->body = $body;

		return $rules->fetch(url: $url);
	}//end zgwFetch()

	/**
	 * Publish one notification on channel "zaken" to one stored subscription.
	 *
	 * @param array $subscription The stored subscription.
	 * @param OutboundUrlGuard $guard The guard the service asks.
	 *
	 * @return void
	 */
	private function notify(array $subscription, OutboundUrlGuard $guard): void {
		$this->sent = new \ArrayObject();
		$mapping = $this->createMock(ZgwMappingService::class);
		$mapping->method('getMapping')->willReturn(['sourceRegister' => 'r', 'sourceSchema' => 's']);

		$service = new class($mapping, $this->createMock(LoggerInterface::class), $guard) extends NotificatieService {
			public ?\ArrayObject $sent = null;

			protected function httpPost(string $url, array $options): void {
				$this->sent->append(['url' => $url, 'options' => $options]);
			}
		};
		$service->sent = $this->sent;

		$objects = new class($subscription) {
			/**
			 * @param array $subscription The one stored subscription.
			 */
			public function __construct(private array $subscription) {
			}

			public function buildSearchQuery(array $requestParams, string $register, string $schema): array {
				return [];
			}

			public function searchObjectsPaginated(array $query): array {
				return ['results' => [$this->subscription]];
			}
		};
		(new \ReflectionProperty(NotificatieService::class, 'objectService'))->setValue($service, $objects);

		$service->publish(
			channel: 'zaken',
			hoofdObject: 'https://zaken.example.com/zaken/1',
			resource: 'zaak',
			resourceUrl: 'https://zaken.example.com/zaken/1',
			action: 'create'
		);
	}//end notify()
}//end trait
