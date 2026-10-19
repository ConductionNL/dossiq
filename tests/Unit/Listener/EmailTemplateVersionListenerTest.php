<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Listener;

use OCA\Dossiq\Listener\EmailTemplateVersionListener;
use OCA\Dossiq\Service\Email\EmailTemplateRepository;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Tests\Support\InMemoryRegister;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * An email template keeps its earlier wording on every write path.
 *
 * @covers \OCA\Dossiq\Listener\EmailTemplateVersionListener
 */
class EmailTemplateVersionListenerTest extends TestCase {

	/**
	 * @var InMemoryRegister The register the copies land in.
	 */
	private InMemoryRegister $store;

	/**
	 * @return void
	 */
	protected function setUp(): void {
		$this->store = new InMemoryRegister();
	}//end setUp()

	/**
	 * @param array<string, mixed> $object The state.
	 * @param string               $schema The schema.
	 *
	 * @return ObjectEntity The entity.
	 */
	private function entity(array $object, string $schema = 'emailTemplate'): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setObject($object);
		$entity->setSchema($schema);
		$entity->setUuid('tpl-1');

		return $entity;
	}//end entity()

	/**
	 * @return EmailTemplateVersionListener The listener over the in-memory register.
	 */
	private function listener(): EmailTemplateVersionListener {
		$config = ['register' => 'dossiq', 'email_template_schema' => 'emailTemplate'];
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => ($config[$key] ?? $default)
		);
		$settings->method('getObjectService')->willReturn($this->store);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		return new EmailTemplateVersionListener(
			settingsService: $settings,
			repository: new EmailTemplateRepository(settingsService: $settings),
			l10n: $l10n,
			logger: new NullLogger()
		);
	}//end listener()

	/**
	 * @return void
	 */
	public function testANewTemplateStartsAtVersionOne(): void {
		$event = new ObjectCreatingEvent($this->entity(['caseType' => 'ct-1', 'name' => 'Ontvangst', 'body' => 'Beste {{contactNaam}}']));

		$this->listener()->handle($event);

		$this->assertSame(['version' => 1, 'isActive' => true], $event->getModifiedData());
	}//end testANewTemplateStartsAtVersionOne()

	/**
	 * @return void
	 */
	public function testAChangedWordingKeepsTheEarlierOneAsAnInactiveCopy(): void {
		$before = ['caseType' => 'ct-1', 'name' => 'Ontvangst', 'subject' => 'Ontvangen', 'body' => 'Oud', 'version' => 2, 'isActive' => true];
		$after = array_merge($before, ['body' => 'Nieuw']);
		$event = new ObjectUpdatingEvent($this->entity($after), $this->entity($before));

		$this->listener()->handle($event);

		$copies = array_values($this->store->all('emailTemplate'));
		$this->assertCount(1, $copies);
		$this->assertSame('Oud', $copies[0]['body']);
		$this->assertFalse($copies[0]['isActive']);
		$this->assertSame(2, $copies[0]['version']);
		$this->assertSame(['version' => 3, 'isActive' => true, 'previousVersion' => $copies[0]['id']], $event->getModifiedData());
	}//end testAChangedWordingKeepsTheEarlierOneAsAnInactiveCopy()

	/**
	 * @return void
	 */
	public function testADeactivationOrAnotherSchemaMakesNoVersion(): void {
		$before = ['caseType' => 'ct-1', 'name' => 'Ontvangst', 'body' => 'Oud', 'version' => 2, 'isActive' => true];
		$deactivate = new ObjectUpdatingEvent($this->entity(array_merge($before, ['isActive' => false])), $this->entity($before));
		$this->listener()->handle($deactivate);

		$other = new ObjectUpdatingEvent($this->entity(array_merge($before, ['body' => 'Nieuw']), 'case'), $this->entity($before, 'case'));
		$this->listener()->handle($other);

		$this->assertSame([], $this->store->all('emailTemplate'));
		$this->assertSame([], $deactivate->getModifiedData());
		$this->assertSame([], $other->getModifiedData());
	}//end testADeactivationOrAnotherSchemaMakesNoVersion()
}//end class
