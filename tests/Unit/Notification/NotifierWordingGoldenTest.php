<?php

/**
 * Notifier wording golden.
 *
 * Pins the subject and message every Dossiq notification subject renders,
 * for an empty parameter set and for several filled ones, so the wording
 * helpers can be regrouped without one word changing.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Notification
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Notification;

use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Notification\Notifier;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Notification\INotification;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Every subject's wording, exactly.
 *
 * @covers \OCA\Dossiq\Notification\Notifier
 * @uses \OCA\Dossiq\Service\QuotaLimitLabel
 */
class NotifierWordingGoldenTest extends TestCase {
	private const GOLDEN = __DIR__ . '/../golden/notifier-wording.json';

	/**
	 * The parameter sets every subject is rendered with.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function parameterSets(): array {
		return [
			'empty' => [],
			'filled' => [
				'actorDisplayName' => 'Anna de Vries',
				'transitionLabel' => ' Besluit ',
				'message' => ' Graag even kijken ',
				'milestone' => 'Advies binnen',
				'daysOverdue' => 3,
				'count' => 4,
				'fromUser' => 'bert',
				'daysRemaining' => 5,
				'quotaType' => 'ai',
				'limit' => 100,
				'used' => 80,
				'reason' => ' Verhuisd ',
				'act' => 'answer',
			],
			'one' => ['daysOverdue' => 1, 'count' => 1, 'daysRemaining' => 1, 'act' => 'withdraw', 'quotaType' => 'storage', 'limit' => 1, 'used' => 1],
			'negative' => ['daysOverdue' => -2, 'daysRemaining' => -1, 'act' => 'unknown', 'transitionLabel' => '  ', 'message' => '', 'reason' => ''],
		];
	}//end parameterSets()

	/**
	 * Render every subject with every parameter set.
	 *
	 * @return array<string, array<string, array{0: string, 1: string}>>
	 */
	private function renderAll(): array {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static fn (string $text, array $params = []): string => ($params === [] ? $text : vsprintf($text, $params))
		);
		$l10n->method('n')->willReturnCallback(
			static fn (string $one, string $many, int $count, array $params = []): string => vsprintf(($count === 1 ? $one : $many), $params)
		);
		$factory = $this->createMock(IFactory::class);
		$factory->method('get')->willReturn($l10n);
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('imagePath')->willReturn('/img/app-dark.svg');
		$urls->method('getAbsoluteURL')->willReturnCallback(static fn (string $path): string => 'https://cloud.example.com' . $path);
		$notifier = new Notifier($factory, $urls);

		$subjects = array_filter(
			(new ReflectionClass(Notifier::class))->getConstants(),
			static fn (string $name): bool => str_starts_with($name, 'SUBJECT_'),
			ARRAY_FILTER_USE_KEY
		);
		ksort($subjects);

		$rendered = [];
		foreach ($subjects as $subject) {
			foreach (self::parameterSets() as $set => $parameters) {
				$parsed = [];
				$notification = $this->createMock(INotification::class);
				$notification->method('getApp')->willReturn(Application::APP_ID);
				$notification->method('getSubject')->willReturn($subject);
				$notification->method('getSubjectParameters')->willReturn($parameters);
				$notification->method('setParsedSubject')->willReturnCallback(
					function (string $text) use (&$parsed, $notification) {
						$parsed[0] = $text;
						return $notification;
					}
				);
				$notification->method('setParsedMessage')->willReturnCallback(
					function (string $text) use (&$parsed, $notification) {
						$parsed[1] = $text;
						return $notification;
					}
				);
				$notification->method('setIcon')->willReturnSelf();
				$notifier->prepare($notification, 'nl');
				$rendered[$subject][$set] = [$parsed[0] ?? '', $parsed[1] ?? ''];
			}
		}

		return $rendered;
	}//end renderAll()

	/**
	 * Every subject renders exactly as before.
	 *
	 * @return void
	 */
	public function testEverySubjectRendersTheSameWording(): void {
		$this->assertSame(
			json_decode((string)file_get_contents(self::GOLDEN), true),
			$this->renderAll()
		);
	}//end testEverySubjectRendersTheSameWording()
}//end class
