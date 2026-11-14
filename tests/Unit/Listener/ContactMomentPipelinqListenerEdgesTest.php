<?php

/**
 * What happens to a logged contact moment when the bridge to the sales app refuses it.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Listener
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

namespace OCA\Dossiq\Tests\Unit\Listener;

use OCA\Dossiq\Kernel\Contracts\ContactMomentBridgeInterface;
use OCA\Dossiq\Kernel\Contracts\ObjectSchemaSlugResolverInterface;
use OCA\Dossiq\Listener\ContactMomentPipelinqListener;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The bridge-failure path of ContactMomentPipelinqListener.
 *
 * @covers \OCA\Dossiq\Listener\ContactMomentPipelinqListener
 * @uses   \OCA\Dossiq\Listener\Support\CreatedCaseRecord
 * @uses   \OCA\Dossiq\Listener\Support\EventObjectPayload
 */
class ContactMomentPipelinqListenerEdgesTest extends TestCase {

	/**
	 * A refused append is logged with the trimmed case id and does not escape.
	 *
	 * @return void
	 */
	public function testARefusedAppendIsLoggedWithTheCase(): void {
		$bridge = $this->createMock(ContactMomentBridgeInterface::class);
		$bridge->expects(self::once())->method('append')
			->with('case-1', self::callback(static fn (array $moment): bool => ($moment['direction'] ?? '') === 'inbound'))
			->willThrowException(new RuntimeException('pipelinq offline'));

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())->method('warning')->with(
			'Dossiq pipelinq: a contact moment on case {case} was saved here and not appended there: {reason}',
			['app' => 'dossiq', 'case' => 'case-1', 'reason' => 'pipelinq offline'],
		);

		$this->listener(bridge: $bridge, logger: $logger)->handle(self::created(['case' => '  case-1 ', 'direction' => 'inbound']));
	}//end testARefusedAppendIsLoggedWithTheCase()

	/**
	 * A successful append logs nothing.
	 *
	 * @return void
	 */
	public function testASuccessfulAppendLogsNothing(): void {
		$bridge = $this->createMock(ContactMomentBridgeInterface::class);
		$bridge->expects(self::once())->method('append')->with('case-1', self::anything());

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::never())->method('warning');

		$this->listener(bridge: $bridge, logger: $logger)->handle(self::created(['case' => 'case-1']));
	}//end testASuccessfulAppendLogsNothing()

	/**
	 * The listener.
	 *
	 * @param ContactMomentBridgeInterface $bridge The bridge.
	 * @param LoggerInterface              $logger The logger.
	 *
	 * @return ContactMomentPipelinqListener The listener.
	 */
	private function listener(ContactMomentBridgeInterface $bridge, LoggerInterface $logger): ContactMomentPipelinqListener {
		$slugs = $this->createMock(ObjectSchemaSlugResolverInterface::class);
		$slugs->method('resolveFromPayload')->willReturn(ContactMomentPipelinqListener::SCHEMA);

		return new ContactMomentPipelinqListener(bridge: $bridge, slugResolver: $slugs, logger: $logger);
	}//end listener()

	/**
	 * A created contact moment.
	 *
	 * @param array<string, mixed> $record The fields.
	 *
	 * @return ObjectCreatedEvent The event.
	 */
	private static function created(array $record): ObjectCreatedEvent {
		$entity = new ObjectEntity();
		$entity->setUuid('cm-1');
		$entity->setSchema('contactmoment');
		$entity->setObject($record);

		return new ObjectCreatedEvent($entity);
	}//end created()
}//end class
