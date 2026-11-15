<?php

/**
 * ReadsCaseWrites — the case a pre-persist event is about to save.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Listener\Support;

use OCA\Dossiq\Kernel\Contracts\SettingsServiceInterface;
use OCA\Dossiq\Listener\Support\ReadsCaseWrites;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The trait, through a minimal host class.
 *
 * @covers \OCA\Dossiq\Listener\Support\ReadsCaseWrites
 */
final class ReadsCaseWritesTest extends TestCase {

	/**
	 * A host composing the trait, exposing the case as saved.
	 *
	 * @return object The host.
	 */
	private function host(): object {
		$settings = $this->createMock(SettingsServiceInterface::class);
		$settings->method('getConfigValue')->with('case_schema')->willReturn('case-schema');

		return new class($settings, $this->createMock(LoggerInterface::class)) {
			use ReadsCaseWrites;

			private const PAYLOAD_CHECK = 'test check';

			/**
			 * Constructor.
			 *
			 * @param SettingsServiceInterface $settingsService The settings.
			 * @param LoggerInterface          $logger          The logger.
			 */
			public function __construct(
				private readonly SettingsServiceInterface $settingsService,
				private readonly LoggerInterface $logger,
			) {
			}//end __construct()

			/**
			 * The case as the save will leave it.
			 *
			 * @param ObjectCreatingEvent $event  The event.
			 * @param ObjectEntity        $entity The entity.
			 *
			 * @return array<string, mixed>|null
			 */
			public function saved(ObjectCreatingEvent $event, ObjectEntity $entity): ?array {
				return $this->caseAsSaved(event: $event, entity: $entity);
			}//end saved()
		};
	}//end host()

	/**
	 * A case gets the modified data on top; another schema reads as null.
	 *
	 * @return void
	 */
	public function testOnlyACaseIsRead(): void {
		$case = new ObjectEntity();
		$case->setObject(['title' => 'old', '@self' => ['schema' => 'case-schema']]);
		$case->setSchema('case-schema');
		$event = new ObjectCreatingEvent($case);
		$event->setModifiedData(['title' => 'new']);
		$other = new ObjectEntity();
		$other->setObject(['title' => 'x']);
		$other->setSchema('other-schema');
		$host = $this->host();

		$saved = $host->saved($event, $case);
		self::assertIsArray($saved);
		self::assertSame('new', $saved['title']);
		self::assertNull($host->saved(new ObjectCreatingEvent($other), $other));
	}//end testOnlyACaseIsRead()
}//end class
