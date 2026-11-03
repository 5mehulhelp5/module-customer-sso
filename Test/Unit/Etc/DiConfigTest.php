<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CustomerSso\Test\Unit\Etc;

use Composer\InstalledVersions;
use DmLab\CustomerSso\Block\Checkout\SsoButtonProcessor;
use DmLab\CustomerSso\Model\Config\Source\ActiveProvider;
use DmLab\CustomerSso\Model\PresetRegistry;
use DmLab\CustomerSso\Plugin\Customer\EnforceSso;
use DmLab\CustomerSso\Plugin\Customer\IdpLogout;
use DmLab\SsoCore\Api\GenericOidcConfigInterface;
use DmLab\SsoCore\Model\Preset\GenericOidcConfig;
use DmLab\SsoCore\Model\Preset\GenericOidcPreset;
use Magento\Store\Model\ScopeInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DiConfigTest extends TestCase
{
    private const CONFIG_VTYPE = 'DmLab\\CustomerSso\\Model\\Preset\\GenericOidcConfig';
    private const PRESET_VTYPE = 'DmLab\\CustomerSso\\Model\\Preset\\GenericOidcPreset';

    public function testGenericOidcConfigReaderIsStoreScopedOnGenericGroup(): void
    {
        $xml = $this->load('etc/di.xml');
        $types = $xml->xpath(sprintf('/config/virtualType[@name="%s"]', self::CONFIG_VTYPE));
        self::assertCount(1, $types);
        self::assertSame(GenericOidcConfig::class, (string)$types[0]['type']);

        $args = $this->arguments($types[0]);
        self::assertSame('dmlab_customer_sso/generic', $args['basePath']);
        self::assertSame(ScopeInterface::SCOPE_STORE, $args['scopeType']);
    }

    public function testGenericOidcPresetUsesTheReaderVirtualType(): void
    {
        $xml = $this->load('etc/di.xml');
        $types = $xml->xpath(sprintf('/config/virtualType[@name="%s"]', self::PRESET_VTYPE));
        self::assertCount(1, $types);
        self::assertSame(GenericOidcPreset::class, (string)$types[0]['type']);
        self::assertSame(self::CONFIG_VTYPE, $this->arguments($types[0])['config']);
    }

    public function testPresetRegistryRegistersGenericPreset(): void
    {
        $items = $this->load('etc/di.xml')->xpath(sprintf(
            '/config/type[@name="%s"]/arguments/argument[@name="presets"]/item[@name="generic"]',
            PresetRegistry::class
        ));
        self::assertCount(1, $items);
        self::assertSame('object', (string)$items[0]->attributes('xsi', true)['type']);
        self::assertSame(self::PRESET_VTYPE, trim((string)$items[0]));
    }

    public function testActiveProviderDropdownLabelsGenericAsGenericOidc(): void
    {
        $items = $this->load('etc/di.xml')->xpath(sprintf(
            '/config/type[@name="%s"]/arguments/argument[@name="optionLabels"]/item[@name="generic"]',
            ActiveProvider::class
        ));
        self::assertCount(1, $items);
        self::assertSame('string', (string)$items[0]->attributes('xsi', true)['type']);
        self::assertSame('true', (string)$items[0]['translate']);
        self::assertSame('Generic OIDC', trim((string)$items[0]));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function diFiles(): array
    {
        return ['global' => ['etc/di.xml'], 'frontend' => ['etc/frontend/di.xml']];
    }

    #[DataProvider('diFiles')]
    public function testNoPreferenceForGenericOidcConfigInterface(string $file): void
    {
        $prefs = $this->load($file)->xpath(
            sprintf('/config/preference[@for="%s"]', GenericOidcConfigInterface::class)
        );
        self::assertSame([], $prefs);
    }

    #[DataProvider('diFiles')]
    public function testDiFileValidatesAgainstObjectManagerSchema(string $file): void
    {
        $framework = (string)InstalledVersions::getInstallPath('magento/framework');
        $xsd = $framework . '/ObjectManager/etc/config.xsd';
        self::assertFileExists($xsd);

        $dom = new \DOMDocument();
        self::assertTrue($dom->load(dirname(__DIR__, 3) . '/' . $file));
        $previous = libxml_use_internal_errors(true);
        // config.xsd redefines a `urn:magento:framework:` schema; map that urn to the install path.
        libxml_set_external_entity_loader(
            static fn (?string $public, string $system): string => str_starts_with($system, 'urn:magento:framework:')
                ? $framework . '/' . substr($system, strlen('urn:magento:framework:'))
                : $system
        );
        try {
            $valid = $dom->schemaValidate($xsd);
        } finally {
            libxml_set_external_entity_loader(null);
        }
        $errors = array_map(static fn (\LibXMLError $e): string => trim($e->message), libxml_get_errors());
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        self::assertTrue($valid, implode("\n", $errors));
    }

    public function testClientSecretIsMarkedSensitive(): void
    {
        $xml = simplexml_load_file(dirname(__DIR__, 3) . '/etc/di.xml');
        self::assertNotFalse($xml);

        $items = $xml->xpath(
            '/config/type[@name="Magento\Config\Model\Config\TypePool"]'
            . '/arguments/argument[@name="sensitive"]'
            . '/item[@name="dmlab_customer_sso/general/client_secret"]'
        );

        self::assertCount(1, $items);
        self::assertSame('1', trim((string)$items[0]));
    }

    public function testPasswordLoginEnforcedOnAccountManagementInAllAreas(): void
    {
        $xml = simplexml_load_file(dirname(__DIR__, 3) . '/etc/di.xml');
        self::assertNotFalse($xml);

        $plugins = $xml->xpath(
            '/config/type[@name="Magento\Customer\Api\AccountManagementInterface"]/plugin'
        );

        self::assertCount(1, $plugins);
        self::assertSame(EnforceSso::class, (string)$plugins[0]['type']);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function checkoutPopups(): array
    {
        return [
            'minicart popup' => [
                'Magento\\Customer\\Block\\Account\\AuthenticationPopup',
                'components/authenticationPopup/children',
            ],
            'checkout step' => [
                'Magento\\Checkout\\Block\\Onepage',
                'components/checkout/children/authentication/children',
            ],
        ];
    }

    #[DataProvider('checkoutPopups')]
    public function testSsoButtonProcessorRegisteredForPopup(string $block, string $childrenPath): void
    {
        $xml = simplexml_load_file(dirname(__DIR__, 3) . '/etc/frontend/di.xml');
        self::assertNotFalse($xml);

        $items = $xml->xpath(sprintf(
            '/config/type[@name="%s"]/arguments/argument[@name="layoutProcessors"]/item',
            $block
        ));
        self::assertCount(1, $items);

        $virtualTypes = $xml->xpath(sprintf(
            '/config/virtualType[@name="%s"][@type="%s"]/arguments/argument[@name="childrenPath"]',
            trim((string)$items[0]),
            SsoButtonProcessor::class
        ));
        self::assertCount(1, $virtualTypes);
        self::assertSame($childrenPath, trim((string)$virtualTypes[0]));
    }

    public function testIdpLogoutIsOffByDefaultAndPluginsCustomerLogout(): void
    {
        $config = simplexml_load_file(dirname(__DIR__, 3) . '/etc/config.xml');
        self::assertNotFalse($config);
        self::assertSame('0', trim((string)$config->default->dmlab_customer_sso->general->idp_logout));

        $xml = simplexml_load_file(dirname(__DIR__, 3) . '/etc/frontend/di.xml');
        self::assertNotFalse($xml);
        $plugins = $xml->xpath(
            '/config/type[@name="Magento\Customer\Controller\Account\Logout"]'
            . '/plugin[@name="dmlab_customer_sso_idp_logout"]'
        );
        self::assertCount(1, $plugins);
        self::assertSame(IdpLogout::class, (string)$plugins[0]['type']);
    }

    private function load(string $file): \SimpleXMLElement
    {
        $xml = simplexml_load_file(dirname(__DIR__, 3) . '/' . $file);
        self::assertNotFalse($xml);

        return $xml;
    }

    /**
     * @return array<string, string>
     */
    private function arguments(\SimpleXMLElement $type): array
    {
        $args = [];
        foreach ($type->arguments->argument as $argument) {
            $args[(string)$argument['name']] = trim((string)$argument);
        }

        return $args;
    }
}
