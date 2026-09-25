<?php

declare(strict_types=1);

/*
 * This file is part of Augias project.
 *
 * (c) HERC SI <opensource@herc-si.fr>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Augias\ElectronicInvoicingBundle\Command;

use Augias\ElectronicInvoicingBundle\Entity\ElectronicInvoiceProviderSetting;
use Augias\ElectronicInvoicingBundle\Manager\ElectronicInvoiceAccountMonitor;
use Augias\ElectronicInvoicingBundle\Repository\ElectronicInvoiceProviderSettingRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use SolidWorx\Platform\PlatformBundle\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Scheduler\Attribute\AsCronTask;
use Throwable;
use function assert;
use function sprintf;

/**
 * Asks each platform, every hour, whether it still recognises the account a
 * company uses — and records the answer, which is what switches electronic
 * invoicing on or off for that company.
 *
 * Both ways: an account the platform has just verified starts sending on its
 * own, with nobody having to come back and save the settings again; one it
 * puts back under review, or refuses, stops before an invoice is sent into a
 * wall.
 *
 * @see \Augias\ElectronicInvoicingBundle\Tests\Command\CheckElectronicInvoiceAccountsCommandTest
 */
#[AsCommand(
    name: 'augias:einvoicing:check-accounts',
    description: 'Ask each e-invoicing platform whether it has verified the account in use',
)]
#[AsCronTask('#hourly', schedule: 'check_electronic_invoice_accounts')]
final class CheckElectronicInvoiceAccountsCommand extends Command
{
    public function __construct(
        private readonly ManagerRegistry $registry,
        private readonly ElectronicInvoiceProviderSettingRepository $settings,
        private readonly ElectronicInvoiceAccountMonitor $monitor,
    ) {
        parent::__construct();
    }

    protected function handle(): int
    {
        $entityManager = $this->registry->getManagerForClass(ElectronicInvoiceProviderSetting::class);
        assert($entityManager instanceof EntityManagerInterface);

        $filters = $entityManager->getFilters();
        $companyFilterEnabled = $filters->isEnabled('company');

        if ($companyFilterEnabled) {
            $filters->disable('company');
        }

        $usable = 0;
        $held = 0;
        $unreachable = 0;

        try {
            foreach ($this->settings->findSwitchedOn() as $setting) {
                try {
                    $status = $this->monitor->check($setting);
                } catch (Throwable $e) {
                    ++$unreachable;
                    $this->io->error(sprintf('Could not check the account of setting %s: %s', (string) $setting->getId(), $e->getMessage()));

                    continue;
                }

                if (null === $status) {
                    continue;
                }

                if (! $status->answered) {
                    ++$unreachable;
                } elseif ($status->verification->isUsable()) {
                    ++$usable;
                } else {
                    ++$held;
                }
            }

            $entityManager->flush();
        } finally {
            if ($companyFilterEnabled) {
                $filters->enable('company');
            }
        }

        $this->io->success(sprintf('Accounts verified: %d, held by the platform: %d, unreachable: %d', $usable, $held, $unreachable));

        return self::SUCCESS;
    }
}
