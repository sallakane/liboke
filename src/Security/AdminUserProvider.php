<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;

/**
 * L'unique compte administrateur, déclaré en variables d'environnement
 * (CLAUDE.md §10) : aucune table `user`, aucune inscription, aucune
 * réinitialisation de mot de passe.
 *
 * Le fournisseur `memory` de Symfony n'accepte pas une variable
 * d'environnement comme identifiant, d'où cette classe.
 *
 * Tant que l'une des deux variables est vide, personne ne peut se connecter :
 * c'est l'état par défaut d'une installation neuve.
 *
 * @implements UserProviderInterface<InMemoryUser>
 */
final readonly class AdminUserProvider implements UserProviderInterface
{
    public const string ROLE = 'ROLE_ADMIN';

    public function __construct(
        #[Autowire(env: 'ADMIN_USERNAME')]
        private string $username,
        #[Autowire(env: 'ADMIN_PASSWORD_HASH')]
        private string $passwordHash,
    ) {
    }

    public function loadUserByIdentifier(string $identifier): UserInterface
    {
        if ('' === $this->username || '' === $this->passwordHash || !hash_equals($this->username, $identifier)) {
            $exception = new UserNotFoundException();
            $exception->setUserIdentifier($identifier);

            throw $exception;
        }

        return new InMemoryUser($this->username, $this->passwordHash, [self::ROLE]);
    }

    /**
     * Relu à chaque requête : changer le hash en environnement ferme les
     * sessions ouvertes avec l'ancien mot de passe.
     */
    public function refreshUser(UserInterface $user): UserInterface
    {
        if (!$user instanceof InMemoryUser) {
            throw new UnsupportedUserException(\sprintf('Utilisateur de type « %s » non géré.', $user::class));
        }

        return $this->loadUserByIdentifier($user->getUserIdentifier());
    }

    public function supportsClass(string $class): bool
    {
        return InMemoryUser::class === $class;
    }
}
