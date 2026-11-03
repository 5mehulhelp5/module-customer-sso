<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CustomerSso\Test\Unit\Etc;

use Composer\InstalledVersions;
use DmLab\SsoCore\Model\Config\Backend\ExtraAuthParams;
use DmLab\SsoCore\Model\Config\Backend\IssuerUrl;
use DmLab\SsoCore\Model\Config\Backend\Scopes;
use DmLab\SsoCore\Model\Preset\GenericOidcConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SystemConfigTest extends TestCase
{
    private const CONFIG_VTYPE = 'DmLab\\CustomerSso\\Model\\Preset\\GenericOidcConfig';

    public function testSystemXmlValidatesAgainstSystemFileSchema(): void
    {
        $xsd = InstalledVersions::getInstallPath('magento/module-config') . '/etc/system_file.xsd';
        self::assertFileExists($xsd);

        $dom = new \DOMDocument();
        self::assertTrue($dom->load($this->path('etc/adminhtml/system.xml')));
        $previous = libxml_use_internal_errors(true);
        $valid = $dom->schemaValidate($xsd);
        $errors = array_map(static fn (\LibXMLError $e): string => trim($e->message), libxml_get_errors());
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        self::assertTrue($valid, implode("\n", $errors));
    }

    public function testGenericGroupDependsOnEnabledAndGenericProvider(): void
    {
        $group = $this->genericGroup();
        self::assertSame('Generic OIDC', (string)$group->label);

        $depends = [];
        foreach ($group->depends->field as $field) {
            $depends[(string)$field['id']] = trim((string)$field);
        }
        self::assertSame([
            'dmlab_customer_sso/general/enabled' => '1',
            'dmlab_customer_sso/general/active_provider' => 'generic',
        ], $depends);
    }

    public function testGenericGroupPathMatchesReaderBasePath(): void
    {
        $di = simplexml_load_file($this->path('etc/di.xml'));
        self::assertNotFalse($di);
        $basePath = $di->xpath(sprintf(
            '/config/virtualType[@name="%s"]/arguments/argument[@name="basePath"]',
            self::CONFIG_VTYPE
        ));
        self::assertCount(1, $basePath);

        $group = $this->genericGroup();
        $section = $this->system()->xpath('/config/system/section[group[@id="generic"]]');
        self::assertCount(1, $section);
        self::assertSame(trim((string)$basePath[0]), $section[0]['id'] . '/' . $group['id']);
    }

    public function testGenericGroupAndFieldsShowInEveryScope(): void
    {
        $group = $this->genericGroup();
        $nodes = [$group];
        foreach ($group->field as $field) {
            $nodes[] = $field;
        }
        foreach ($nodes as $node) {
            foreach (['showInDefault', 'showInWebsite', 'showInStore'] as $scope) {
                self::assertSame('1', (string)$node[$scope], $node['id'] . ' ' . $scope);
            }
        }
    }

    public function testGenericFieldIdsMatchReaderFields(): void
    {
        $ids = [];
        foreach ($this->genericGroup()->field as $field) {
            $ids[] = (string)$field['id'];
        }
        self::assertSame([
            GenericOidcConfig::FIELD_ISSUER_URL,
            GenericOidcConfig::FIELD_DISPLAY_NAME,
            GenericOidcConfig::FIELD_SCOPES,
            GenericOidcConfig::FIELD_GROUPS_CLAIM,
            GenericOidcConfig::FIELD_EXTRA_AUTH_PARAMS,
        ], $ids);
    }

    /**
     * @return array<string, array{string, string, ?string}>
     */
    public static function genericFields(): array
    {
        return [
            'issuer_url' => ['issuer_url', 'text', IssuerUrl::class],
            'display_name' => ['display_name', 'text', null],
            'scopes' => ['scopes', 'text', Scopes::class],
            'groups_claim' => ['groups_claim', 'text', null],
            'extra_auth_params' => ['extra_auth_params', 'textarea', ExtraAuthParams::class],
        ];
    }

    #[DataProvider('genericFields')]
    public function testGenericFieldTypeAndBackendModel(string $id, string $type, ?string $backend): void
    {
        $fields = $this->genericGroup()->xpath(sprintf('field[@id="%s"]', $id));
        self::assertCount(1, $fields);
        self::assertSame($type, (string)$fields[0]['type']);

        if ($backend === null) {
            self::assertFalse(isset($fields[0]->backend_model));
            return;
        }
        self::assertSame($backend, trim((string)$fields[0]->backend_model));
        self::assertTrue(class_exists($backend), $backend);
    }

    public function testIssuerUrlIsRequiredSoGenericCannotBeSavedUnusable(): void
    {
        $fields = $this->genericGroup()->xpath('field[@id="issuer_url"]');
        self::assertSame('required-entry', trim((string)$fields[0]->validate));
    }

    public function testGenericDefaults(): void
    {
        $config = simplexml_load_file($this->path('etc/config.xml'));
        self::assertNotFalse($config);
        $generic = $config->default->dmlab_customer_sso->generic;

        self::assertSame('openid profile email', trim((string)$generic->scopes));
        self::assertSame('groups', trim((string)$generic->groups_claim));
        self::assertFalse(isset($generic->issuer_url));
        self::assertFalse(isset($generic->display_name));
        self::assertFalse(isset($generic->extra_auth_params));
    }

    private function genericGroup(): \SimpleXMLElement
    {
        $groups = $this->system()->xpath('/config/system/section[@id="dmlab_customer_sso"]/group[@id="generic"]');
        self::assertCount(1, $groups);

        return $groups[0];
    }

    private function system(): \SimpleXMLElement
    {
        $xml = simplexml_load_file($this->path('etc/adminhtml/system.xml'));
        self::assertNotFalse($xml);

        return $xml;
    }

    private function path(string $file): string
    {
        return dirname(__DIR__, 3) . '/' . $file;
    }
}
