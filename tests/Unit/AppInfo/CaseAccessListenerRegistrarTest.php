<?php

/**
 * The case reassign guard is bound to the update before and after the save.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\AppInfo
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/woo-request-scoped-access/specs/case-reviewer-access/spec.md#requirement-a-case-with-private-records-changes-hands-only-through-a-supervisor-req-cra-005
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\AppInfo;

use OCA\Dossiq\AppInfo\Registrar\CaseAccessListenerRegistrar;
use OCA\Dossiq\AppInfo\Registrar\ObjectListenerRegistrar;
use OCA\Dossiq\Listener\CaseDecisionAccessListener;
use OCA\Dossiq\Listener\CaseReassignGuardListener;
use OCA\Dossiq\Listener\CaseTypeRightsRowsListener;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\AppInfo\Registrar\CaseAccessListenerRegistrar
 */
class CaseAccessListenerRegistrarTest extends TestCase {

	public function testTheGuardHearsTheUpdateBeforeAndAfterTheSave(): void {
		$registered = [];
		$context = $this->createMock(originalClassName: IRegistrationContext::class);
		$context->method('registerEventListener')->willReturnCallback(
			static function (string $event, string $listener) use (&$registered): void {
				$registered[] = [$event, $listener];
			}
		);

		(new CaseAccessListenerRegistrar())->register(context: $context);

		self::assertSame(
			[
				['OCA\OpenRegister\Event\ObjectUpdatingEvent', CaseReassignGuardListener::class],
				['OCA\OpenRegister\Event\ObjectUpdatedEvent', CaseReassignGuardListener::class],
				['OCA\OpenRegister\Event\DecisionChangeRequestedEvent', CaseDecisionAccessListener::class],
				['OCA\OpenRegister\Event\DecisionVisibilityRequestedEvent', CaseDecisionAccessListener::class],
				['OCA\OpenRegister\Event\MatrixRowsRequestedEvent', CaseTypeRightsRowsListener::class],
			],
			$registered
		);
	}//end testTheGuardHearsTheUpdateBeforeAndAfterTheSave()

	public function testTheAppRegistrarCallsIt(): void {
		$source = (string)file_get_contents((new \ReflectionClass(ObjectListenerRegistrar::class))->getFileName());

		self::assertStringContainsString('(new CaseAccessListenerRegistrar())->register(context: $context);', $source);
	}//end testTheAppRegistrarCallsIt()
}//end class
