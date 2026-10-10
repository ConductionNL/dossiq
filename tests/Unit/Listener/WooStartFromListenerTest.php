<?php

/**
 * Woo Start From Listener Test
 *
 * A case created with `wooStartFrom` gets the earlier request's configuration
 * and a draft plan, through OpenRegister's real creation event.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/woo-request-corpus-collection/specs/woo-case-type/spec.md#requirement-a-new-request-starts-from-the-configuration-of-an-earlier-one-req-wrc-005
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Listener;

use OCA\Dossiq\Listener\WooStartFromListener;
use OCA\Dossiq\Service\ObjectSchemaSlugResolver;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Tests\Support\InMemoryRegister;
use OCA\Dossiq\Woo\WooSearchPlans;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Dossiq\Listener\WooStartFromListener
 * @uses   \OCA\Dossiq\Woo\WooSearchPlans
 * @uses   \OCA\Dossiq\Service\Support\SearchesObjects
 */
class WooStartFromListenerTest extends TestCase {

	private const EARLIER = '55555555-5555-4555-8555-555555555555';
	private const NEW_CASE = '66666666-6666-4666-8666-666666666666';

	private InMemoryRegister $register;

	private string $slug = 'case';

	/**
	 * The listener over the real plan service.
	 *
	 * @return WooStartFromListener The listener.
	 */
	private function listener(): WooStartFromListener {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->register);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => [
				'register' => 'dossiq',
				'woo_search_plan_schema' => 'wooSearchPlan',
				'woo_request_configuration_schema' => 'wooRequestConfiguration',
			][$key] ?? $default
		);
		$slugs = $this->createMock(ObjectSchemaSlugResolver::class);
		$slugs->method('resolveFromPayload')->willReturnCallback(fn (): string => $this->slug);

		return new WooStartFromListener(plans: new WooSearchPlans(settingsService: $settings), slugResolver: $slugs, logger: $this->createMock(LoggerInterface::class));
	}//end listener()

	/**
	 * A creation event for an object.
	 *
	 * @param array<string, mixed> $object The object.
	 *
	 * @return ObjectCreatedEvent The event.
	 */
	private function created(array $object): ObjectCreatedEvent {
		$entity = new ObjectEntity();
		$entity->setUuid((string)($object['id'] ?? ''));
		unset($object['id']);
		$entity->setObject($object);
		return new ObjectCreatedEvent($entity);
	}//end created()

	protected function setUp(): void {
		$this->register = new InMemoryRegister();
		$this->register->seed('wooRequestConfiguration', 'conf-1', ['case' => self::EARLIER, 'custodians' => [['name' => 'A'], ['name' => 'B']], 'systems' => ['files', 'microsoft365'], 'terms' => 'Stationsweg']);
	}//end setUp()

	/**
	 * REQ-WRC-005 "Start from the last request".
	 *
	 * @return void
	 */
	public function testANewCaseStartsFromTheEarlierConfiguration(): void {
		$this->listener()->handle($this->created(['id' => self::NEW_CASE, 'wooStartFrom' => self::EARLIER]));

		$draft = $this->register->all('wooSearchPlan')[0];
		self::assertSame(self::NEW_CASE, $draft['case']);
		self::assertSame([['name' => 'A'], ['name' => 'B']], $draft['custodians']);
		self::assertSame(['files', 'microsoft365'], $draft['systems']);
		self::assertSame('Stationsweg', $draft['terms']);
		self::assertArrayNotHasKey('periodFrom', $draft);
		self::assertArrayNotHasKey('recordedAt', $draft);
	}//end testANewCaseStartsFromTheEarlierConfiguration()

	public function testOtherObjectsAndCasesWithoutAnEarlierRequestAreLeftAlone(): void {
		$this->listener()->handle($this->created(['id' => self::NEW_CASE]));
		$this->slug = 'task';
		$this->listener()->handle($this->created(['id' => self::NEW_CASE, 'wooStartFrom' => self::EARLIER]));
		$this->listener()->handle(new Event());

		self::assertSame([], $this->register->all('wooSearchPlan'));
	}//end testOtherObjectsAndCasesWithoutAnEarlierRequestAreLeftAlone()
}//end class
