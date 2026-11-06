<?php

/**
 * The case activity listener hears every seam work on a case crosses.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\AppInfo
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
 * @spec openspec/specs/case-last-activity-at/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\AppInfo;

use OCA\Dossiq\AppInfo\Registrar\CaseActivityListenerRegistrar;
use OCA\Dossiq\Listener\CaseLastActivityListener;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the case activity listener registrar.
 *
 * @covers \OCA\Dossiq\AppInfo\Registrar\CaseActivityListenerRegistrar
 */
class CaseActivityListenerRegistrarTest extends TestCase {

	/**
	 * Object saves, task completion and both spellings of a new note.
	 *
	 * @return void
	 */
	public function testTheListenerHearsEveryKindOfWork(): void {
		$registered = [];
		$context = $this->createMock(originalClassName: IRegistrationContext::class);
		$context->method('registerEventListener')->willReturnCallback(
			static function (string $event, string $listener) use (&$registered): void {
				$registered[$event] = $listener;
			}
		);

		(new CaseActivityListenerRegistrar())->register(context: $context);

		self::assertSame(
			expected: [
				'OCA\OpenRegister\Event\ObjectCreatedEvent',
				'OCA\OpenRegister\Event\ObjectUpdatedEvent',
				'OCA\OpenRegister\Event\TaskTerminalEvent',
				'OCP\Comments\CommentsEvent',
				'OCP\Comments\Events\CommentAddedEvent',
			],
			actual: array_keys($registered),
		);
		self::assertSame(expected: [CaseLastActivityListener::class], actual: array_values(array_unique($registered)));
	}//end testTheListenerHearsEveryKindOfWork()
}//end class
