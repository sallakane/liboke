<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<ContactInput>
 */
final class ContactType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var array{ts: string, signature: string} $timer */
        $timer = $options['timer'];

        $builder
            ->add('name', TextType::class, [
                'label' => 'Votre nom',
                // Un champ vide arrive à null : sans empty_data, l'affectation
                // à une propriété typée `string` lèverait une exception.
                'empty_data' => '',
                'attr' => ['autocomplete' => 'name', 'maxlength' => 120],
            ])
            ->add('email', EmailType::class, [
                'label' => 'Votre adresse e-mail',
                'empty_data' => '',
                'attr' => ['autocomplete' => 'email', 'maxlength' => 180],
            ])
            ->add('subject', TextType::class, [
                'label' => 'Objet',
                'empty_data' => '',
                'attr' => ['maxlength' => 180],
            ])
            ->add('message', TextareaType::class, [
                'label' => 'Votre message',
                'empty_data' => '',
                'attr' => ['rows' => 8, 'maxlength' => 5000],
            ])
            ->add('consent', CheckboxType::class, [
                'label' => 'J\'accepte que mon message et mes coordonnées soient conservés pour permettre à l\'association de me répondre.',
                'required' => true,
            ])
            // Piège à robots : masqué visuellement et aux lecteurs d'écran,
            // il ne doit jamais être rempli par un humain.
            ->add('website', TextType::class, [
                'mapped' => false,
                'required' => false,
                'label' => false,
                'attr' => [
                    'autocomplete' => 'off',
                    'tabindex' => '-1',
                    'aria-hidden' => 'true',
                ],
            ])
            ->add('ts', HiddenType::class, ['mapped' => false, 'data' => $timer['ts']])
            ->add('signature', HiddenType::class, ['mapped' => false, 'data' => $timer['signature']])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ContactInput::class,
            'attr' => ['novalidate' => 'novalidate'],
        ]);

        $resolver->setRequired('timer');
        $resolver->setAllowedTypes('timer', 'array');
    }
}
