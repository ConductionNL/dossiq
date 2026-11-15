<?php

/**
 * CaseEmailRepository unit tests
 *
 * The repository reads templates and case records out of OpenRegister and
 * writes the sent and received messages back. These tests pin what it reads
 * and writes, the template variables a case gives (with their deprecated
 * aliases), and that every path stands down quietly when OpenRegister or the
 * schema configuration is missing.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Email
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Email;

use OCA\Dossiq\Service\Email\CaseEmailRepository;
use OCA\Dossiq\Service\SettingsService;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the case e-mail repository.
 *
 * @covers \OCA\Dossiq\Service\Email\CaseEmailRepository
 *
 * @uses \OCA\Dossiq\Service\Support\ConfiguredSchemaRows
 * @uses \OCA\Dossiq\Service\Support\SearchesObjects
 */
class CaseEmailRepositoryTest extends TestCase {

	/**
	 * The schema configuration every test starts from.
	 */
	private const CONFIG = [
		'register' => 'dossiq',
		'case_schema' => 'case',
		'email_template_schema' => 'emailTemplate',
		'email_message_schema' => 'emailMessage',
	];

	/**
	 * The fake object service and what it was asked.
	 *
	 * @var object
	 */
	private object $objects;

	/**
	 * Build the fake object service.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->objects = new class {
			/** @var array<string, mixed> */
			public array $found = [];

			/** @var array<int, array<string, mixed>> */
			public array $finds = [];

			/** @var array<int, array<string, mixed>> */
			public array $saved = [];

			/** @var array<int, array<int, mixed>> */
			public array $searches = [];

			/** @var array<int, mixed> */
			public array $rows = [];

			/**
			 * @param string $id       The id.
			 * @param mixed  $register The register.
			 * @param mixed  $schema   The schema.
			 *
			 * @return mixed
			 */
			public function find(string $id, mixed $register = null, mixed $schema = null): mixed {
				$this->finds[] = compact('id', 'register', 'schema');
				if (array_key_exists($id, $this->found) === false) {
					throw new DoesNotExistException('gone');
				}

				return $this->found[$id];
			}

			/**
			 * @param array<string, mixed> $object   The object.
			 * @param mixed                $register The register.
			 * @param mixed                $schema   The schema.
			 *
			 * @return array<string, mixed>
			 */
			public function saveObject(array $object, mixed $register = null, mixed $schema = null): array {
				$this->saved[] = compact('object', 'register', 'schema');
				return $object;
			}

			/**
			 * @param string               $register The register slug.
			 * @param string               $schema   The schema slug.
			 * @param array<string, mixed> $filters  The filters.
			 *
			 * @return array<int, mixed>
			 */
			public function searchObjectsBySlug(string $register, string $schema, array $filters): array {
				$this->searches[] = [$register, $schema, $filters];
				return $this->rows;
			}
		};
	}//end setUp()

	public function testATemplateIsReadFromTheTemplateSchema(): void {
		$this->objects->found['tpl-1'] = ['id' => 'tpl-1', 'subject' => 'Ontvangst'];

		$template = $this->repository()->findTemplate(templateId: 'tpl-1');

		$this->assertSame(['id' => 'tpl-1', 'subject' => 'Ontvangst'], $template);
		$this->assertSame([['id' => 'tpl-1', 'register' => 'dossiq', 'schema' => 'emailTemplate']], $this->objects->finds);
	}//end testATemplateIsReadFromTheTemplateSchema()

	public function testAMissingTemplateIsNull(): void {
		$this->assertNull($this->repository()->findTemplate(templateId: 'tpl-gone'));
	}//end testAMissingTemplateIsNull()

	public function testNoTemplateIsReadWithoutTheTemplateSchemaOrOpenRegister(): void {
		$this->assertNull($this->repository(config: ['email_template_schema' => ''])->findTemplate(templateId: 'tpl-1'));
		$this->assertNull($this->repository(withObjects: false)->findTemplate(templateId: 'tpl-1'));
		$this->assertSame([], $this->objects->finds);
	}//end testNoTemplateIsReadWithoutTheTemplateSchemaOrOpenRegister()

	public function testTheTemplatesOfACaseTypeAreSearchedForUpToAHundred(): void {
		$this->objects->rows = [['id' => 'tpl-1'], ['id' => 'tpl-2']];

		$templates = $this->repository()->findTemplatesForCaseType(caseTypeId: 'ct-1');

		$this->assertSame([['id' => 'tpl-1'], ['id' => 'tpl-2']], $templates);
		$this->assertSame([['dossiq', 'emailTemplate', ['caseType' => 'ct-1', '_limit' => 100]]], $this->objects->searches);
	}//end testTheTemplatesOfACaseTypeAreSearchedForUpToAHundred()

	public function testACaseRecordIsReadAndSerialised(): void {
		$entity = new class implements \JsonSerializable {
			/**
			 * @return array<string, mixed>
			 */
			public function jsonSerialize(): array {
				return ['id' => 'case-1', 'identifier' => 'Z-2026-1'];
			}
		};
		$this->objects->found['case-1'] = $entity;
		$this->objects->found['case-null'] = null;
		$this->objects->found['case-odd'] = 'not a record';

		$repository = $this->repository();

		$this->assertSame(['id' => 'case-1', 'identifier' => 'Z-2026-1'], $repository->loadCaseRecord(caseId: 'case-1'));
		$this->assertSame('case', $this->objects->finds[0]['schema']);
		$this->assertSame([], $repository->loadCaseRecord(caseId: 'case-null'));
		$this->assertSame([], $repository->loadCaseRecord(caseId: 'case-odd'));
		$this->assertSame([], $this->repository(withObjects: false)->loadCaseRecord(caseId: 'case-1'));
	}//end testACaseRecordIsReadAndSerialised()

	public function testACaseGivesItsTemplateVariablesWithTheDeprecatedAliases(): void {
		$this->objects->found['case-1'] = [
			'identifier' => 'Z-2026-1',
			'title' => 'Omgevingsvergunning',
			'startDate' => '2026-01-05',
			'deadline' => '2026-03-01',
			'status' => 'open',
			'assignee' => 'hanna',
			'contact' => ['name' => 'Jan Jansen', 'email' => 'jan@example.nl', 'phone' => '0612345678'],
			'caseTypeTitle' => 'Vergunning',
		];

		$variables = $this->repository()->loadCaseVariables(caseId: 'case-1');

		$this->assertSame(
			[
				'zaakNummer' => 'Z-2026-1',
				'titel' => 'Omgevingsvergunning',
				'startDate' => '2026-01-05',
				'endDate' => '',
				'deadline' => '2026-03-01',
				'status' => 'open',
				'handler' => 'hanna',
				'contactNaam' => 'Jan Jansen',
				'contactEmail' => 'jan@example.nl',
				'contactTelefoon' => '0612345678',
				'zaaktypeNaam' => 'Vergunning',
				'zaaktypeOmschrijving' => '',
				'title' => 'Omgevingsvergunning',
				'startdatum' => '2026-01-05',
			],
			$variables
		);
	}//end testACaseGivesItsTemplateVariablesWithTheDeprecatedAliases()

	public function testFlatContactFieldsWinOverTheNestedContact(): void {
		$variables = $this->repository()->flattenCaseVariables(
			caseObj: ['contactName' => 'A', 'contactEmail' => 'a@example.nl', 'contactPhone' => '1', 'contact' => ['name' => 'B', 'email' => 'b@example.nl', 'phone' => '2']]
		);

		$this->assertSame(['A', 'a@example.nl', '1'], [$variables['contactNaam'], $variables['contactEmail'], $variables['contactTelefoon']]);
	}//end testFlatContactFieldsWinOverTheNestedContact()

	public function testAnEmptyCaseGivesNoVariables(): void {
		$this->assertSame([], $this->repository()->flattenCaseVariables(caseObj: []));
		$this->objects->found['case-null'] = null;
		$this->assertSame([], $this->repository()->loadCaseVariables(caseId: 'case-null'));
	}//end testAnEmptyCaseGivesNoVariables()

	public function testASentMessageIsRecordedAsOutbound(): void {
		$messageId = $this->repository()->recordSentEmail(caseId: 'case-1', fromAddress: 'zaken@gemeente.nl', to: 'jan@example.nl', subject: 'Ontvangst', body: 'Beste Jan');

		$this->assertMatchesRegularExpression('/^msg-[0-9a-f]+$/', $messageId);
		$this->assertCount(1, $this->objects->saved);
		$saved = $this->objects->saved[0];
		$this->assertSame(['dossiq', 'emailMessage'], [$saved['register'], $saved['schema']]);
		$this->assertSame(
			['case' => 'case-1', 'direction' => 'outbound', 'from' => 'zaken@gemeente.nl', 'to' => 'jan@example.nl', 'subject' => 'Ontvangst', 'body' => 'Beste Jan', 'messageId' => $messageId],
			array_diff_key($saved['object'], ['sentAt' => true])
		);
		$this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}$/', $saved['object']['sentAt']);
	}//end testASentMessageIsRecordedAsOutbound()

	public function testAReceivedMessageIsRecordedAsInboundWithWhatItAnswers(): void {
		$messageId = $this->repository()->recordReceivedEmail(caseId: 'case-1', from: 'jan@example.nl', recipient: 'zaken@gemeente.nl', subject: 'Re: Ontvangst', body: 'Dank', inReplyTo: 'msg-abc');

		$object = $this->objects->saved[0]['object'];
		$this->assertSame('inbound', $object['direction']);
		$this->assertSame('msg-abc', $object['inReplyTo']);
		$this->assertSame($messageId, $object['messageId']);
		$this->assertSame('zaken@gemeente.nl', $object['to']);
		$this->assertArrayHasKey('receivedAt', $object);
		$this->assertArrayNotHasKey('sentAt', $object);
	}//end testAReceivedMessageIsRecordedAsInboundWithWhatItAnswers()

	public function testWithoutAMessageSchemaOrOpenRegisterAnIdIsStillGivenButNothingIsWritten(): void {
		$withoutSchema = $this->repository(config: ['email_message_schema' => '']);

		$sent = $withoutSchema->recordSentEmail(caseId: 'c', fromAddress: 'a', to: 'b', subject: 's', body: 'b');
		$received = $withoutSchema->recordReceivedEmail(caseId: 'c', from: 'a', recipient: 'b', subject: 's', body: 'b', inReplyTo: '');
		$offline = $this->repository(withObjects: false)->recordSentEmail(caseId: 'c', fromAddress: 'a', to: 'b', subject: 's', body: 'b');
		$offlineIn = $this->repository(withObjects: false)->recordReceivedEmail(caseId: 'c', from: 'a', recipient: 'b', subject: 's', body: 'b', inReplyTo: '');

		$this->assertSame([], $this->objects->saved);
		foreach ([$sent, $received, $offline, $offlineIn] as $id) {
			$this->assertStringStartsWith('msg-', $id);
		}
	}//end testWithoutAMessageSchemaOrOpenRegisterAnIdIsStillGivenButNothingIsWritten()

	public function testACaseIsFoundByItsIdentifier(): void {
		$this->objects->rows = [['uuid' => 'case-uuid-1', 'identifier' => 'Z-2026-1']];

		$this->assertSame('case-uuid-1', $this->repository()->findCaseIdByIdentifier(identifier: 'Z-2026-1'));
		$this->assertSame([['dossiq', 'case', ['identifier' => 'Z-2026-1', '_limit' => 1]]], $this->objects->searches);

		$this->objects->rows = [['id' => 'case-1', 'uuid' => 'case-uuid-1']];
		$this->assertSame('case-1', $this->repository()->findCaseIdByIdentifier(identifier: 'Z-2026-1'));
	}//end testACaseIsFoundByItsIdentifier()

	public function testAnUnknownIdentifierFindsNoCase(): void {
		$this->assertNull($this->repository()->findCaseIdByIdentifier(identifier: 'Z-0000-0'));
		$this->objects->rows = [['identifier' => 'Z-1']];
		$this->assertNull($this->repository()->findCaseIdByIdentifier(identifier: 'Z-1'));
		$this->assertNull($this->repository(withObjects: false)->findCaseIdByIdentifier(identifier: 'Z-1'));
	}//end testAnUnknownIdentifierFindsNoCase()

	/**
	 * The repository over the fake object service and the configuration.
	 *
	 * @param array<string, string> $config      Overrides of the configuration.
	 * @param bool                  $withObjects Whether OpenRegister is there.
	 *
	 * @return CaseEmailRepository
	 */
	private function repository(array $config = [], bool $withObjects = true): CaseEmailRepository {
		$values = array_merge(self::CONFIG, $config);
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($withObjects === true ? $this->objects : null);
		$settings->method('getConfigValue')->willReturnCallback(static fn (string $key): string => ($values[$key] ?? ''));

		return new CaseEmailRepository(settingsService: $settings);
	}//end repository()
}//end class
