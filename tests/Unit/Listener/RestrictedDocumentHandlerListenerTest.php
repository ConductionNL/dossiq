<?php

/**
 * RestrictedDocumentHandlerListener unit tests
 *
 * A reassigned case changes who handles it, so the readers of its restricted
 * documents are refreshed. Anything else (another schema, an unconfigured case
 * schema, a save that leaves the assignee alone) refreshes nothing, and a
 * failing refresh is logged rather than thrown into the save.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Listener;

use OCA\Dossiq\Listener\RestrictedDocumentHandlerListener;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Zaakdossier\RestrictedDocumentReaders;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Unit tests for the restricted-document reader refresh on reassignment.
 *
 * @covers \OCA\Dossiq\Listener\RestrictedDocumentHandlerListener
 */
class RestrictedDocumentHandlerListenerTest extends TestCase {

	/**
	 * The mocked settings.
	 *
	 * @var SettingsService|MockObject
	 */
	private SettingsService $settings;

	/**
	 * The mocked reader refresher.
	 *
	 * @var RestrictedDocumentReaders|MockObject
	 */
	private RestrictedDocumentReaders $readers;

	/**
	 * The mocked logger.
	 *
	 * @var LoggerInterface|MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * Build the collaborators; the case schema is `case`.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->settings = $this->createMock(SettingsService::class);
		$this->settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key): string => ($key === 'case_schema' ? 'case' : '')
		);
		$this->readers = $this->createMock(RestrictedDocumentReaders::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * A new assignee refreshes the readers of that case, once.
	 *
	 * @return void
	 */
	public function testAReassignedCaseRefreshesItsReaders(): void {
		$this->readers->expects($this->once())->method('refreshCase')->with('case-1')->willReturn(2);
		$this->logger->expects($this->never())->method('error');

		$this->listener()->handle($this->updated(schema: 'case', before: 'anna', after: 'hanna'));
	}//end testAReassignedCaseRefreshesItsReaders()

	/**
	 * A first assignee, on a case that had none, is a reassignment too.
	 *
	 * @return void
	 */
	public function testAFirstAssigneeRefreshesTheReaders(): void {
		$this->readers->expects($this->once())->method('refreshCase')->with('case-1');

		$this->listener()->handle($this->updated(schema: 'case', before: null, after: 'hanna'));
	}//end testAFirstAssigneeRefreshesTheReaders()

	/**
	 * An update without a previous version compares against no assignee.
	 *
	 * @return void
	 */
	public function testAnUpdateWithoutAnOldObjectComparesAgainstNoAssignee(): void {
		$this->readers->expects($this->once())->method('refreshCase')->with('case-1');

		$this->listener()->handle(new ObjectUpdatedEvent($this->entity(schema: 'case', assignee: 'hanna')));
	}//end testAnUpdateWithoutAnOldObjectComparesAgainstNoAssignee()

	/**
	 * A save that keeps the assignee refreshes nothing.
	 *
	 * @return void
	 */
	public function testAnUnchangedAssigneeRefreshesNothing(): void {
		$this->readers->expects($this->never())->method('refreshCase');

		$this->listener()->handle($this->updated(schema: 'case', before: 'hanna', after: 'hanna'));
	}//end testAnUnchangedAssigneeRefreshesNothing()

	/**
	 * Another schema's object is none of this listener's business.
	 *
	 * @return void
	 */
	public function testAnotherSchemaIsIgnored(): void {
		$this->readers->expects($this->never())->method('refreshCase');

		$this->listener()->handle($this->updated(schema: 'task', before: 'anna', after: 'hanna'));
	}//end testAnotherSchemaIsIgnored()

	/**
	 * With no case schema configured nothing counts as a case.
	 *
	 * @return void
	 */
	public function testAnUnconfiguredCaseSchemaRefreshesNothing(): void {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getConfigValue')->willReturn('');
		$this->readers->expects($this->never())->method('refreshCase');

		$listener = new RestrictedDocumentHandlerListener($settings, $this->readers, $this->logger);
		$listener->handle($this->updated(schema: '', before: 'anna', after: 'hanna'));
	}//end testAnUnconfiguredCaseSchemaRefreshesNothing()

	/**
	 * Only an update can reassign; a create is left alone.
	 *
	 * @return void
	 */
	public function testOtherEventsAreIgnored(): void {
		$this->readers->expects($this->never())->method('refreshCase');
		$this->settings->expects($this->never())->method('getConfigValue');

		$this->listener()->handle(new ObjectCreatedEvent($this->entity(schema: 'case', assignee: 'hanna')));
	}//end testOtherEventsAreIgnored()

	/**
	 * A failing refresh is logged with the case id and does not escape.
	 *
	 * @return void
	 */
	public function testAFailingRefreshIsLoggedNotThrown(): void {
		$this->readers->method('refreshCase')->willThrowException(new RuntimeException('share backend down'));
		$this->logger->expects($this->once())->method('error')->with(
			$this->stringContains('could not be refreshed'),
			$this->callback(
				static fn (array $context): bool => $context['app'] === 'dossiq'
					&& $context['caseId'] === 'case-1'
					&& $context['exception'] === 'share backend down'
			)
		);

		$this->listener()->handle($this->updated(schema: 'case', before: 'anna', after: 'hanna'));
	}//end testAFailingRefreshIsLoggedNotThrown()

	/**
	 * The listener over the shared collaborators.
	 *
	 * @return RestrictedDocumentHandlerListener
	 */
	private function listener(): RestrictedDocumentHandlerListener {
		return new RestrictedDocumentHandlerListener($this->settings, $this->readers, $this->logger);
	}//end listener()

	/**
	 * An update of case-1 moving the assignee.
	 *
	 * @param string      $schema The schema of the saved object.
	 * @param string|null $before The previous assignee (null: none).
	 * @param string      $after  The new assignee.
	 *
	 * @return ObjectUpdatedEvent
	 */
	private function updated(string $schema, ?string $before, string $after): ObjectUpdatedEvent {
		return new ObjectUpdatedEvent(
			$this->entity(schema: $schema, assignee: $after),
			$this->entity(schema: $schema, assignee: $before)
		);
	}//end updated()

	/**
	 * A case entity.
	 *
	 * @param string      $schema   The schema id.
	 * @param string|null $assignee The assignee (null: absent).
	 *
	 * @return ObjectEntity
	 */
	private function entity(string $schema, ?string $assignee): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid('case-1');
		$entity->setSchema($schema);
		$entity->setObject($assignee === null ? ['title' => 'Zaak'] : ['title' => 'Zaak', 'assignee' => $assignee]);

		return $entity;
	}//end entity()
}//end class
