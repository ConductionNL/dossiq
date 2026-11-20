<?php

/**
 * IntakeFormDependentsListener unit tests.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/a-request-form-opens-the-case-at-once/specs/case-intake-destination/spec.md#requirement-publishing-a-case-type-must-re-check-the-forms-bound-to-it
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Listener;

use OCA\Dossiq\Kernel\Contracts\BoundFormsCheckInterface;
use OCA\Dossiq\Kernel\Contracts\IntakeFormBindingInterface;
use OCA\Dossiq\Kernel\Contracts\IntakeFormBindingsInterface;
use OCA\Dossiq\Kernel\Contracts\SettingsServiceInterface;
use OCA\Dossiq\Listener\IntakeFormDependentsListener;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Event\FormDestinationDependentsEvent;
use OCA\OpenRegister\Service\Form\FormDependent;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * When the case schema is about to change, dossiq names its bound intake forms.
 *
 * @covers \OCA\Dossiq\Listener\IntakeFormDependentsListener
 */
class IntakeFormDependentsListenerTest extends TestCase {

	/**
	 * The configured case schema.
	 *
	 * @var string
	 */
	private string $caseSchema = 'case';

	/**
	 * Names a case type.
	 *
	 * @var BoundFormsCheckInterface&MockObject
	 */
	private BoundFormsCheckInterface&MockObject $check;

	/**
	 * The bound case types.
	 *
	 * @var IntakeFormBindingsInterface&MockObject
	 */
	private IntakeFormBindingsInterface&MockObject $bindings;

	/**
	 * Reads a binding as a mapping.
	 *
	 * @var IntakeFormBindingInterface&MockObject
	 */
	private IntakeFormBindingInterface&MockObject $binding;

	/**
	 * The logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Build mocks; the check names a case type by its `id`.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->check = $this->createMock(BoundFormsCheckInterface::class);
		$this->check->method('namesOf')->willReturnCallback(
			static fn (array $ct): array => (isset($ct['id']) === true) ? [$ct['id'], 'alias'] : []
		);
		$this->bindings = $this->createMock(IntakeFormBindingsInterface::class);
		$this->binding = $this->createMock(IntakeFormBindingInterface::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * The listener over the current configured case schema.
	 *
	 * @return IntakeFormDependentsListener The listener.
	 */
	private function listener(): IntakeFormDependentsListener {
		$settings = $this->createMock(SettingsServiceInterface::class);
		$settings->method('getConfigValue')->willReturnCallback(
			fn (string $key): string => ($key === 'case_schema') ? $this->caseSchema : ''
		);
		return new IntakeFormDependentsListener(
			settingsService: $settings,
			check: $this->check,
			bindings: $this->bindings,
			binding: $this->binding,
			logger: $this->logger,
		);
	}//end listener()

	/**
	 * A schema with a slug.
	 *
	 * @param string $slug Its slug.
	 *
	 * @return Schema The schema.
	 */
	private static function schema(string $slug): Schema {
		$schema = new Schema();
		$schema->setSlug($slug);
		return $schema;
	}//end schema()

	/**
	 * Each bound case type that names itself becomes one form, titled and owned as the case type.
	 *
	 * @return void
	 */
	public function testEachBoundCaseTypeIsAnnouncedAsAForm(): void {
		$this->bindings->method('boundCaseTypes')->willReturn(
			[
				['id' => 'ct-1', 'title' => '  Bezwaar  ', '@self' => ['owner' => 'alice']],
				['id' => 'ct-2', 'title' => ' ', 'name' => 'Woo-verzoek', '@self' => ['owner' => '']],
				['id' => 'ct-3', 'identifier' => 'VTH-1'],
				['title' => 'Names nothing'],
			]
		);
		$this->binding->method('mappingFor')->willReturnCallback(
			static fn (array $ct): array => ['subject' => 'answer-' . $ct['id']]
		);

		$event = new FormDestinationDependentsEvent(self::schema('case'));
		$this->listener()->handle($event);

		$forms = $event->getForms();
		$this->assertCount(3, $forms);
		$this->assertContainsOnlyInstancesOf(FormDependent::class, $forms);

		$this->assertSame(['ct-1', 'dossiq', 'Bezwaar', 'alice', ['subject' => 'answer-ct-1']], [$forms[0]->id, $forms[0]->app, $forms[0]->title, $forms[0]->author, $forms[0]->mapping]);
		$this->assertSame(['ct-2', 'Woo-verzoek', null], [$forms[1]->id, $forms[1]->title, $forms[1]->author]);
		$this->assertSame(['ct-3', 'VTH-1', null], [$forms[2]->id, $forms[2]->title, $forms[2]->author]);
	}//end testEachBoundCaseTypeIsAnnouncedAsAForm()

	/**
	 * A case type without any title is announced with an empty one.
	 *
	 * @return void
	 */
	public function testACaseTypeWithoutATitleGetsAnEmptyOne(): void {
		$this->bindings->method('boundCaseTypes')->willReturn([['id' => 'ct-1', 'title' => 7, '@self' => 'not-an-array']]);
		$this->binding->method('mappingFor')->willReturn([]);

		$event = new FormDestinationDependentsEvent(self::schema('case'));
		$this->listener()->handle($event);

		$this->assertSame('', $event->getForms()[0]->title);
		$this->assertNull($event->getForms()[0]->author);
	}//end testACaseTypeWithoutATitleGetsAnEmptyOne()

	/**
	 * A form's unpublish takes the binding off its case type.
	 *
	 * @return void
	 */
	public function testUnpublishingAFormUnbindsItsCaseType(): void {
		$this->bindings->method('boundCaseTypes')->willReturn([['id' => 'ct-7', 'title' => 'X']]);
		$this->binding->method('mappingFor')->willReturn([]);
		$this->bindings->expects($this->once())->method('unbind')->with('ct-7');

		$event = new FormDestinationDependentsEvent(self::schema('case'));
		$this->listener()->handle($event);

		($event->getForms()[0]->unpublish)();
	}//end testUnpublishingAFormUnbindsItsCaseType()

	/**
	 * Another schema, or no configured case schema, announces nothing.
	 *
	 * @return void
	 */
	public function testAnotherSchemaAnnouncesNothing(): void {
		$this->bindings->expects($this->never())->method('boundCaseTypes');

		$event = new FormDestinationDependentsEvent(self::schema('contactMoment'));
		$this->listener()->handle($event);
		$this->assertSame([], $event->getForms());

		$this->caseSchema = '';
		$event = new FormDestinationDependentsEvent(self::schema(''));
		$this->listener()->handle($event);
		$this->assertSame([], $event->getForms());
	}//end testAnotherSchemaAnnouncesNothing()

	/**
	 * The case schema is recognised by its uuid too.
	 *
	 * @return void
	 */
	public function testTheCaseSchemaIsRecognisedByUuid(): void {
		$this->caseSchema = 'uuid-9';
		$this->bindings->expects($this->once())->method('boundCaseTypes')->willReturn([]);

		$schema = self::schema('case');
		$schema->setUuid('uuid-9');
		$this->listener()->handle(new FormDestinationDependentsEvent($schema));
	}//end testTheCaseSchemaIsRecognisedByUuid()

	/**
	 * A failure while listing is logged as an error, not thrown.
	 *
	 * @return void
	 */
	public function testAFailureIsLoggedNotThrown(): void {
		$this->bindings->method('boundCaseTypes')->willThrowException(new RuntimeException('register offline'));
		$this->logger->expects($this->once())->method('error')->with(
			'Dossiq intake: could not list the intake forms that write into the case schema about to change',
			['reason' => 'register offline']
		);

		$event = new FormDestinationDependentsEvent(self::schema('case'));
		$this->listener()->handle($event);

		$this->assertSame([], $event->getForms());
	}//end testAFailureIsLoggedNotThrown()

	/**
	 * Any other event is ignored.
	 *
	 * @return void
	 */
	public function testAnyOtherEventIsIgnored(): void {
		$this->bindings->expects($this->never())->method('boundCaseTypes');

		$this->listener()->handle(new Event());
	}//end testAnyOtherEventIsIgnored()
}//end class
