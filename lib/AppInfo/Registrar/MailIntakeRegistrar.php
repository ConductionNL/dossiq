<?php

/**
 * Dossiq mail intake registrar.
 *
 * Wires the inbound mail pipeline: the gateway behind its interface, the filter
 * order, and the listener for Nextcloud Mail's synchronisation event.
 *
 * THE PIPELINE IS ASSEMBLED HERE AND NOWHERE ELSE. Nextcloud cannot autowire a
 * constructor that takes a list of implementations, and an autowired pipeline
 * would silently be an EMPTY one, which accepts every message and looks exactly
 * like a pipeline that ran. So the filters are named here, and
 * `FilterPipelineTest` asserts the assembled order rather than trusting it.
 *
 * @category AppInfo
 * @package  OCA\Dossiq\AppInfo\Registrar
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\AppInfo\Registrar;

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
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use Psr\Container\ContainerInterface;

/**
 * Registers the inbound mail gateway, pipeline and listener.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
 */
class MailIntakeRegistrar {

	/**
	 * The filters, in the order this app declares them.
	 *
	 * Each filter also carries its own `order()`, and the pipeline sorts by
	 * that rather than by this list: a list is easy to reorder by accident and
	 * a number beside the filter is not.
	 *
	 * @var string[]
	 */
	private const FILTERS = [
		OwnNotificationLoopFilter::class,
		BounceNotificationFilter::class,
		AutoReplyFilter::class,
		OutOfOfficeFilter::class,
		BlockedSenderFilter::class,
		JunkFilter::class,
	];

	/**
	 * Register the gateway, the pipeline and the synchronisation listener.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
	 */
	public function register(IRegistrationContext $context): void {
		// An alias, not a factory: nothing resolves until something asks, so an
		// instance without the Mail app still boots.
		$context->registerServiceAlias(
			MailGatewayInterface::class,
			NextcloudMailGateway::class
		);

		$context->registerService(
			FilterPipeline::class,
			static function (ContainerInterface $c): FilterPipeline {
				$filters = [];
				foreach (self::FILTERS as $filter) {
					$filters[] = $c->get($filter);
				}

				return new FilterPipeline(
					logger: $c->get('Psr\\Log\\LoggerInterface'),
					filters: $filters
				);
			}
		);

		// The event class is Nextcloud Mail's, so the only place that names it
		// is the gateway. Registering a listener for an event class that does
		// not exist is inert, which is exactly what an instance without Mail
		// should get.
		$context->registerEventListener(
			event: NextcloudMailGateway::NEW_MESSAGES_EVENT,
			listener: NewMessagesSynchronizedListener::class
		);
	}//end register()
}//end class
