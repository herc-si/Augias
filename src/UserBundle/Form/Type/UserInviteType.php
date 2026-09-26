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

namespace Augias\UserBundle\Form\Type;

use Augias\UserBundle\Entity\UserInvitation;
use Augias\UserBundle\Enum\CompanyRole;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use function array_filter;
use function array_values;

/**
 * @extends AbstractType<UserInvitation>
 */
final class UserInviteType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('email', EmailType::class, [
                'label' => 'form.field.email', 'required' => true]);

        // Only the roles the person inviting may give, and never owner: the
        // company changes hands by being handed over, not by an invitation.
        $builder->add('role', EnumType::class, [
            'class' => CompanyRole::class,
            'choices' => array_values(array_filter($options['assignable_roles'], static fn (CompanyRole $role): bool => CompanyRole::Owner !== $role)),
            'choice_label' => static fn (CompanyRole $role): string => $role->labelKey(),
            'expanded' => true,
            'label' => 'users.invite.role.label',
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefault('data_class', UserInvitation::class);
        $resolver->setDefault('assignable_roles', [CompanyRole::Billing, CompanyRole::Accountant]);
        $resolver->setAllowedTypes('assignable_roles', 'array');
    }
}
