<?php

/**
 * The support note waits for the welcome tour (first-load-onboarding).
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\Onboarding
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/first-load-onboarding/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Onboarding;

use OCA\Dossiq\Service\Onboarding\WalkthroughCompletion;
use OCP\IConfig;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Tests for WalkthroughCompletion.
 */
final class WalkthroughCompletionTest extends TestCase {

	/**
	 * A completion with this user and this stored preference value.
	 *
	 * @param string|null $stored The stored value, or null for no user.
	 *
	 * @return WalkthroughCompletion The reader.
	 */
	private function completion(?string $stored): WalkthroughCompletion {
		$session = $this->createMock(originalClassName: IUserSession::class);
		$config  = $this->createMock(originalClassName: IConfig::class);
		if ($stored === null) {
			$session->method('getUser')->willReturn(null);
			return new WalkthroughCompletion(config: $config, userSession: $session);
		}

		$user = $this->createMock(originalClassName: IUser::class);
		$user->method('getUID')->willReturn('nieuw');
		$session->method('getUser')->willReturn($user);
		$config->method('getUserValue')
			->with('nieuw', 'dossiq', 'pref_walkthrough_completed_version', '')
			->willReturn($stored);
		return new WalkthroughCompletion(config: $config, userSession: $session);
	}//end completion()

	/**
	 * A new user has not seen the tour, so the support note waits.
	 *
	 * @return void
	 */
	public function testANewUserHasNotSeenTheTour(): void {
		$this->assertFalse(condition: $this->completion(stored: '')->seen());
	}//end testANewUserHasNotSeenTheTour()

	/**
	 * A user who finished or skipped the tour has a stored version.
	 *
	 * @return void
	 */
	public function testAFinishedTourCounts(): void {
		$this->assertTrue(condition: $this->completion(stored: '0.4.52')->seen());
	}//end testAFinishedTourCounts()

	/**
	 * With nobody signed in nothing is held back.
	 *
	 * @return void
	 */
	public function testNoUserHoldsNothingBack(): void {
		$this->assertTrue(condition: $this->completion(stored: null)->seen());
	}//end testNoUserHoldsNothingBack()

	/**
	 * The key read here is the one the manifest tells nextcloud-vue to write.
	 *
	 * @return void
	 */
	public function testTheKeyIsTheManifestsCompletionKey(): void {
		$manifest = json_decode((string)file_get_contents(__DIR__.'/../../../../src/manifest.json'), true);
		$this->assertSame(
			expected: $manifest['walkthrough']['completionConfigKey'],
			actual: WalkthroughCompletion::PREFERENCE_KEY
		);
	}//end testTheKeyIsTheManifestsCompletionKey()
}//end class
