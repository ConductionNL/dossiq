<?php

/**
 * Unit tests for MergeTemplateHandler — rendering a template into a case field.
 *
 * The behaviour worth protecting is the identity the write runs under. Under
 * FlowRunWorker the ambient session carries nobody, so a bare saveObject() is
 * refused as "User 'Anonymous' does not have permission" — measured live as
 * `merge_template_failed` on run f087ae22, where the seeded case flow stopped
 * at `besluit-document` and the case never received its decision document.
 * The write must go through the object service's runAs seam as the run's
 * acting identity, and a runAs that resolves to nobody must fail the step
 * loudly instead of writing as whoever happens to be ambient.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/case-flow-human-steps/specs/case-flow-human-steps/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Actions;

use OCA\Dossiq\Service\Actions\MergeTemplateHandler;
use OCA\Dossiq\Service\FlowRunAsScope;
use OCA\Dossiq\Service\SettingsService;
use OCP\IAppConfig;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * @covers \OCA\Dossiq\Service\Actions\MergeTemplateHandler
 *
 * @uses \OCA\Dossiq\Service\FlowRunAsScope
 * @uses \OCA\Dossiq\Service\Actions\ActionResult
 */
class MergeTemplateHandlerTest extends TestCase {

	/**
	 * The uids the object service's runAs seam was asked to act as.
	 *
	 * @var string[]
	 */
	private array $actedAs = [];

	/**
	 * The case the object service was asked to save, or null.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $saved = null;

	/**
	 * The object service double behind both the handler and the scope.
	 *
	 * @var object|null
	 */
	private ?object $objectService = null;

	protected function setUp(): void {
		$this->actedAs = [];
		$this->saved = null;

		$saved = &$this->saved;
		$actedAs = &$this->actedAs;
		$this->objectService = new class($saved, $actedAs) {
			/**
			 * @param array<string, mixed>|null $sink    Receives the saved case.
			 * @param string[]                  $actedAs Receives the runAs uids.
			 */
			public function __construct(private ?array &$sink, private array &$actedAs) {
			}

			/**
			 * @param array<string, mixed> $object   The object to save.
			 * @param string               $register The register.
			 * @param string               $schema   The schema.
			 *
			 * @return array<string, mixed> The saved object.
			 */
			public function saveObject(array $object, string $register, string $schema): array {
				$this->sink = $object;

				return $object;
			}

			/**
			 * @param IUser    $user      The identity to act as.
			 * @param callable $operation The operation.
			 *
			 * @return mixed The operation's result.
			 */
			public function runAs(IUser $user, callable $operation): mixed {
				$this->actedAs[] = $user->getUID();

				return $operation();
			}
		};
	}//end setUp()

	/**
	 * A handler over the recording object service.
	 *
	 * The handler resolves the object service through the container, the
	 * scope through SettingsService — in production both answer with the one
	 * OpenRegister ObjectService, so the doubles share one instance here too.
	 *
	 * @return MergeTemplateHandler The handler under test.
	 */
	private function handler(): MergeTemplateHandler {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')
			->with('OCA\OpenRegister\Service\ObjectService')
			->willReturn($this->objectService);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default): string {
				unset($app, $default);

				return ($key === 'register') ? 'dossiq' : 'case';
			}
		);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');
		$user->method('isEnabled')->willReturn(true);

		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturnCallback(
			static fn (string $uid): ?IUser => ($uid === 'admin') ? $user : null
		);

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->objectService);

		return new MergeTemplateHandler(
			container: $container,
			appConfig: $appConfig,
			runAsScope: new FlowRunAsScope($settings, $users),
			logger: new NullLogger(),
		);
	}//end handler()

	public function testTheRenderedTemplateIsSavedIntoTheTargetField(): void {
		$result = $this->handler()->handle(
			actionConfig: [
				'type' => 'mergeTemplate',
				'template' => 'Besluit over {{case.title}}',
				'targetField' => 'besluitDocument',
			],
			case: ['id' => 'case-1', 'title' => 'Kapvergunning'],
			transitionContext: []
		);

		self::assertTrue($result->succeeded);
		self::assertSame('Besluit over Kapvergunning', $this->saved['besluitDocument']);
	}//end testTheRenderedTemplateIsSavedIntoTheTargetField()

	/**
	 * 🔴 A CONTEXT THAT NAMES AN ACTING IDENTITY IS OBEYED.
	 *
	 * Under FlowRunWorker the ambient session carries nobody, so a bare
	 * saveObject() is refused as 'Anonymous' — measured live on run f087ae22
	 * as `merge_template_failed` at `besluit-document`, with the run context
	 * carrying `runAs` the whole time. The write must go through the object
	 * service's runAs seam as that user. Remove the wrap in the handler and
	 * this test goes red: the seam is never asked, and $actedAs stays empty.
	 */
	public function testTheWriteRunsAsTheRunsActingIdentity(): void {
		$result = $this->handler()->handle(
			actionConfig: [
				'type' => 'mergeTemplate',
				'template' => 'Besluit over {{case.title}}',
				'targetField' => 'besluitDocument',
			],
			case: ['id' => 'case-1', 'title' => 'Kapvergunning'],
			transitionContext: ['runAs' => 'admin']
		);

		self::assertTrue($result->succeeded);
		self::assertSame(
			['admin'],
			$this->actedAs,
			'The save must run through the object service\'s runAs seam as the run\'s acting identity.'
		);
		self::assertSame('Besluit over Kapvergunning', $this->saved['besluitDocument']);
	}//end testTheWriteRunsAsTheRunsActingIdentity()

	/**
	 * 🔴 AN UNRESOLVABLE ACTING IDENTITY REFUSES LOUDLY, WRITING NOTHING.
	 *
	 * A `runAs` naming no account must stop the step — never fall back to
	 * writing as whoever the ambient session happens to carry.
	 */
	public function testAnUnresolvableActingIdentityFailsWithoutWriting(): void {
		$result = $this->handler()->handle(
			actionConfig: [
				'type' => 'mergeTemplate',
				'template' => 'Besluit',
				'targetField' => 'besluitDocument',
			],
			case: ['id' => 'case-1'],
			transitionContext: ['runAs' => 'offboarded-nobody']
		);

		self::assertFalse($result->succeeded);
		self::assertSame('merge_template_failed', $result->error);
		self::assertNull($this->saved, 'Nothing may be written when the acting identity does not resolve.');
	}//end testAnUnresolvableActingIdentityFailsWithoutWriting()

	public function testADryRunPersistsNothing(): void {
		$result = $this->handler()->handle(
			actionConfig: [
				'type' => 'mergeTemplate',
				'template' => 'Besluit over {{case.title}}',
				'targetField' => 'besluitDocument',
			],
			case: ['id' => 'case-1', 'title' => 'Kapvergunning'],
			transitionContext: ['dryRun' => true, 'runAs' => 'admin']
		);

		self::assertTrue($result->succeeded);
		self::assertSame('Besluit over Kapvergunning', $result->data['rendered']);
		self::assertNull($this->saved, 'A dry run must not write the case.');
	}//end testADryRunPersistsNothing()

	public function testAMissingTargetFieldFailsTheStep(): void {
		$result = $this->handler()->handle(
			actionConfig: ['type' => 'mergeTemplate', 'template' => 'Besluit'],
			case: ['id' => 'case-1'],
			transitionContext: []
		);

		self::assertFalse($result->succeeded);
		self::assertSame('missing_target_field', $result->error);
		self::assertNull($this->saved);
	}//end testAMissingTargetFieldFailsTheStep()
}//end class
