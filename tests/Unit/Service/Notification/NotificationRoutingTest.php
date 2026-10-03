<?php

/**
 * What dossiq reads back when the platform routes a notification.
 *
 * THE DOUBLES ARE ANONYMOUS CLASSES, NOT MOCKS, ON PURPOSE. OpenRegister's
 * preference service is not a class this app can import, so a mock of it would
 * have to invent the methods it is asked for, and a double that invents a
 * method can only ever pass. An anonymous class declares exactly the surface
 * dossiq calls, so a rename on the OpenRegister side shows up here as the seam
 * answering null rather than as a green test.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Notification
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/unread-state-on-the-case/specs/case-management/spec.md#requirement-a-notification-preference-says-which-layer-decided-it-req-urs-05
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Notification;

use OCA\Dossiq\Service\Notification\NotificationRouting;
use OCA\Dossiq\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * The seam onto OpenRegister's layered preference read.
 *
 * @spec openspec/changes/unread-state-on-the-case/specs/case-management/spec.md#requirement-a-notification-preference-says-which-layer-decided-it-req-urs-05
 */
class NotificationRoutingTest extends TestCase {

	/**
	 * A routing seam wired to the given platform service.
	 *
	 * @param object|null          $service The platform service, or null when absent.
	 * @param LoggerInterface|null $logger  The logger.
	 *
	 * @return NotificationRouting The seam.
	 */
	private function routing(?object $service, ?LoggerInterface $logger = null): NotificationRouting {
		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getOpenRegisterClass')->willReturn($service);

		return new NotificationRouting(
			settingsService: $settings,
			logger: ($logger ?? new NullLogger())
		);
	}//end routing()

	/**
	 * A platform service that answers the given entries.
	 *
	 * @param array<int, array<string, mixed>> $entries The effective entries.
	 *
	 * @return object The double.
	 */
	private function platform(array $entries): object {
		return new class($entries) {
			/**
			 * Every write this double received.
			 *
			 * @var array<int, array<string, mixed>>
			 */
			public array $writes = [];

			/**
			 * The scopes the last read was asked for.
			 *
			 * @var array<int, string>
			 */
			public array $askedScopes = [];

			/**
			 * Constructor.
			 *
			 * @param array<int, array<string, mixed>> $entries The entries to answer.
			 */
			public function __construct(private array $entries) {
			}

			/**
			 * The effective entries for one person.
			 *
			 * @param string            $userId The person.
			 * @param array<int,string> $scopes The scopes asked for.
			 *
			 * @return array<int, array<string, mixed>> The entries.
			 */
			public function getEffectiveForUser(string $userId, array $scopes = []): array {
				$this->askedScopes = $scopes;

				return $this->entries;
			}

			/**
			 * Record one override.
			 *
			 * @param string            $userId          The person.
			 * @param string            $schemaSlug      The schema.
			 * @param string            $notificationKey The rule.
			 * @param array|null        $override        The value.
			 * @param string|null       $scope           The scope.
			 *
			 * @return void
			 */
			public function setOverride(
				string $userId,
				string $schemaSlug,
				string $notificationKey,
				?array $override,
				?string $scope = null
			): void {
				$this->writes[] = [
					'userId' => $userId,
					'schema' => $schemaSlug,
					'notification' => $notificationKey,
					'override' => $override,
					'scope' => $scope,
				];
			}
		};
	}//end platform()

	/**
	 * One digest entry as the platform answers it.
	 *
	 * @param bool   $enabled Whether it is on.
	 * @param string $source  The deciding layer.
	 * @param string $scope   How narrowly it was pinned.
	 *
	 * @return array<string, mixed> The entry.
	 */
	private function digestEntry(bool $enabled, string $source, string $scope = 'global'): array {
		return [
			'schema' => NotificationRouting::DIGEST_SCHEMA,
			'notification' => NotificationRouting::DIGEST_NOTIFICATION,
			'enabled' => $enabled,
			'channels' => ['nc-notification'],
			'source' => $source,
			'scope' => $scope,
			'layers' => [],
		];
	}//end digestEntry()

	/**
	 * An instance without the routing answers null, never a value.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/unread-state-on-the-case/specs/case-management/spec.md#requirement-a-notification-preference-says-which-layer-decided-it-req-urs-05
	 */
	public function testNothingRoutesWhenThePlatformIsAbsent(): void {
		$routing = $this->routing(service: null);

		$this->assertFalse(condition: $routing->isAvailable());
		$this->assertNull(actual: $routing->effectiveFor(userId: 'alice'));
		$this->assertNull(
			actual: $routing->digestEnabledFor(userId: 'alice'),
			message: 'Absent routing must answer null, never false: false would switch every digest off.'
		);
	}//end testNothingRoutesWhenThePlatformIsAbsent()

	/**
	 * An OpenRegister that predates the layered read is treated as absent.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/unread-state-on-the-case/specs/case-management/spec.md#requirement-a-notification-preference-says-which-layer-decided-it-req-urs-05
	 */
	public function testAServiceWithoutTheLayeredReadIsTreatedAsAbsent(): void {
		$old = new class {
			/**
			 * The pre-routing surface, which carries no deciding layer.
			 *
			 * @return array<int, mixed> Nothing.
			 */
			public function getPreferences(): array {
				return [];
			}
		};

		$this->assertFalse(
			condition: $this->routing(service: $old)->isAvailable(),
			message: 'Half an answer must not read as a whole one.'
		);
	}//end testAServiceWithoutTheLayeredReadIsTreatedAsAbsent()

	/**
	 * The deciding layer comes back with the value.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/unread-state-on-the-case/specs/case-management/spec.md#requirement-a-notification-preference-says-which-layer-decided-it-req-urs-05
	 */
	public function testTheDecidingLayerComesBackWithTheValue(): void {
		$routing = $this->routing(
			service: $this->platform(entries: [$this->digestEntry(enabled: false, source: 'group-default')])
		);

		$this->assertFalse(
			condition: $routing->digestEnabledFor(userId: 'alice'),
			message: 'A group default that switched the digest off must read as off.'
		);
		$this->assertSame(
			expected: ['source' => 'group-default', 'scope' => 'global'],
			actual: $routing->digestDecidedBy(userId: 'alice'),
			message: 'The screen has to be able to say which layer decided.'
		);
	}//end testTheDecidingLayerComesBackWithTheValue()

	/**
	 * A person's own value is reported as theirs.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/unread-state-on-the-case/specs/case-management/spec.md#requirement-a-notification-preference-says-which-layer-decided-it-req-urs-05
	 */
	public function testAPersonsOwnValueIsNamedAsTheirs(): void {
		$routing = $this->routing(
			service: $this->platform(
				entries: [$this->digestEntry(enabled: true, source: 'user-override', scope: 'domain:werkvoorraad')]
			)
		);

		$this->assertTrue(condition: $routing->digestEnabledFor(userId: 'alice'));
		$this->assertSame(
			expected: ['source' => 'user-override', 'scope' => 'domain:werkvoorraad'],
			actual: $routing->digestDecidedBy(userId: 'alice')
		);
	}//end testAPersonsOwnValueIsNamedAsTheirs()

	/**
	 * A scope asked for is the scope passed to the platform.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/unread-state-on-the-case/specs/case-management/spec.md#requirement-a-notification-preference-says-which-layer-decided-it-req-urs-05
	 */
	public function testAScopeAskedForReachesThePlatform(): void {
		$platform = $this->platform(entries: [$this->digestEntry(enabled: true, source: 'schema-default')]);
		$this->routing(service: $platform)->effectiveFor(userId: 'alice', scope: 'domain:zaken');

		$this->assertSame(
			expected: ['domain:zaken'],
			actual: $platform->askedScopes,
			message: 'A screen showing one domain must be answered for that domain.'
		);
	}//end testAScopeAskedForReachesThePlatform()

	/**
	 * An entry for another rule is not mistaken for the digest.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/unread-state-on-the-case/specs/case-management/spec.md#requirement-the-daily-digest-switch-is-a-notification-preference-req-urs-06
	 */
	public function testAnotherRulesEntryIsNotTheDigest(): void {
		$otherSchema = $this->digestEntry(enabled: false, source: 'group-default');
		$otherSchema['notification'] = 'caseAssigned';
		$otherSchema['schema'] = 'case';

		$this->assertNull(
			actual: $this->routing(service: $this->platform(entries: [$otherSchema]))->digestEnabledFor(userId: 'alice'),
			message: 'Reading somebody else\'s switch as the digest is how a screen lies about a value.'
		);

		// The harder half: a SIBLING rule on the digest's own schema. Matching
		// the schema alone would answer this one, and it is the first in the
		// list, so the answer would be confidently wrong rather than absent.
		$sibling = $this->digestEntry(enabled: false, source: 'group-default');
		$sibling['notification'] = 'workDigestFailed';

		$this->assertTrue(
			condition: $this->routing(
				service: $this->platform(entries: [$sibling, $this->digestEntry(enabled: true, source: 'user-override')])
			)->digestEnabledFor(userId: 'alice'),
			message: 'A sibling rule on the same schema must not be read as the digest switch.'
		);
	}//end testAnotherRulesEntryIsNotTheDigest()

	/**
	 * The digest switch is written as the person's own override.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/unread-state-on-the-case/specs/case-management/spec.md#requirement-the-daily-digest-switch-is-a-notification-preference-req-urs-06
	 */
	public function testTheSwitchIsWrittenAsTheirOwnOverride(): void {
		$platform = $this->platform(entries: []);

		$this->assertTrue(
			condition: $this->routing(service: $platform)->setDigestEnabled(userId: 'alice', enabled: false)
		);
		$this->assertSame(
			expected: [
				[
					'userId' => 'alice',
					'schema' => NotificationRouting::DIGEST_SCHEMA,
					'notification' => NotificationRouting::DIGEST_NOTIFICATION,
					'override' => ['enabled' => false],
					'scope' => null,
				],
			],
			actual: $platform->writes,
			message: 'The switch is the person\'s own value, so the team default stays visible underneath it.'
		);
	}//end testTheSwitchIsWrittenAsTheirOwnOverride()

	/**
	 * A read that throws propagates rather than reading as unrouted.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/unread-state-on-the-case/specs/case-management/spec.md#requirement-the-daily-digest-switch-is-a-notification-preference-req-urs-06
	 */
	public function testAThrowingReadPropagatesRatherThanReadingAsUnrouted(): void {
		$angry = new class {
			/**
			 * Always throws.
			 *
			 * @param string            $userId The person.
			 * @param array<int,string> $scopes The scopes.
			 *
			 * @return array<int, mixed> Never.
			 */
			public function getEffectiveForUser(string $userId, array $scopes = []): array {
				throw new \RuntimeException('the register is down');
			}

			/**
			 * Always throws.
			 *
			 * @param string      $userId          The person.
			 * @param string      $schemaSlug      The schema.
			 * @param string      $notificationKey The rule.
			 * @param array|null  $override        The value.
			 * @param string|null $scope           The scope.
			 *
			 * @return void
			 */
			public function setOverride(
				string $userId,
				string $schemaSlug,
				string $notificationKey,
				?array $override,
				?string $scope = null
			): void {
				throw new \RuntimeException('the register is down');
			}
		};

		$routing = $this->routing(service: $angry);

		// The READ propagates: answering null would say "nothing routes here",
		// which is a different fact and would hand every reader back to the
		// local mirror on a bad minute.
		$this->expectException(exception: \RuntimeException::class);
		$routing->effectiveFor(userId: 'alice');
	}//end testAThrowingReadPropagatesRatherThanReadingAsUnrouted()

	/**
	 * A write that throws is reported as not routed, not as success.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/unread-state-on-the-case/specs/case-management/spec.md#requirement-the-daily-digest-switch-is-a-notification-preference-req-urs-06
	 */
	public function testAThrowingWriteIsReportedAsNotRouted(): void {
		$angry = new class {
			/**
			 * Answers, so the seam reads as available.
			 *
			 * @param string            $userId The person.
			 * @param array<int,string> $scopes The scopes.
			 *
			 * @return array<int, mixed> Nothing.
			 */
			public function getEffectiveForUser(string $userId, array $scopes = []): array {
				return [];
			}

			/**
			 * Always throws.
			 *
			 * @param string      $userId          The person.
			 * @param string      $schemaSlug      The schema.
			 * @param string      $notificationKey The rule.
			 * @param array|null  $override        The value.
			 * @param string|null $scope           The scope.
			 *
			 * @return void
			 */
			public function setOverride(
				string $userId,
				string $schemaSlug,
				string $notificationKey,
				?array $override,
				?string $scope = null
			): void {
				throw new \RuntimeException('the register is down');
			}
		};

		$this->assertFalse(
			condition: $this->routing(service: $angry)->setDigestEnabled(userId: 'alice', enabled: true),
			message: 'A write that did not land must not report that it did.'
		);
	}//end testAThrowingWriteIsReportedAsNotRouted()
}//end class
