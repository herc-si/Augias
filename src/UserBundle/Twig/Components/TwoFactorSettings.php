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

namespace Augias\UserBundle\Twig\Components;

use Augias\UserBundle\Entity\User;
use Augias\UserBundle\Repository\UserRepository;
use Augias\UserBundle\Security\AccountSecurity;
use Augias\UserBundle\Security\AuthenticatorAppPolicy;
use Carbon\Carbon;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\RoundBlockSizeMode;
use Endroid\QrCode\Writer\PngWriter;
use Override;
use Scheb\TwoFactorBundle\Security\TwoFactor\Provider\Totp\TotpAuthenticatorInterface;
use Scheb\TwoFactorBundle\Security\TwoFactor\Trusted\TrustedDeviceManagerInterface;
use Scheb\TwoFactorBundle\Security\TwoFactor\Trusted\TrustedDeviceTokenStorage;
use SolidWorx\Platform\PlatformBundle\Contracts\Security\TwoFactor\UserTwoFactorInterface;
use SolidWorx\Platform\PlatformBundle\Form\Type\Security\TwoFactorVerifyType;
use SolidWorx\Platform\PlatformBundle\Security\TwoFactor\BackupCodeGeneratorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\ComponentToolsTrait;
use Symfony\UX\LiveComponent\ComponentWithFormTrait;
use Symfony\UX\LiveComponent\DefaultActionTrait;
use Symfony\UX\TwigComponent\Attribute\ExposeInTemplate;
use Symfony\UX\TwigComponent\Attribute\PreMount;
use function assert;

/**
 * @see \Augias\UserBundle\Tests\Twig\Components\TwoFactorSettingsTest
 */
#[AsLiveComponent(name: 'User:TwoFactorSettings', template: '@AugiasUser/Components/TwoFactorSettings.html.twig')]
final class TwoFactorSettings extends AbstractController
{
    use DefaultActionTrait;
    use ComponentWithFormTrait;
    use ComponentToolsTrait;

    #[LiveProp]
    public string $totpSecret;

    #[LiveProp(writable: true)]
    public bool $showBackupCodes = false;

    #[LiveProp(writable: true)]
    public bool $showQrModal = false;

    /**
     * Said on the page itself: a flash message would only show on the next
     * page load.
     */
    #[LiveProp]
    public bool $trustedDevicesForgotten = false;

    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly TotpAuthenticatorInterface $totpAuthenticator,
        #[Autowire(service: 'scheb_two_factor.trusted_token_storage')]
        private readonly TrustedDeviceTokenStorage $trustedDeviceTokenStorage,
        #[Autowire(service: 'scheb_two_factor.default_trusted_device_manager')]
        private readonly TrustedDeviceManagerInterface $trustedDeviceManager,
        private readonly BackupCodeGeneratorInterface $backupCodeGenerator,
        private readonly TokenStorageInterface $tokenStorage,
        private readonly AuthenticatorAppPolicy $authenticatorAppPolicy,
        private readonly AccountSecurity $accountSecurity,
    ) {
    }

    #[PreMount]
    public function preMount(): void
    {
        $this->totpSecret = $this->totpAuthenticator->generateSecret();
    }

    #[LiveAction]
    public function enableEmailAuth(): void
    {
        $user = $this->getUser();
        $user->enableEmailAuth(true);

        if ($user->getBackupCodes() === []) {
            $this->regenerateBackupCodes();
        }

        $this->userRepository->save($user);
        $this->refreshSecurityToken($user);
    }

    #[LiveAction]
    public function disableEmailAuth(): void
    {
        $user = $this->getUser();
        $user->enableEmailAuth(false);

        if (! $user->is2FaEnabled()) {
            $user->setBackUpCodes([]);
        }

        $this->userRepository->save($user);
        $this->refreshSecurityToken($user);
    }

    #[ExposeInTemplate]
    public function getQrContent(): string
    {
        $user = $this->getUser();

        if (! $user->isTotpAuthenticationEnabled()) {
            $user = clone $user;
            $user->setTotpSecret($this->totpSecret);
        }

        $qrContent = $this->totpAuthenticator->getQRContent($user);

        $builder = new Builder(
            writer: new PngWriter(),
            writerOptions: [],
            validateResult: false,
            data: $qrContent,
            encoding: new Encoding('UTF-8'),
            errorCorrectionLevel: ErrorCorrectionLevel::High,
            size: 300,
            margin: 10,
            roundBlockSizeMode: RoundBlockSizeMode::Margin,
        );

        return $builder->build()->getDataUri();
    }

    #[LiveAction]
    public function enableTOTPAuth(): void
    {
        $this->submitForm();

        $data = $this->getForm()->getData();

        $secret = $data['secret'] ?? $this->totpSecret;

        $user = $this->getUser();
        assert($user instanceof UserTwoFactorInterface);

        $user->setTotpSecret($secret);
        if ($user->getBackupCodes() === []) {
            $this->regenerateBackupCodes();
        }

        $this->userRepository->save($user);
        $this->refreshSecurityToken($user);

        $this->showQrModal = false;
        $this->dispatchBrowserEvent('modal:close');
    }

    #[LiveAction]
    public function disableTOTPAuth(): void
    {
        $user = $this->getUser();
        assert($user instanceof UserTwoFactorInterface);

        // Required here: the app can be replaced (set up again), not removed.
        if ($this->authenticatorAppPolicy->isRequired()) {
            $this->addFlash('error', 'security.two_factor.required.cannot_disable');

            return;
        }

        $user->setTotpSecret('');

        if (! $user->is2FaEnabled()) {
            $user->setBackUpCodes([]);
        }

        $this->userRepository->save($user);
        $this->refreshSecurityToken($user);
    }

    #[LiveAction]
    public function regenerateBackupCodes(): void
    {
        $user = $this->getUser();
        assert($user instanceof UserTwoFactorInterface);

        $codes = $this->generateBackupCodes();
        $user->setBackUpCodes($codes);
        // Store plain text codes for download

        $this->userRepository->save($user);
        $this->refreshSecurityToken($user);

        // Keep modal open
        $this->showBackupCodes = true;
    }

    #[LiveAction]
    public function downloadBackupCodes(): void
    {
        // Use the stored plain text codes instead of fetching from user
        // (which may return hashed codes or be stale)
        $codes = $this->getUser()->getBackupCodes();

        $content = "Augias - Two-Factor Authentication Backup Codes\n";
        $content .= 'Generated: ' . Carbon::now()->format('Y-m-d H:i:s') . "\n";
        $content .= str_repeat('=', 50) . "\n\n";

        foreach ($codes as $i => $code) {
            $content .= sprintf("%2d. %s\n", $i + 1, $code);
        }

        $content .= "\n" . str_repeat('=', 50) . "\n";
        $content .= "IMPORTANT: Store these codes securely.\n";
        $content .= "Each code can only be used once.\n";

        $this->dispatchBrowserEvent('download:file', [
            'content' => $content,
            'filename' => 'augias-backup-codes-' . Carbon::now()->format('Y-m-d') . '.txt',
            'type' => 'text/plain',
        ]);

        // Keep modal open after download
        $this->showBackupCodes = true;
    }

    #[ExposeInTemplate]
    public function isDeviceTrusted(): bool
    {
        $user = $this->getUser();
        assert($user instanceof UserTwoFactorInterface);

        return $this->trustedDeviceManager->isTrustedDevice($user, 'main');
    }

    #[LiveAction]
    public function clearTrustedDevice(): void
    {
        $user = $this->getUser();
        assert($user instanceof UserInterface);

        $this->trustedDeviceTokenStorage->clearTrustedToken($user->getUserIdentifier(), 'main');
    }

    #[LiveAction]
    public function forgetAllTrustedDevices(): void
    {
        $user = $this->getUser();

        $this->accountSecurity->forgetTrustedDevices($user);
        $this->trustedDeviceTokenStorage->clearTrustedToken($user->getUserIdentifier(), 'main');

        $this->trustedDevicesForgotten = true;
    }

    #[Override]
    /**
     * @return FormInterface<array{code: string|null, secret: string|null}>
     */
    protected function instantiateForm(): FormInterface
    {
        return $this->createForm(TwoFactorVerifyType::class, [
            'secret' => $this->totpSecret,
        ], [
            'secret' => $this->totpSecret,
        ]);
    }

    /**
     * @return list<string>
     */
    private function generateBackupCodes(int $number = BackupCodeGeneratorInterface::LIMIT): array
    {
        return $this->backupCodeGenerator->generateBackupCodes($number);
    }

    /**
     * Refresh the security token with the updated user entity
     * This ensures that app.user in templates reflects the latest changes
     */
    private function refreshSecurityToken(UserInterface $user): void
    {
        $token = $this->tokenStorage->getToken();

        if (! $token instanceof TokenInterface) {
            return;
        }

        $newToken = new UsernamePasswordToken(
            $user,
            'main', // firewall name
            $token->getRoleNames()
        );

        $this->tokenStorage->setToken($newToken);
    }

    #[Override]
    protected function getUser(): User
    {
        $user = parent::getUser();
        assert($user instanceof User);

        return $user;
    }
}
