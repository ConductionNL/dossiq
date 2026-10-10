<?php

/**
 * A cited Woo refusal ground cannot be deleted.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
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
 * @spec openspec/specs/woo-refusal-grounds/spec.md#requirement-one-hierarchical-list-of-grounds-in-dossiqs-register-req-wrg-002
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Listener;

use OCA\Dossiq\Listener\WooRefusalGroundDeleteGuard;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Tests\Support\InMemoryRegister;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectDeletingEvent;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * REQ-WRG-002 through OpenRegister's delete event.
 *
 * @covers \OCA\Dossiq\Listener\WooRefusalGroundDeleteGuard
 *
 * @uses \OCA\Dossiq\Service\Support\SearchesObjects
 */
class WooRefusalGroundDeleteGuardTest extends TestCase {

	/**
	 * The store the guard reads citations from.
	 *
	 * @var InMemoryRegister
	 */
	private InMemoryRegister $store;

	/**
	 * Whether the object service is reachable.
	 *
	 * @var bool
	 */
	private bool $reachable = true;

	/**
	 * Reset the store.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->store = new InMemoryRegister();
		$this->reachable = true;
	}//end setUp()

	/**
	 * The delete event for a ground.
	 *
	 * @param string $code   The ground's code.
	 * @param string $schema The schema the object carries.
	 *
	 * @return ObjectDeletingEvent The event.
	 */
	private function deleteOf(string $code, string $schema = 'wooRefusalGround'): ObjectDeletingEvent {
		$entity = new ObjectEntity();
		$entity->setObject(['code' => $code, 'label' => 'Eerbiediging van de persoonlijke levenssfeer', 'status' => 'active']);
		$entity->setSchema($schema);
		$entity->setUuid('99999999-0000-4000-8000-000000000001');

		return new ObjectDeletingEvent($entity);
	}//end deleteOf()

	/**
	 * The guard over the store.
	 *
	 * @return WooRefusalGroundDeleteGuard The guard.
	 */
	private function guard(): WooRefusalGroundDeleteGuard {
		$config = [
			'register' => 'dossiq',
			'woo_assessment_schema' => 'wooDocumentAssessment',
			'decision_schema' => 'decision',
			'woo_refusal_ground_schema' => 'wooRefusalGround',
		];
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => ($config[$key] ?? $default)
		);
		$settings->method('getObjectService')->willReturnCallback(fn (): ?InMemoryRegister => ($this->reachable === true ? $this->store : null));

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, $parameters = []): string => vsprintf($text, (array)$parameters));

		return new WooRefusalGroundDeleteGuard(settingsService: $settings, l10n: $l10n, logger: new NullLogger());
	}//end guard()

	/**
	 * A ground an assessment cites is not deleted, and the refusal says to retire it.
	 *
	 * @return void
	 */
	public function testACitedGroundCannotBeDeleted(): void {
		$this->store->seed(schema: 'wooDocumentAssessment', uuid: 'a-1', row: ['classification' => 'deels_openbaar', 'weigeringsgronden' => ['5.1.1.d', '5.1.2.e']]);
		$event = $this->deleteOf(code: '5.1.2.e');

		$this->guard()->handle($event);

		$this->assertTrue($event->isPropagationStopped());
		$this->assertSame('woo-refusal-ground-cited', $event->getErrors()['error']);
		$this->assertStringContainsString('Retire it instead', $event->getErrors()['message']);
	}//end testACitedGroundCannotBeDeleted()

	/**
	 * A ground a decision cites is held too.
	 *
	 * @return void
	 */
	public function testAGroundADecisionCitesIsHeld(): void {
		$this->store->seed(schema: 'decision', uuid: 'd-1', row: ['weigeringsgronden' => ['5.1.2.e']]);
		$event = $this->deleteOf(code: '5.1.2.e');

		$this->guard()->handle($event);

		$this->assertTrue($event->isPropagationStopped());
	}//end testAGroundADecisionCitesIsHeld()

	/**
	 * A ground nothing cites may go, and another schema's delete is not looked at.
	 *
	 * @return void
	 */
	public function testAnUncitedGroundAndOtherSchemasPass(): void {
		$this->store->seed(schema: 'wooDocumentAssessment', uuid: 'a-1', row: ['weigeringsgronden' => ['5.1.1.d']]);

		$uncited = $this->deleteOf(code: '5.1.2.e');
		$this->guard()->handle($uncited);
		$this->assertFalse($uncited->isPropagationStopped());

		$this->store->seed(schema: 'wooDocumentAssessment', uuid: 'a-2', row: ['weigeringsgronden' => ['5.1.2.e']]);
		$otherSchema = $this->deleteOf(code: '5.1.2.e', schema: 'case');
		$this->guard()->handle($otherSchema);
		$this->assertFalse($otherSchema->isPropagationStopped());
	}//end testAnUncitedGroundAndOtherSchemasPass()

	/**
	 * When the citations cannot be read, the delete is refused.
	 *
	 * @return void
	 */
	public function testAnUncheckableDeleteIsRefused(): void {
		$this->reachable = false;
		$event = $this->deleteOf(code: '5.1.2.e');

		$this->guard()->handle($event);

		$this->assertTrue($event->isPropagationStopped());
		$this->assertStringContainsString('could not be checked', $event->getErrors()['message']);
	}//end testAnUncheckableDeleteIsRefused()
}//end class
