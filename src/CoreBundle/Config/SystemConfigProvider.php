<?php

declare(strict_types=1);

/*
 * This file is part of Augias project.
 *
 * (c) Pierre du Plessis <open-source@solidworx.co>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Augias\CoreBundle\Config;

use Augias\CoreBundle\Company\CompanyBankDetails;
use Augias\CoreBundle\Form\Type\BicType;
use Augias\CoreBundle\Form\Type\IbanType;
use Augias\CoreBundle\Form\Type\ImageUploadType;
use Augias\CoreBundle\Form\Type\LocaleType;
use Augias\MoneyBundle\Form\Type\CurrencyType;
use Augias\SettingsBundle\Config\ProviderInterface;
use Augias\SettingsBundle\DTO\Config;
use Augias\SettingsBundle\Form\Type\AddressType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TextType;

final class SystemConfigProvider implements ProviderInterface
{
    /**
     * @return Config[]
     */
    public function provide(array $data): array
    {
        return [
            new Config('system/company/logo', null, null, ImageUploadType::class),
            new Config('system/company/company_name', $data['company_name'] ?? null, null, TextType::class),
            new Config('system/company/contact_details/address', null, null, AddressType::class),
            new Config('system/company/contact_details/email', null, null, EmailType::class),
            new Config('system/company/contact_details/phone_number', null, null, TextType::class),
            new Config('system/company/currency', $data['currency'] ?? null, null, CurrencyType::class),
            new Config('system/company/locale', $data['locale'] ?? 'en', null, LocaleType::class),
            new Config(CompanyBankDetails::BANK_NAME, null, null, TextType::class, ['attr' => ['maxlength' => 100]]),
            new Config(CompanyBankDetails::IBAN, null, null, IbanType::class),
            new Config(CompanyBankDetails::BIC, null, null, BicType::class),
        ];
    }
}
