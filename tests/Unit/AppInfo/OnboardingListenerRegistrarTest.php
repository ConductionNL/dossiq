<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\AppInfo;

use OCA\Dossiq\Listener\DisplaySettingsInitialStateListener;
use OCA\Dossiq\Listener\Registrar\OnboardingListenerRegistrar;
use OCA\Dossiq\Listener\RolePermissionsInitialStateListener;
use OCA\Dossiq\Listener\SupportNoteInitialStateListener;
use OCA\Dossiq\Listener\WalkthroughSeenInitialStateListener;
use OCP\AppFramework\Http\Events\BeforeTemplateRenderedEvent;
use PHPUnit\Framework\TestCase;

/**
 * The four initial states the page reads on load are each provided before
 * the template renders.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\AppInfo
 *
 * @covers \OCA\Dossiq\Listener\Registrar\OnboardingListenerRegistrar
 */
class OnboardingListenerRegistrarTest extends TestCase {
	use RecordsRegistrations;

	public function testEveryInitialStateIsProvidedBeforeTheTemplateRenders(): void {
		(new OnboardingListenerRegistrar())->register(context: $this->recordingContext());

		self::assertSame(
			[
				[BeforeTemplateRenderedEvent::class, WalkthroughSeenInitialStateListener::class, 0],
				[BeforeTemplateRenderedEvent::class, SupportNoteInitialStateListener::class, 0],
				[BeforeTemplateRenderedEvent::class, RolePermissionsInitialStateListener::class, 0],
				[BeforeTemplateRenderedEvent::class, DisplaySettingsInitialStateListener::class, 0],
			],
			$this->listeners
		);
	}//end testEveryInitialStateIsProvidedBeforeTheTemplateRenders()
}//end class
