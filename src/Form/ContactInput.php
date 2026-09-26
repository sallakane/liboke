<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Données saisies dans le formulaire de contact.
 *
 * Objet de transfert : il porte la validation, l'entité ContactMessage n'est
 * construite qu'une fois les données jugées valides.
 */
final class ContactInput
{
    #[Assert\NotBlank(message: 'Merci d\'indiquer votre nom.')]
    #[Assert\Length(max: 120, maxMessage: 'Votre nom ne peut pas dépasser {{ limit }} caractères.')]
    public string $name = '';

    #[Assert\NotBlank(message: 'Merci d\'indiquer votre adresse e-mail.')]
    #[Assert\Email(message: 'Cette adresse e-mail n\'est pas valide.')]
    #[Assert\Length(max: 180)]
    public string $email = '';

    #[Assert\NotBlank(message: 'Merci d\'indiquer un objet.')]
    #[Assert\Length(max: 180, maxMessage: 'L\'objet ne peut pas dépasser {{ limit }} caractères.')]
    public string $subject = '';

    #[Assert\NotBlank(message: 'Merci d\'écrire votre message.')]
    #[Assert\Length(
        min: 20,
        max: 5000,
        minMessage: 'Votre message doit faire au moins {{ limit }} caractères.',
        maxMessage: 'Votre message ne peut pas dépasser {{ limit }} caractères.',
    )]
    public string $message = '';

    // Facultatif : la réponse au message repose sur l'intérêt légitime de
    // l'association, pas sur ce consentement (politique de confidentialité).
    public bool $consent = false;
}
