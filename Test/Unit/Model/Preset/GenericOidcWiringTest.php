<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CustomerSso\Test\Unit\Model\Preset;

use DmLab\CustomerSso\Model\ActiveProviderResolver;
use DmLab\CustomerSso\Model\PresetRegistry;
use DmLab\SsoCore\Model\Oidc\UrlPolicy;
use DmLab\SsoCore\Model\Preset\GenericOidcConfig;
use DmLab\SsoCore\Model\Preset\GenericOidcConfigParser;
use DmLab\SsoCore\Model\Preset\GenericOidcPreset;
use Magento\Framework\App\Config\ScopeConfigInterface;
use PHPUnit\Framework\TestCase;

/**
 * Builds the generic preset exactly as etc/di.xml wires it, over a mocked ScopeConfig.
 */
class GenericOidcWiringTest extends TestCase
{
    /**
     * @var array<string, string|null>
     */
    private array $values = [];

    /**
     * @var list<array{string, string, mixed}>
     */
    private array $calls = [];

    /**
     * @var string
     */
    private string $basePath;

    /**
     * @var GenericOidcPreset
     */
    private GenericOidcPreset $preset;

    /**
     * @var PresetRegistry
     */
    private PresetRegistry $registry;

    /**
     * @var ScopeConfigInterface
     */
    private ScopeConfigInterface $scopeConfig;

    protected function setUp(): void
    {
        $args = $this->readerArguments();
        $this->basePath = $args['basePath'];

        $this->scopeConfig = $this->createStub(ScopeConfigInterface::class);
        $this->scopeConfig->method('getValue')->willReturnCallback(
            function (string $path, string $scopeType, $scopeCode) {
                $this->calls[] = [$path, $scopeType, $scopeCode];

                return $this->values[$path] ?? null;
            }
        );

        $reader = new GenericOidcConfig($this->scopeConfig, $args['basePath'], $args['scopeType']);
        $this->preset = new GenericOidcPreset($reader, new GenericOidcConfigParser(new UrlPolicy()));
        $this->registry = new PresetRegistry(['generic' => $this->preset]);
    }

    public function testRegistryHoldsPresetUnderGeneric(): void
    {
        self::assertTrue($this->registry->has('generic'));
        self::assertSame($this->preset, $this->registry->get('generic'));
    }

    public function testActiveProviderResolverReturnsGenericPreset(): void
    {
        $this->values[ActiveProviderResolver::XML_PATH_ACTIVE_PROVIDER] = 'generic';
        $resolver = new ActiveProviderResolver($this->scopeConfig, $this->registry);

        self::assertSame($this->preset, $resolver->getActive());
    }

    public function testReadsStoreScopedValuesUnderGenericGroup(): void
    {
        $this->set('issuer_url', 'https://kc.example/realms/shop/');
        $this->set('display_name', 'Keycloak');
        $this->set('scopes', 'openid email');
        $this->set('groups_claim', 'groups');
        $this->set('extra_auth_params', 'prompt=login');

        self::assertSame(
            'https://kc.example/realms/shop/.well-known/openid-configuration',
            $this->preset->buildDiscoveryUrl(['client_id' => 'x'])
        );
        self::assertSame('Keycloak', $this->preset->getLabel());
        self::assertSame(['openid', 'email'], $this->preset->getDefaultScopes());
        self::assertSame('groups', $this->preset->getGroupsClaim());
        self::assertSame(['prompt' => 'login'], $this->preset->getAuthorizationParams(['client_id' => 'x']));

        $paths = [];
        foreach ($this->calls as [$path, $scopeType, $scopeCode]) {
            self::assertSame('store', $scopeType);
            self::assertNull($scopeCode);
            $paths[$path] = true;
        }
        self::assertSame(
            array_map(
                static fn (string $field): string => 'dmlab_customer_sso/generic/' . $field,
                ['issuer_url', 'display_name', 'scopes', 'groups_claim', 'extra_auth_params']
            ),
            array_keys($paths)
        );
    }

    public function testButtonLabelUsesDisplayName(): void
    {
        $this->set('display_name', 'Keycloak');

        self::assertSame('Keycloak', $this->preset->getLabel());
        self::assertSame('Sign in with Keycloak', $this->preset->getButtonLabel());
    }

    public function testUnsetDisplayNameFallsBackToSso(): void
    {
        self::assertSame('SSO', $this->preset->getLabel());
    }

    public function testEmptyGroupsClaimDisablesGroupMapping(): void
    {
        $this->set('groups_claim', '');

        self::assertNull($this->preset->getGroupsClaim());
    }

    private function set(string $field, string $value): void
    {
        $this->values[$this->basePath . '/' . $field] = $value;
    }

    /**
     * @return array<string, string>
     */
    private function readerArguments(): array
    {
        $xml = simplexml_load_file(dirname(__DIR__, 4) . '/etc/di.xml');
        self::assertNotFalse($xml);
        $types = $xml->xpath('/config/virtualType[@name="DmLab\CustomerSso\Model\Preset\GenericOidcConfig"]');
        self::assertCount(1, $types);

        $args = [];
        foreach ($types[0]->arguments->argument as $argument) {
            $args[(string)$argument['name']] = trim((string)$argument);
        }

        return $args;
    }
}
