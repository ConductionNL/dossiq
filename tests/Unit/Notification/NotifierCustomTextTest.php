<?php

/**
 * Dossiq Notifier custom text tests
 *
 * An administrator's own text for a kind of notification replaces the
 * shipped message on the next notification of that kind (REQ-CRS-13).
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Notification
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
 * @spec openspec/changes/capability-rows-say-the-whole-capability/specs/admin-settings/spec.md#requirement-an-administrator-edits-the-text-of-each-notification-req-crs-13
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Notification;

use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Notification\NotificationText;
use OCA\Dossiq\Notification\Notifier;
use OCA\Dossiq\Service\QuotaLimitLabel;
use OCA\Dossiq\Service\Settings\ConfigKeys;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Notification\INotification;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * The administrator's notification texts.
 */
#[CoversClass(Notifier::class)]
#[UsesClass(NotificationText::class)]
#[UsesClass(QuotaLimitLabel::class)]
final class NotifierCustomTextTest extends TestCase {

	/**
	 * Stored appconfig values, keyed by key.
	 *
	 * @var array<string, string>
	 */
	private array $stored = [];

	/**
	 * Build a notifier over the stored texts.
	 *
	 * @param bool $withConfig Whether the notifier gets an app config at all.
	 *
	 * @return Notifier
	 */
	private function notifier(bool $withConfig = true): Notifier {
		$l10n = $this->createMock(originalClassName: IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static function (string $text, array $params = []): string {
				if ($params === []) {
					return $text;
				}

				return vsprintf(format: $text, values: $params);
			}
		);
		$factory = $this->createMock(originalClassName: IFactory::class);
		$factory->method('get')->willReturn($l10n);
		$urls = $this->createMock(originalClassName: IURLGenerator::class);
		$urls->method('imagePath')->willReturn('/img/app-dark.svg');
		$urls->method('getAbsoluteURL')->willReturnCallback(static fn (string $path): string => 'https://cloud.example.com'.$path);

		if ($withConfig === false) {
			return new Notifier(l10nFactory: $factory, urlGenerator: $urls);
		}

		$config = $this->createMock(originalClassName: IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			function (string $app, string $key, string $default = ''): string {
				$this->assertSame(expected: Application::APP_ID, actual: $app);
				return ($this->stored[$key] ?? $default);
			}
		);

		return new Notifier(l10nFactory: $factory, urlGenerator: $urls, quotaLabels: new QuotaLimitLabel(), appConfig: $config);
	}//end notifier()

	/**
	 * Prepare one notification and return what it was given as message.
	 *
	 * @param Notifier $notifier The notifier.
	 * @param string $subject The subject key.
	 * @param array<string, mixed> $parameters The subject parameters.
	 *
	 * @return array{0: string, 1: string} The parsed subject and message.
	 */
	private function prepare(Notifier $notifier, string $subject, array $parameters = []): array {
		$parsed       = ['', ''];
		$notification = $this->createMock(originalClassName: INotification::class);
		$notification->method('getApp')->willReturn(Application::APP_ID);
		$notification->method('getSubject')->willReturn($subject);
		$notification->method('getSubjectParameters')->willReturn($parameters);
		$notification->method('setParsedSubject')->willReturnCallback(
			static function (string $value) use (&$parsed, $notification): INotification {
				$parsed[0] = $value;
				return $notification;
			}
		);
		$notification->method('setParsedMessage')->willReturnCallback(
			static function (string $value) use (&$parsed, $notification): INotification {
				$parsed[1] = $value;
				return $notification;
			}
		);
		$notification->method('setIcon')->willReturn($notification);

		$notifier->prepare(notification: $notification, languageCode: 'en');

		return $parsed;
	}//end prepare()

	/**
	 * A saved text is the message of the next notification of that kind.
	 *
	 * @return void
	 */
	public function testASavedTextReplacesTheMessage(): void {
		$this->stored['notification_text_case_role_notified'] = 'Pick this case up today, please.';

		[$subject, $message] = $this->prepare(notifier: $this->notifier(), subject: 'case_role_notified', parameters: ['message' => 'Shipped body']);

		$this->assertSame(expected: 'A case you handle needs your attention', actual: $subject);
		$this->assertSame(expected: 'Pick this case up today, please.', actual: $message);
	}//end testASavedTextReplacesTheMessage()

	/**
	 * Without a saved text the shipped wording stays.
	 *
	 * @return void
	 */
	public function testAnEmptyTextKeepsTheShippedWording(): void {
		$this->stored['notification_text_case_role_notified'] = '   ';

		[, $message] = $this->prepare(notifier: $this->notifier(), subject: 'case_role_notified');

		$this->assertSame(expected: 'Open the case to see what to do next.', actual: $message);
	}//end testAnEmptyTextKeepsTheShippedWording()

	/**
	 * A text saved for one kind leaves another kind alone.
	 *
	 * @return void
	 */
	public function testATextIsPerKind(): void {
		$this->stored['notification_text_term_exceeded'] = 'Too late.';

		[, $message] = $this->prepare(notifier: $this->notifier(), subject: 'case_role_notified');

		$this->assertSame(expected: 'Open the case to see what to do next.', actual: $message);
	}//end testATextIsPerKind()

	/**
	 * A placeholder is filled from the notification's scalar parameter of
	 * that name; one the kind does not carry stays as written.
	 *
	 * @return void
	 */
	public function testPlaceholdersAreFilledFromTheParameters(): void {
		$this->stored['notification_text_cases_reassigned'] = '{fromUser} gave you {count} cases. {missing} {list}';

		[, $message] = $this->prepare(
			notifier: $this->notifier(),
			subject: 'cases_reassigned',
			parameters: ['fromUser' => 'jan', 'count' => 3, 'list' => ['a', 'b']]
		);

		$this->assertSame(expected: 'jan gave you 3 cases. {missing} {list}', actual: $message);
	}//end testPlaceholdersAreFilledFromTheParameters()

	/**
	 * The Dutch-named advice request reads the advice request's text.
	 *
	 * @return void
	 */
	public function testTheDutchAdviceSubjectSharesTheAdviceText(): void {
		$this->stored['notification_text_advice_requested'] = 'Your advice is asked.';

		[, $message] = $this->prepare(notifier: $this->notifier(), subject: 'advies_aangevraagd');

		$this->assertSame(expected: 'Your advice is asked.', actual: $message);
	}//end testTheDutchAdviceSubjectSharesTheAdviceText()

	/**
	 * A notifier built without an app config words as shipped.
	 *
	 * @return void
	 */
	public function testWithoutAppConfigTheShippedWordingStays(): void {
		[, $message] = $this->prepare(notifier: $this->notifier(withConfig: false), subject: 'case_role_notified');

		$this->assertSame(expected: 'Open the case to see what to do next.', actual: $message);
	}//end testWithoutAppConfigTheShippedWordingStays()

	/**
	 * Every editable kind's key is one the settings service reads and writes,
	 * and names a subject the notifier knows.
	 *
	 * @return void
	 */
	public function testEveryTextKeyIsASettingForAKnownSubject(): void {
		$subjects = (new \ReflectionClassConstant(class: Notifier::class, constant: 'KNOWN_SUBJECTS'))->getValue();
		foreach (ConfigKeys::NOTIFICATION_TEXT_KEYS as $key) {
			$this->assertContains(needle: $key, haystack: ConfigKeys::ALL, message: $key);
			$this->assertStringStartsWith(prefix: Notifier::TEXT_KEY_PREFIX, string: $key);
			$subject = substr(string: $key, offset: strlen(string: Notifier::TEXT_KEY_PREFIX));
			$this->assertContains(needle: $subject, haystack: $subjects, message: $key);
		}
		$this->assertCount(expectedCount: count(value: $subjects) - 1, haystack: ConfigKeys::NOTIFICATION_TEXT_KEYS, message: 'every subject but the Dutch advice alias');
	}//end testEveryTextKeyIsASettingForAKnownSubject()
}//end class
