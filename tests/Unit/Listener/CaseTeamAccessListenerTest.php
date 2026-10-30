<?php

/**
 * A saved case type re-projects the handling team rules, and nothing else does.
 *
 * Wired from the caller: the registrar is asked which listeners it binds, and
 * the bound listener is handed the event OpenRegister dispatches after a save.
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
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/case-access-control/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Listener;

use OCA\Dossiq\AppInfo\Registrar\CaseTypeListenerRegistrar;
use OCA\Dossiq\Listener\CaseTeamAccessListener;
use OCA\Dossiq\Service\Access\CaseTeamAccessProjector;
use OCA\Dossiq\Service\SettingsService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Listener\CaseTeamAccessListener
 * @covers \OCA\Dossiq\AppInfo\Registrar\CaseTypeListenerRegistrar
 *
 * @spec openspec/specs/case-access-control/spec.md
 */
class CaseTeamAccessListenerTest extends TestCase {

	/**
	 * The case type schema id the settings name.
	 */
	private const CASE_TYPE_SCHEMA = '28';

	/**
	 * A listener whose projector expects the given number of runs.
	 *
	 * @param int $runs How often the projector must run.
	 *
	 * @return CaseTeamAccessListener The listener.
	 */
	private function listener(int $runs): CaseTeamAccessListener {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key): string => ($key === 'case_type_schema') ? self::CASE_TYPE_SCHEMA : ''
		);

		$projector = $this->createMock(CaseTeamAccessProjector::class);
		$projector->expects($this->exactly($runs))->method('reapply')->willReturn(true);

		return new CaseTeamAccessListener(settingsService: $settings, projector: $projector);
	}//end listener()

	/**
	 * An object of the given schema.
	 *
	 * @param string $schema The schema id.
	 *
	 * @return ObjectEntity The entity.
	 */
	private function entity(string $schema): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid('row-1');
		$entity->setSchema($schema);
		$entity->setObject(['handling' => ['teams' => ['vergunningen']]]);

		return $entity;
	}//end entity()

	/**
	 * The registrar binds the listener to both after-save events.
	 *
	 * A listener nothing registers is a projection that only ever runs on an
	 * import.
	 *
	 * @return void
	 */
	public function testTheRegistrarBindsItAfterCreateAndUpdate(): void {
		$bound = [];
		$context = $this->createMock(IRegistrationContext::class);
		$context->method('registerEventListener')->willReturnCallback(
			static function (string $event, string $listener) use (&$bound): void {
				$bound[] = [$event, $listener];
			}
		);

		(new CaseTypeListenerRegistrar())->register($context);

		$this->assertContains([ObjectCreatedEvent::class, CaseTeamAccessListener::class], $bound);
		$this->assertContains([ObjectUpdatedEvent::class, CaseTeamAccessListener::class], $bound);
	}//end testTheRegistrarBindsItAfterCreateAndUpdate()

	/**
	 * Saving a case type re-projects the rules.
	 *
	 * @return void
	 */
	public function testACaseTypeSaveReprojects(): void {
		$this->listener(runs: 1)->handle(new ObjectUpdatedEvent($this->entity(schema: self::CASE_TYPE_SCHEMA)));
		$this->listener(runs: 1)->handle(new ObjectCreatedEvent($this->entity(schema: self::CASE_TYPE_SCHEMA)));
	}//end testACaseTypeSaveReprojects()

	/**
	 * Saving anything else does not.
	 *
	 * Every object save in the instance passes through this listener; reading
	 * every case type on each of them would be a cost with no reason.
	 *
	 * @return void
	 */
	public function testAnotherObjectSaveDoesNot(): void {
		$this->listener(runs: 0)->handle(new ObjectUpdatedEvent($this->entity(schema: '35')));
	}//end testAnotherObjectSaveDoesNot()
}//end class
