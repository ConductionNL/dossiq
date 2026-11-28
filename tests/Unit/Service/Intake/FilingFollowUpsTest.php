<?php

/**
 * The Link to case dialog's two choices (board DqKoppelAanZaak).
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\Intake
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/case-email-integration/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Intake;

use OCA\Dossiq\Service\Intake\FilingFollowUps;
use OCA\Dossiq\Service\SettingsService;
use OCP\IL10N;
use OCP\IUserManager;
use OCP\L10N\IFactory;
use OCP\Notification\IManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\TestCase;

/**
 * Tests for FilingFollowUps.
 *
 * @covers \OCA\Dossiq\Service\Intake\FilingFollowUps
 */
class FilingFollowUpsTest extends TestCase {

	/**
	 * The rows the fake store saved.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $saved = [];

	/**
	 * The service over a fake store with one case, its party role types and the roles given.
	 *
	 * @param array<int, array<string, mixed>> $roles    The roles already on the case.
	 * @param IManager|null                    $notifier The notification manager.
	 *
	 * @return FilingFollowUps
	 */
	private function followUps(array $roles = [], ?IManager $notifier = null): FilingFollowUps {
		$saved = &$this->saved;
		$store = new class($roles, $saved) {
			/**
			 * @param array $roles The roles on the case.
			 * @param array $saved The saved rows, by reference.
			 */
			public function __construct(private array $roles, private array &$saved) {
			}

			/**
			 * The rows a search asks for: role types by case type, roles by case.
			 *
			 * @param array $query The query.
			 *
			 * @return array
			 */
			public function searchObjects(array $query): array {
				if (isset($query['caseType']) === true) {
					return [['id' => 'rt-handler', 'genericRole' => 'handler'], ['id' => 'rt-stake', 'genericRole' => 'stakeholder']];
				}

				return $this->roles;
			}

			/**
			 * The case.
			 *
			 * @param string $id      The id.
			 * @param mixed  $options The options.
			 *
			 * @return array
			 */
			public function find(string $id, mixed $options = null): array {
				return ['id' => $id, 'caseType' => 'ct-1', 'assignee' => 'anouk'];
			}

			/**
			 * Keep a saved row.
			 *
			 * @param array $object  The row.
			 * @param mixed $options The options.
			 *
			 * @return array
			 */
			public function saveObject(array $object, mixed $options = null): array {
				$this->saved[] = $object;
				return $object;
			}
		};

		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getObjectService')->willReturn($store);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key): string => ['register' => '5', 'case_schema' => '7', 'role_schema' => '8', 'role_type_schema' => '9'][$key] ?? ''
		);
		$l = $this->createMock(originalClassName: IL10N::class);
		$l->method('t')->willReturnCallback(static fn (string $text, array $vars = []): string => vsprintf($text, $vars));
		$factory = $this->createMock(originalClassName: IFactory::class);
		$factory->method('get')->willReturn($l);

		return new FilingFollowUps(
			settingsService: $settings,
			notifications: ($notifier ?? $this->createMock(originalClassName: IManager::class)),
			l10nFactory: $factory,
			users: $this->createMock(originalClassName: IUserManager::class),
		);
	}//end followUps()

	/**
	 * A sender not on the case yet becomes a party in the case type's stakeholder role.
	 *
	 * @return void
	 */
	public function testASenderNotOnTheCaseBecomesAStakeholder(): void {
		$added = $this->followUps()->addSenderAsParty(caseId: 'case-1', address: 'Henk@Example.org', name: 'Henk Bakker');

		self::assertSame(expected: 'added', actual: $added);
		self::assertSame(
			expected: [['name' => 'Henk Bakker', 'roleType' => 'rt-stake', 'case' => 'case-1', 'participant' => 'mailto:henk@example.org']],
			actual: $this->saved
		);
	}//end testASenderNotOnTheCaseBecomesAStakeholder()

	/**
	 * A sender already on the case is not added twice.
	 *
	 * @return void
	 */
	public function testASenderAlreadyOnTheCaseIsLeft(): void {
		$followUps = $this->followUps(roles: [['participant' => 'mailto:henk@example.org']]);

		self::assertSame(expected: 'already', actual: $followUps->addSenderAsParty(caseId: 'case-1', address: 'henk@example.org', name: ''));
		self::assertSame(expected: [], actual: $this->saved);
	}//end testASenderAlreadyOnTheCaseIsLeft()

	/**
	 * The handler hears of the message; a handler who linked it hears nothing.
	 *
	 * @return void
	 */
	public function testTheHandlerIsToldUnlessTheyLinkedIt(): void {
		$notification = $this->createMock(originalClassName: INotification::class);
		foreach (['setApp', 'setUser', 'setDateTime', 'setObject', 'setSubject'] as $setter) {
			$notification->method($setter)->willReturnSelf();
		}

		$notification->expects($this->once())->method('setUser')->with('anouk');
		$notification->expects($this->once())->method('setSubject')->with(
			'case_role_notified',
			['message' => 'A message from Henk Bakker was linked to this case: Vraag 2026-0206']
		);
		$manager = $this->createMock(originalClassName: IManager::class);
		$manager->method('createNotification')->willReturn($notification);
		$manager->expects($this->once())->method('notify');

		$followUps = $this->followUps(notifier: $manager);

		$told = $followUps->notifyHandler(caseId: 'case-1', callerId: 'pieter', sender: 'Henk Bakker', subject: 'Vraag 2026-0206');

		self::assertSame(expected: 'notified', actual: $told);
		self::assertSame(expected: 'none', actual: $followUps->notifyHandler(caseId: 'case-1', callerId: 'anouk', sender: 'Henk Bakker', subject: 'x'));
	}//end testTheHandlerIsToldUnlessTheyLinkedIt()
}//end class
