<?php

/**
 * Unit tests for the webhook translations.
 *
 * The translation and the template syntax are real, and so is integriq's
 * SourceRequestedEvent (its stub when integriq is absent, which mirrors the
 * real constructor). Only the dispatcher, standing in for integriq's listener,
 * and the user session are doubles.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Flow;

use OCA\Dossiq\Service\Flow\RetiredTemplateSyntax;
use OCA\Dossiq\Service\Flow\RetiredWebhookSteps;
use OCA\Dossiq\Service\Flow\UnmappableStep;
use OCA\Integriq\Event\SourceRequestedEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\Flow\RetiredWebhookSteps
 * @covers \OCA\Dossiq\Service\Flow\RetiredTemplateSyntax
 */
class RetiredWebhookStepsTest extends TestCase {

	/**
	 * The Source requests the dispatcher saw.
	 *
	 * @var array<int, SourceRequestedEvent>
	 */
	private array $requests = [];

	/**
	 * The translation, with integriq answering (or refusing) every Source request.
	 *
	 * @param string|null $refusal Refuse with this reason instead of answering.
	 *
	 * @return RetiredWebhookSteps The translation.
	 */
	private function steps(?string $refusal = null): RetiredWebhookSteps {
		$events = $this->createMock(IEventDispatcher::class);
		$events->method('dispatchTyped')->willReturnCallback(
			function (Event $event) use ($refusal): void {
				if (($event instanceof SourceRequestedEvent) === false) {
					return;
				}

				$this->requests[] = $event;
				if ($refusal !== null) {
					$event->refuse(refusal: $refusal);
					return;
				}

				$event->setSource(sourceId: 'src-' . count($this->requests), sourceSlug: 'url-x', created: true);
			}
		);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('beheerder');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return new RetiredWebhookSteps($events, $session, new RetiredTemplateSyntax());
	}//end steps()

	/**
	 * A callWebhook without a template posts the case, with its own timeout, and says who asked.
	 *
	 * @return void
	 */
	public function testACallWebhookPostsTheCase(): void {
		$step = $this->steps()->action(config: ['url' => 'http://intern.example.org', 'timeoutSec' => 3]);

		self::assertSame(
			[
				'type' => 'openconnector.source-call',
				'config' => [
					'source' => 'src-1',
					'endpoint' => '/',
					'method' => 'POST',
					'output' => 'actionResult',
					'body' => ['case' => '{{ @item }}'],
				],
			],
			$step
		);
		self::assertSame('http://intern.example.org', $this->requests[0]->getBaseUrl());
		self::assertSame(3, $this->requests[0]->getTimeoutSeconds());
		self::assertSame('dossiq', $this->requests[0]->getSourceApp());
		self::assertSame('beheerder', $this->requests[0]->getUserId());
	}//end testACallWebhookPostsTheCase()

	/**
	 * A payload template is carried as a JSON string body, in integriq's placeholder syntax.
	 *
	 * @return void
	 */
	public function testAPayloadTemplateBecomesAStringBody(): void {
		$step = $this->steps()->action(
			config: ['url' => 'https://hooks.example.org/in', 'payloadTemplate' => '{"zaak": "{{case.identifier}}", "x": "{{ case.a.b }}{{other}}"}']
		);

		self::assertSame('{"zaak": "{{ identifier }}", "x": "{{ a.b }}"}', $step['config']['body']);
		self::assertSame(['Content-Type' => 'application/json'], $step['config']['headers']);
		self::assertSame(10, $this->requests[0]->getTimeoutSeconds());
	}//end testAPayloadTemplateBecomesAStringBody()

	/**
	 * A URL named by slug in a flat map is the URL; one chosen per tenant cannot be stored as one.
	 *
	 * @return void
	 */
	public function testAUrlMapIsReadOnlyWhenItDoesNotDependOnTheTenant(): void {
		$step = $this->steps()->action(
			config: ['urlSlug' => 'crm', 'urlMap' => ['crm' => 'https://crm.example.org/hook'], 'url' => 'https://fallback.example.org']
		);
		self::assertSame('/hook', $step['config']['endpoint']);
		self::assertSame('https://crm.example.org', $this->requests[0]->getBaseUrl());

		$this->expectException(UnmappableStep::class);
		$this->expectExceptionMessage('per tenant');
		$this->steps()->action(config: ['urlSlug' => 'crm', 'urlMap' => ['gemeente-a' => ['crm' => 'https://a.example.org']]]);
	}//end testAUrlMapIsReadOnlyWhenItDoesNotDependOnTheTenant()

	/**
	 * A transition webhook keeps its own headers, but a credential header belongs on the Source.
	 *
	 * @return void
	 */
	public function testACredentialHeaderIsRefused(): void {
		$step = $this->steps()->transition(config: ['url' => 'https://hooks.example.org', 'headers' => ['X-Bron' => 'dossiq']]);
		self::assertSame(['X-Bron' => 'dossiq'], $step['config']['headers']);
		self::assertSame(5, $this->requests[0]->getTimeoutSeconds());

		$this->expectException(UnmappableStep::class);
		$this->expectExceptionMessage('Authorization');
		$this->steps()->transition(config: ['url' => 'https://hooks.example.org', 'headers' => ['Authorization' => 'Bearer x']]);
	}//end testACredentialHeaderIsRefused()

	/**
	 * A URL that is not http(s) with a host, or that carries a password, is refused before integriq is asked.
	 *
	 * @return void
	 */
	public function testAnUnusableUrlIsRefusedWithoutAskingIntegriq(): void {
		foreach (['', 'ftp://files.example.org/x', 'hooks.example.org/x', 'https://user:pw@hooks.example.org/x'] as $url) {
			try {
				$this->steps()->transition(config: ['url' => $url]);
				self::fail('expected a refusal for "' . $url . '"');
			} catch (UnmappableStep $e) {
				self::assertNotSame('', $e->getMessage());
			}
		}

		self::assertSame([], $this->requests);
	}//end testAnUnusableUrlIsRefusedWithoutAskingIntegriq()

	/**
	 * Integriq's refusal is the step's reason, so the administrator sees why.
	 *
	 * @return void
	 */
	public function testIntegriqsRefusalIsTheReason(): void {
		$this->expectException(UnmappableStep::class);
		$this->expectExceptionMessage('this instance does not allow calls to the host 10.0.0.5');

		$this->steps(refusal: 'this instance does not allow calls to the host 10.0.0.5')->transition(config: ['url' => 'http://10.0.0.5/hook']);
	}//end testIntegriqsRefusalIsTheReason()
}//end class
