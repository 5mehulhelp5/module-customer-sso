<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CustomerSso\Test\Unit\Model\Config\Source;

use DmLab\CustomerSso\Model\Config\Source\ActiveProvider;
use DmLab\CustomerSso\Model\PresetRegistry;
use DmLab\SsoCore\Api\ProviderPresetInterface;
use PHPUnit\Framework\TestCase;

class ActiveProviderTest extends TestCase
{
    private function preset(string $code, string $label): ProviderPresetInterface
    {
        $preset = $this->createStub(ProviderPresetInterface::class);
        $preset->method('getCode')->willReturn($code);
        $preset->method('getLabel')->willReturn($label);

        return $preset;
    }

    public function testFirstOptionIsAlwaysAnEmptyPlaceholder(): void
    {
        $source = new ActiveProvider(new PresetRegistry([]));

        $options = $source->toOptionArray();
        self::assertCount(1, $options);
        self::assertSame('', $options[0]['value']);
    }

    public function testBuildsOneOptionPerRegisteredPreset(): void
    {
        $source = new ActiveProvider(new PresetRegistry([
            $this->preset('okta', 'Okta'),
            $this->preset('google', 'Google'),
        ]));

        $options = $source->toOptionArray();

        self::assertSame('', $options[0]['value']);
        self::assertSame(['value' => 'okta', 'label' => 'Okta'], self::normalize($options[1]));
        self::assertSame(['value' => 'google', 'label' => 'Google'], self::normalize($options[2]));
    }

    public function testLabelOverrideAppliesOnlyToItsCode(): void
    {
        $source = new ActiveProvider(
            new PresetRegistry([$this->preset('okta', 'Okta'), $this->preset('generic', 'Keycloak')]),
            ['generic' => __('Generic OIDC')]
        );

        $options = $source->toOptionArray();

        self::assertCount(3, $options);
        self::assertSame(['value' => 'okta', 'label' => 'Okta'], self::normalize($options[1]));
        self::assertSame(['value' => 'generic', 'label' => 'Generic OIDC'], self::normalize($options[2]));
    }

    public function testOverrideForUnregisteredCodeAddsNoOption(): void
    {
        $source = new ActiveProvider(
            new PresetRegistry([$this->preset('okta', 'Okta')]),
            ['generic' => 'Generic OIDC']
        );

        $options = $source->toOptionArray();

        self::assertCount(2, $options);
        self::assertSame(['value' => 'okta', 'label' => 'Okta'], self::normalize($options[1]));
    }

    public function testEmptyOverridesKeepPresetLabels(): void
    {
        $registry = new PresetRegistry([$this->preset('generic', 'SSO')]);

        self::assertEquals(
            (new ActiveProvider($registry))->toOptionArray(),
            (new ActiveProvider($registry, []))->toOptionArray()
        );
        self::assertSame(
            ['value' => 'generic', 'label' => 'SSO'],
            self::normalize((new ActiveProvider($registry, []))->toOptionArray()[1])
        );
    }

    private static function normalize(array $option): array
    {
        return ['value' => $option['value'], 'label' => (string)$option['label']];
    }
}
