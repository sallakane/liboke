<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\ContactMessageRepository;
use DateTimeImmutable;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Purge RGPD des messages de contact (CLAUDE.md §11).
 *
 * À planifier une fois par jour en production.
 */
#[AsCommand(
    name: 'app:purge-contact-messages',
    description: 'Supprime les messages de contact de plus de douze mois.',
)]
final class PurgeContactMessagesCommand extends Command
{
    public function __construct(
        private readonly ContactMessageRepository $messages,
        private readonly int $retentionMois = 12,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Compte les messages concernés sans rien supprimer.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $limite = new DateTimeImmutable(\sprintf('-%d months', $this->retentionMois));

        if (true === $input->getOption('dry-run')) {
            $io->info(\sprintf(
                '%d message(s) antérieur(s) au %s seraient supprimés.',
                $this->messages->countOlderThan($limite),
                $limite->format('d/m/Y'),
            ));

            return Command::SUCCESS;
        }

        $supprimes = $this->messages->deleteOlderThan($limite);

        $io->success(\sprintf(
            '%d message(s) de contact antérieur(s) au %s supprimé(s).',
            $supprimes,
            $limite->format('d/m/Y'),
        ));

        return Command::SUCCESS;
    }
}
