<?php

declare(strict_types=1);

namespace Hexis\SystemEmailBundle\Form;

use Hexis\SystemEmailBundle\Entity\SystemEmailRecipient;
use Hexis\SystemEmailBundle\Enum\RecipientType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class RecipientFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'Name',
            ])
            ->add('email', EmailType::class, [
                'label' => 'Email address',
            ])
            ->add('recipientType', ChoiceType::class, [
                'label' => 'Type',
                'choices' => RecipientType::choices(),
            ])
            ->add('active', CheckboxType::class, [
                'label' => 'Active',
                'required' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => SystemEmailRecipient::class,
            'translation_domain' => 'system_email',
        ]);
    }
}
