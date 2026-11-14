<?php

/**
 * The checklist-run guard on events it does not own, a run without a stored version and a failing settings read.
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

use OCA\Dossiq\Kernel\Contracts\SettingsServiceInterface;
use OCA\Dossiq\Listener\ChecklistRunImmutabilityListener;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Edge paths of ChecklistRunImmutabilityListener.
 *
 * @covers \OCA\Dossiq\Listener\ChecklistRunImmutabilityListener
 * @uses   \OCA\Dossiq\Listener\Support\SchemaMembership
 * @uses   \OCA\Dossiq\Listener\Support\SerialisedObject
 */
class ChecklistRunImmutabilityListenerEdgesTest extends TestCase {

	/**
	 * A create is not a mutation of a run, and the settings are not even read.
	 *
	 * @return void
	 */
	public function testACreateIsNotChecked(): void {
		$settings = $this->createMock(SettingsServiceInterface::class);
		$settings->expects(self::never())->method('getConfigValue');

		$event = new ObjectCreatingEvent(self::checklistRun(status: 'submitted'));
		(new ChecklistRunImmutabilityListener(settingsService: $settings, logger: $this->createMock(LoggerInterface::class)))->handle($event);

		self::assertFalse($event->isPropagationStopped());
	}//end testACreateIsNotChecked()

	/**
	 * An update of a run with no stored version passes.
	 *
	 * @return void
	 */
	public function testARunWithoutAStoredVersionPasses(): void {
		$event = new ObjectUpdatingEvent(newObject: self::checklistRun(status: 'submitted', responses: ['q1' => 'changed']), oldObject: null);
		$this->listener()->handle($event);

		self::assertFalse($event->isPropagationStopped());
		self::assertSame([], $event->getErrors());
	}//end testARunWithoutAStoredVersionPasses()

	/**
	 * A settings read that fails is logged and lets the write through.
	 *
	 * @return void
	 */
	public function testAFailingSettingsReadIsLoggedAndPasses(): void {
		$settings = $this->createMock(SettingsServiceInterface::class);
		$settings->method('getConfigValue')->willThrowException(new RuntimeException('config down'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())->method('debug')->with('Dossiq: checklist immutability listener swallowed exception: config down');

		$event = new ObjectUpdatingEvent(newObject: self::checklistRun(status: 'submitted', responses: ['q1' => 'changed']), oldObject: self::checklistRun(status: 'submitted'));
		(new ChecklistRunImmutabilityListener(settingsService: $settings, logger: $logger))->handle($event);

		self::assertFalse($event->isPropagationStopped());
	}//end testAFailingSettingsReadIsLoggedAndPasses()

	/**
	 * The control: a material change to a submitted run is refused.
	 *
	 * @return void
	 */
	public function testAMaterialChangeToASubmittedRunIsRefused(): void {
		$event = new ObjectUpdatingEvent(newObject: self::checklistRun(status: 'submitted', responses: ['q1' => 'changed']), oldObject: self::checklistRun(status: 'submitted'));
		$this->listener()->handle($event);

		self::assertTrue($event->isPropagationStopped());
		self::assertSame(['message' => 'Checklist run is append-only', 'code' => 'inspectionChecklistRun.appendOnly'], $event->getErrors());
	}//end testAMaterialChangeToASubmittedRunIsRefused()

	/**
	 * The listener with the run schema configured.
	 *
	 * @return ChecklistRunImmutabilityListener The listener.
	 */
	private function listener(): ChecklistRunImmutabilityListener {
		$settings = $this->createMock(SettingsServiceInterface::class);
		$settings->method('getConfigValue')->willReturn('checklistRun');

		return new ChecklistRunImmutabilityListener(settingsService: $settings, logger: $this->createMock(LoggerInterface::class));
	}//end listener()

	/**
	 * A checklist run.
	 *
	 * @param string               $status    Its status.
	 * @param array<string, mixed> $responses Its responses.
	 *
	 * @return ObjectEntity The run.
	 */
	private static function checklistRun(string $status, array $responses = ['q1' => 'ok']): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid('run-1');
		$entity->setSchema('checklistRun');
		$entity->setObject(['status' => $status, 'responses' => $responses]);

		return $entity;
	}//end checklistRun()
}//end class
