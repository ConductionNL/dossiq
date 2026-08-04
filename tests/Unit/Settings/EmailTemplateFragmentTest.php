<?php

/**
 * Email Template Register Fragment + Settings Unit Tests
 *
 * Verifies that the register.d/35-email-templates.json fragment appends its
 * three Dutch seed objects onto the procest monolith via the ADR-037
 * deep-merge loader, that the emailTemplate schema is the only new email
 * schema (no emailMessage/emailThread), and that EmailSettings scopes the
 * shared-mailbox config keys for delegated admins without leaking the
 * sensitive password key.
 *
 * @category Tests
 * @package  OCA\Procest\Tests\Unit\Settings
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
 * @link https://procest.nl
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Settings;

use OCA\Procest\AppInfo\Application;
use OCA\Procest\Service\Settings\RegisterFragmentMerger;
use OCA\Procest\Settings\EmailSettings;
use OCP\App\IAppManager;
use OCP\AppFramework\Services\IInitialState;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Integration-style unit tests for the case-email-integration seeds + settings.
 *
 * @covers \OCA\Procest\Service\SettingsService
 * @covers \OCA\Procest\Settings\EmailSettings
 *
 * @uses \OCA\Procest\Service\Settings\RegisterFragmentMerger
 *
 * @spec openspec/changes/case-email-integration/tasks.md#T03
 * @spec openspec/changes/case-email-integration/tasks.md#T10
 */
class EmailTemplateFragmentTest extends TestCase
{

    /**
     * The three Dutch default template seed slugs.
     *
     * @var array<int, string>
     */
    private const SEED_SLUGS = [
        'email-template-ontvangstbevestiging',
        'email-template-informatieverzoek',
        'email-template-besluit',
    ];

    /**
     * @var array<string, mixed>
     */
    private array $merged;

    /**
     * Load the monolith and merge the real register.d fragments.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $base = json_decode(
            (string) file_get_contents(__DIR__.'/../../../lib/Settings/procest_register.json'),
            true
        );

        [$merged] = (new RegisterFragmentMerger())->merge(
            base: $base,
            fragmentDir: __DIR__.'/../../../lib/Settings/register.d'
        );

        $this->merged = $merged;
    }//end setUp()

    /**
     * The three Dutch default template seeds are appended to components.objects.
     *
     * @return void
     */
    public function testSeedObjectsAppended(): void
    {
        $slugs = array_map(
            static function (array $object): string {
                return (string) ($object['@self']['slug'] ?? '');
            },
            $this->merged['components']['objects']
        );

        foreach (self::SEED_SLUGS as $slug) {
            $this->assertContains($slug, $slugs, $slug.' seed must be appended');
        }
    }//end testSeedObjectsAppended()

    /**
     * Each seed targets the emailTemplate schema in the procest register, has
     * a Dutch name, version 1, and is active.
     *
     * @return void
     */
    public function testSeedObjectsAreWellFormed(): void
    {
        $names = [];
        foreach ($this->merged['components']['objects'] as $object) {
            $self = ($object['@self'] ?? []);
            if (($self['schema'] ?? '') !== 'emailTemplate') {
                continue;
            }

            $this->assertSame('procest', $self['register'] ?? null);
            $this->assertSame(1, $object['version'] ?? null);
            $this->assertTrue($object['isActive'] ?? false);
            $this->assertNotEmpty($object['subject'] ?? '');
            $this->assertNotEmpty($object['body'] ?? '');
            $names[] = (string) ($object['name'] ?? '');
        }

        $this->assertContains('Ontvangstbevestiging', $names);
        $this->assertContains('Informatieverzoek', $names);
        $this->assertContains('Besluit', $names);
    }//end testSeedObjectsAreWellFormed()

    /**
     * emailTemplate is the only new email schema — no parallel message store.
     *
     * @return void
     */
    public function testNoParallelEmailSchemaInvented(): void
    {
        $schemas = $this->merged['components']['schemas'];
        $this->assertArrayHasKey('emailTemplate', $schemas);
        $this->assertArrayNotHasKey('emailMessage', $schemas);
        $this->assertArrayNotHasKey('emailThread', $schemas);
    }//end testNoParallelEmailSchemaInvented()

    /**
     * EmailSettings delegates the shared-mailbox config keys but NOT the
     * sensitive password key.
     *
     * @return void
     */
    public function testDelegatedSettingsScopeKeysWithoutPassword(): void
    {
        $settings = new EmailSettings(
            $this->createMock(IAppManager::class),
            $this->createMock(IInitialState::class),
        );

        $this->assertSame('procest', $settings->getSection());

        $authorized = $settings->getAuthorizedAppConfig();
        $this->assertArrayHasKey(Application::APP_ID, $authorized);

        $keys = $authorized[Application::APP_ID];
        $this->assertContains('email_imap_host', $keys);
        $this->assertContains('email_transport', $keys);
        $this->assertContains('email_poll_interval', $keys);
        $this->assertNotContains('email_imap_password', $keys, 'sensitive password must not be delegated');
    }//end testDelegatedSettingsScopeKeysWithoutPassword()
}//end class
