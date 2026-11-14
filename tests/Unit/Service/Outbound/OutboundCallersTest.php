<?php

/**
 * The two callers of the outbound-URL guard
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

use PHPUnit\Framework\TestCase;

/**
 * The ZGW fetch and the notification delivery, from the caller's side.
 *
 * A guard with a full suite and no call site protects nothing. These tests
 * drive the two callers and look at the request they make: none when the guard
 * refuses, and one that carries the guard's options when it does not.
 *
 * @covers \OCA\Dossiq\Service\ZgwRulesBase
 * @uses \OCA\Dossiq\Service\Zgw\ZgwRuleLookups
 * @uses \OCA\Dossiq\Service\Zgw\ZgwRuleResults
 * @covers \OCA\Dossiq\Service\NotificatieService
 * @uses \OCA\Dossiq\Service\Outbound\OutboundUrlGuard
 * @uses \OCA\Dossiq\Service\Outbound\HostResolver
 * @uses \OCA\Dossiq\Service\FieldValidator
 *
 * @spec openspec/changes/method-decomposition/tasks.md
 */
class OutboundCallersTest extends TestCase {
	use OutboundFakes;

	private const NAMES = [
		'selectielijst.example.com' => ['93.184.216.34'],
		'subscriber.example.com' => ['93.184.216.34'],
		'rebind.example.com' => ['93.184.216.34', '169.254.169.254'],
	];

	/**
	 * A stored subscription on channel "zaken".
	 *
	 * @param string $callbackUrl The callback URL.
	 * @param array $extra Fields that replace the defaults.
	 *
	 * @return array
	 */
	private function subscription(string $callbackUrl, array $extra = []): array {
		return array_merge(['kanalen' => [['name' => 'zaken']], 'callbackUrl' => $callbackUrl, 'auth' => 'Bearer abc'], $extra);
	}//end subscription()

	/**
	 * An approved fetch makes one request, to the pinned address, without redirects.
	 *
	 * @return void
	 */
	public function testAnApprovedFetchConnectsWithTheGuardsOptions(): void {
		$url = 'https://selectielijst.example.com/api/v1/procestypen/1';

		$this->assertSame(['nummer' => 1], $this->zgwFetch(url: $url, guard: $this->guard(names: self::NAMES), body: '{"nummer": 1}'));
		$this->assertSame(
			[
				[
					'url' => $url,
					'options' => [
						'timeout' => 10,
						'allow_redirects' => false,
						'verify' => true,
						'curl' => [CURLOPT_RESOLVE => ['selectielijst.example.com:443:93.184.216.34']],
					],
				],
			],
			$this->sent->getArrayCopy()
		);
	}//end testAnApprovedFetchConnectsWithTheGuardsOptions()

	/**
	 * A refused fetch answers null and makes no request.
	 *
	 * Plain http is the row that changed: the ZGW copy accepted it as a scheme.
	 *
	 * @return void
	 */
	public function testARefusedFetchMakesNoRequest(): void {
		$guard = $this->guard(names: self::NAMES);

		foreach (['http://selectielijst.example.com/x', 'https://rebind.example.com/x', 'https://unknown.example.com/x'] as $url) {
			$this->assertNull($this->zgwFetch(url: $url, guard: $guard), $url);
			$this->assertCount(0, $this->sent, $url);
		}
	}//end testARefusedFetchMakesNoRequest()

	/**
	 * A fetch that answers with something other than a JSON object or list is null.
	 *
	 * @return void
	 */
	public function testAFetchedBodyThatIsNoJsonArrayIsNull(): void {
		$url = 'https://selectielijst.example.com/x';

		$this->assertNull($this->zgwFetch(url: $url, guard: $this->guard(names: self::NAMES), body: ''));
		$this->assertNull($this->zgwFetch(url: $url, guard: $this->guard(names: self::NAMES), body: '"text"'));
		$this->assertCount(1, $this->sent);
	}//end testAFetchedBodyThatIsNoJsonArrayIsNull()

	/**
	 * An approved callback gets one POST, to the pinned address, without redirects.
	 *
	 * @return void
	 */
	public function testAnApprovedCallbackIsDeliveredWithTheGuardsOptions(): void {
		$url = 'https://subscriber.example.com:8443/callback';
		$this->notify(subscription: $this->subscription(callbackUrl: $url), guard: $this->guard(names: self::NAMES));

		$this->assertCount(1, $this->sent);
		$sent = $this->sent[0];
		$this->assertSame($url, $sent['url']);
		$this->assertSame('zaken', $sent['options']['json']['notificationChannel']);
		$this->assertSame('create', $sent['options']['json']['action']);
		$this->assertSame(['Content-Type' => 'application/json', 'Authorization' => 'Bearer abc'], $sent['options']['headers']);
		$this->assertSame(10, $sent['options']['timeout']);
		$this->assertFalse($sent['options']['allow_redirects']);
		$this->assertTrue($sent['options']['verify']);
		$this->assertSame(['subscriber.example.com:8443:93.184.216.34'], $sent['options']['curl'][CURLOPT_RESOLVE]);
	}//end testAnApprovedCallbackIsDeliveredWithTheGuardsOptions()

	/**
	 * A refused callback gets nothing: no request, and so no Authorization header either.
	 *
	 * @return void
	 */
	public function testARefusedCallbackGetsNothing(): void {
		$guard = $this->guard(names: self::NAMES);

		foreach (['http://subscriber.example.com/callback', 'https://rebind.example.com/callback', 'https://unknown.example.com/callback'] as $url) {
			$this->notify(subscription: $this->subscription(callbackUrl: $url), guard: $guard);
			$this->assertCount(0, $this->sent, $url);
		}
	}//end testARefusedCallbackGetsNothing()

	/**
	 * A subscription on another channel, or without a callback URL, is not delivered to.
	 *
	 * @return void
	 */
	public function testOnlyAMatchingSubscriptionWithACallbackIsDeliveredTo(): void {
		$guard = $this->guard(names: self::NAMES);
		$url = 'https://subscriber.example.com/callback';

		$this->notify(subscription: $this->subscription(callbackUrl: $url, extra: ['kanalen' => [['name' => 'documenten']]]), guard: $guard);
		$this->assertCount(0, $this->sent);
		$this->notify(subscription: $this->subscription(callbackUrl: ''), guard: $guard);
		$this->assertCount(0, $this->sent);
		$this->notify(subscription: $this->subscription(callbackUrl: $url, extra: ['auth' => '']), guard: $guard);
		$this->assertSame(['Content-Type' => 'application/json'], $this->sent[0]['options']['headers']);
	}//end testOnlyAMatchingSubscriptionWithACallbackIsDeliveredTo()
}//end class
