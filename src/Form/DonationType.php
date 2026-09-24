<?php

declare(strict_types=1);

namespace App\Form;

use App\Stripe\DonationAmounts;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<DonationInput>
 */
final class DonationType extends AbstractType
{
    public function __construct(private readonly DonationAmounts $amounts)
    {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $choix = [];
        foreach ($this->amounts->suggestions as $centimes) {
            $choix[$this->amounts->toEuros($centimes).' €'] = (string) $centimes;
        }
        $choix['Autre montant'] = DonationInput::LIBRE;

        $builder
            // Boutons radio : le choix d'un montant suggéré fonctionne sans
            // la moindre ligne de JavaScript (CLAUDE.md §2).
            ->add('preset', ChoiceType::class, [
                'label' => 'Montant du don',
                'choices' => $choix,
                'expanded' => true,
                'multiple' => false,
                'placeholder' => false,
            ])
            ->add('custom', IntegerType::class, [
                'label' => 'Autre montant, en euros',
                'required' => false,
                'attr' => [
                    'min' => (int) ceil($this->amounts->minimum / 100),
                    'max' => (int) floor($this->amounts->maximum / 100),
                    'inputmode' => 'numeric',
                ],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => DonationInput::class,
            'attr' => ['novalidate' => 'novalidate'],
        ]);
    }
}
