<?php

/**
 * DisplaySettingsInitialStateListener tests: the page gets the date format
 * and the beta switches, read on every load.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Listener;

use OCA\Dossiq\Listener\DisplaySettingsInitialStateListener;
use OCA\Dossiq\Service\SettingsService;
use OCP\AppFramework\Http\Events\BeforeTemplateRenderedEvent;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use PHPUnit\Framework\TestCase;

/**
 * Tests for DisplaySettingsInitialStateListener.
 *
 * @spec openspec/changes/admin-tabs-carry-the-settings-board-blocks/specs/tenant-configuration/spec.md
 */
class DisplaySettingsInitialStateListenerTest extends TestCase {

	/**
	 * Dossiq's own page gets the stored format and the switch.
	 *
	 * @return void
	 */
	public function testTheStoredSettingsReachThePage(): void {
		$this->assertSame(
			expected: [['dateFormat', 'YYYY-MM-DD'], ['features', ['newCasePage' => true]]],
			actual: $this->provided(stored: ['date_format' => 'YYYY-MM-DD', 'feature_new_case_page' => 'true'], app: 'dossiq')
		);
	}//end testTheStoredSettingsReachThePage()

	/**
	 * A format nobody can read falls back to the default; the switch is off unless it says true.
	 *
	 * @return void
	 */
	public function testAnUnknownFormatReadsAsTheDefault(): void {
		$this->assertSame(
			expected: [['dateFormat', 'DD-MM-YYYY'], ['features', ['newCasePage' => false]]],
			actual: $this->provided(stored: ['date_format' => 'MM/DD/YY'], app: 'dossiq')
		);
	}//end testAnUnknownFormatReadsAsTheDefault()

	/**
	 * Another app's page gets nothing.
	 *
	 * @return void
	 */
	public function testAnotherAppsPageGetsNothing(): void {
		$this->assertSame(expected: [], actual: $this->provided(stored: [], app: 'files'));
	}//end testAnotherAppsPageGetsNothing()

	/**
	 * What the listener provides for a page of one app over stored settings.
	 *
	 * @param array<string, string> $stored The stored settings.
	 * @param string                $app    The page's app.
	 *
	 * @return list<array{0: string, 1: mixed}>
	 */
	private function provided(array $stored, string $app): array {
		$provided = [];
		$state = $this->createMock(originalClassName: IInitialState::class);
		$state->method('provideInitialState')->willReturnCallback(
			static function (string $key, mixed $value) use (&$provided): void {
				$provided[] = [$key, $value];
			}
		);
		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getConfigValue')->willReturnCallback(static fn (string $key, string $default = ''): string => ($stored[$key] ?? $default));

		$response = $this->createMock(originalClassName: TemplateResponse::class);
		$response->method('getApp')->willReturn($app);
		$event = $this->createMock(originalClassName: BeforeTemplateRenderedEvent::class);
		$event->method('isLoggedIn')->willReturn(true);
		$event->method('getResponse')->willReturn($response);

		(new DisplaySettingsInitialStateListener(initialState: $state, settings: $settings))->handle(event: $event);

		return $provided;
	}//end provided()
}//end class
