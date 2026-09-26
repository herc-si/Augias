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

use Augias\UserBundle\Action\AcceptInvitation;
use Augias\UserBundle\Action\ApiIndex;
use Augias\UserBundle\Action\CompanyLoginHistory;
use Augias\UserBundle\Action\DeleteUserInvite;
use Augias\UserBundle\Action\EditProfile;
use Augias\UserBundle\Action\ForgotPassword\Check;
use Augias\UserBundle\Action\ForgotPassword\Request;
use Augias\UserBundle\Action\ForgotPassword\Reset;
use Augias\UserBundle\Action\InviteUser;
use Augias\UserBundle\Action\LoginHistory;
use Augias\UserBundle\Action\Member\ChangeMemberRole;
use Augias\UserBundle\Action\Member\LeaveCompany;
use Augias\UserBundle\Action\Member\RemoveMember;
use Augias\UserBundle\Action\Member\TransferOwnership;
use Augias\UserBundle\Action\Notifications;
use Augias\UserBundle\Action\Profile;
use Augias\UserBundle\Action\Register;
use Augias\UserBundle\Action\ResendUserInvite;
use Augias\UserBundle\Action\Security\ChangePassword;
use Augias\UserBundle\Action\Security\OAuthConnect;
use Augias\UserBundle\Action\Security\OAuthConnectCheck;
use Augias\UserBundle\Action\Security\TwoFactorIndex;
use Augias\UserBundle\Action\Security\VerifyEmail;
use Augias\UserBundle\Action\Users;
use Augias\UserBundle\Onboarding\Action\Onboarding;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

return static function (RoutingConfigurator $routingConfigurator): void {
    $routingConfigurator
        ->add('_api_keys_index', '/profile/api')
        ->controller(ApiIndex::class);

    // The account's own sign-ins, under the profile: it is about the person,
    // not about the company they happen to be looking at.
    $routingConfigurator
        ->add('_login_history', '/profile/sign-ins')
        ->controller(LoginHistory::class)
        ->methods(['GET']);

    $routingConfigurator
        ->add('_users_list', '/users')
        ->controller(Users::class);

    // Everyone's, scoped to the company in play — see CompanyLoginHistory.
    $routingConfigurator
        ->add('_users_login_history', '/users/sign-ins')
        ->controller(CompanyLoginHistory::class)
        ->methods(['GET']);

    $routingConfigurator
        ->add('_user_invite', '/users/invite')
        ->controller(InviteUser::class);

    $routingConfigurator
        ->add('_user_resend_invite', '/users/invite/{id}/resend')
        ->controller(ResendUserInvite::class);

    $routingConfigurator
        ->add('_user_delete_invite', '/users/invite/{id}/delete')
        ->controller(DeleteUserInvite::class);

    $routingConfigurator
        ->add('_member_role', '/users/members/{id}/role')
        ->controller(ChangeMemberRole::class)
        ->methods(['POST']);

    $routingConfigurator
        ->add('_member_remove', '/users/members/{id}/remove')
        ->controller(RemoveMember::class)
        ->methods(['POST']);

    $routingConfigurator
        ->add('_member_transfer_ownership', '/users/members/{id}/make-owner')
        ->controller(TransferOwnership::class)
        ->methods(['POST']);

    $routingConfigurator
        ->add('_member_leave', '/users/leave')
        ->controller(LeaveCompany::class)
        ->methods(['POST']);

    $routingConfigurator
        ->add('_user_accept_invite', '/invite/accept/{id}')
        ->controller(AcceptInvitation::class);

    $routingConfigurator
        ->add('_register', '/register')
        ->controller(Register::class);

    $routingConfigurator
        ->add('_onboarding', '/onboarding')
        ->controller(Onboarding::class);

    $routingConfigurator->add('_logout', '/logout');

    $routingConfigurator
        ->add('_user_forgot_password', '/forgot-password')
        ->controller(Request::class);

    $routingConfigurator
        ->add('_user_forgot_password_check_email', '/forgot-password/check')
        ->controller(Check::class);

    $routingConfigurator
        ->add('_user_password_reset', '/forgot-password/reset/{token}')
        ->defaults(['token' => null])
        ->controller(Reset::class);

    $routingConfigurator
        ->add('_profile', '/profile')
        ->controller(Profile::class);

    $routingConfigurator
        ->add('_edit_profile', '/profile/edit')
        ->controller(EditProfile::class);

    $routingConfigurator
        ->add('_change_password', '/profile/change-password')
        ->controller(ChangePassword::class);

    $routingConfigurator
        ->add('_profile_notifications', '/profile/notifications')
        ->controller(Notifications::class);

    $routingConfigurator
        ->add('_verify_email', '/verify')
        ->controller(VerifyEmail::class);

    $routingConfigurator->add(OAuthConnect::ROUTE, '/oauth/connect/{service}')
        ->controller(OAuthConnect::class);

    $routingConfigurator->add(OAuthConnectCheck::ROUTE, '/oauth/check/{service}')
        ->controller(OAuthConnectCheck::class);

    $routingConfigurator->add('_2fa_list', '/profile/2fa')
        ->controller(TwoFactorIndex::class);
};
