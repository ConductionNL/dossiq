<?php

/**
 * Unit test for MailIntakeRegistrar.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Registrar
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Registrar;

use OCA\Dossiq\Listener\NewMessagesSynchronizedListener;
use OCA\Dossiq\Service\Email\Filters\AutoReplyFilter;
use OCA\Dossiq\Service\Email\Filters\BlockedSenderFilter;
use OCA\Dossiq\Service\Email\Filters\BounceNotificationFilter;
use OCA\Dossiq\Service\Email\Filters\FilterPipeline;
use OCA\Dossiq\Service\Email\Filters\JunkFilter;
use OCA\Dossiq\Service\Email\Filters\OutOfOfficeFilter;
use OCA\Dossiq\Service\Email\Filters\OwnNotificationLoopFilter;
use OCA\Dossiq\Service\Email\MailGatewayInterface;
use OCA\Dossiq\Service\Email\NextcloudMailGateway;
use OCA\Dossiq\Service\Registrar\MailIntakeRegistrar;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * The mail intake registrar binds the gateway, a filled filter pipeline and the sync listener.
 *
 * @covers \OCA\Dossiq\Service\Registrar\MailIntakeRegistrar
 * @uses \OCA\Dossiq\Service\Email\Filters\FilterPipeline
 */
class MailIntakeRegistrarTest extends TestCase {

	/**
	 * What the registrar registered.
	 *
	 * @var array{aliases: array<string, string>, services: array<string, callable>, listeners: array<int, array{0: string, 1: string}>}
	 */
	private array $registered = ['aliases' => [], 'services' => [], 'listeners' => []];

	/**
	 * Run the registrar against a recording context.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$context = $this->createMock(IRegistrationContext::class);
		$context->method('registerServiceAlias')->willReturnCallback(
			function (string $alias, string $target): void {
				$this->registered['aliases'][$alias] = $target;
			}
		);
		$context->method('registerService')->willReturnCallback(
			function (string $name, callable $factory): void {
				$this->registered['services'][$name] = $factory;
			}
		);
		$context->method('registerEventListener')->willReturnCallback(
			function (string $event, string $listener): void {
				$this->registered['listeners'][] = [$event, $listener];
			}
		);

		(new MailIntakeRegistrar())->register(context: $context);
	}//end setUp()

	/**
	 * The gateway seam is an alias onto the Nextcloud Mail gateway.
	 *
	 * @return void
	 */
	public function testTheGatewayIsAnAliasOntoTheMailGateway(): void {
		$this->assertSame([MailGatewayInterface::class => NextcloudMailGateway::class], $this->registered['aliases']);
	}//end testTheGatewayIsAnAliasOntoTheMailGateway()

	/**
	 * The sync listener listens to the Mail app's new-messages event.
	 *
	 * @return void
	 */
	public function testTheSyncListenerListensToTheMailEvent(): void {
		$this->assertSame(
			[[NextcloudMailGateway::NEW_MESSAGES_EVENT, NewMessagesSynchronizedListener::class]],
			$this->registered['listeners']
		);
	}//end testTheSyncListenerListensToTheMailEvent()

	/**
	 * The pipeline is built with all six filters, never empty, in their declared order.
	 *
	 * @return void
	 */
	public function testThePipelineHoldsEverySixFiltersInOrder(): void {
		$this->assertSame([FilterPipeline::class], array_keys($this->registered['services']));

		$classes = [
			OwnNotificationLoopFilter::class,
			BounceNotificationFilter::class,
			AutoReplyFilter::class,
			OutOfOfficeFilter::class,
			BlockedSenderFilter::class,
			JunkFilter::class,
		];
		$filters = [];
		// Hand them out in reverse slot order so the pipeline's own sort is what orders them.
		foreach ($classes as $index => $class) {
			$filter = $this->createMock($class);
			$filter->method('order')->willReturn(60 - ($index * 10));
			$filter->method('name')->willReturn('filter-' . $index);
			$filters[$class] = $filter;
		}

		$asked = [];
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use ($filters, &$asked): object {
				$asked[] = $id;
				if ($id === 'Psr\\Log\\LoggerInterface') {
					return $this->createMock(LoggerInterface::class);
				}

				return $filters[$id];
			}
		);

		$pipeline = $this->registered['services'][FilterPipeline::class]($container);

		$this->assertInstanceOf(FilterPipeline::class, $pipeline);
		$this->assertSame(array_merge($classes, ['Psr\\Log\\LoggerInterface']), $asked);
		$this->assertSame(
			['filter-5', 'filter-4', 'filter-3', 'filter-2', 'filter-1', 'filter-0'],
			$pipeline->declaredOrder()
		);
	}//end testThePipelineHoldsEverySixFiltersInOrder()
}//end class
