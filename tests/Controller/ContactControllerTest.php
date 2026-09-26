<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\ContactMessage;
use App\Repository\ContactMessageRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Mime\Email;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

final class ContactControllerTest extends WebTestCase
{
    /**
     * Le secret de test, pour forger un horodatage signé antidaté plutôt que
     * d'attendre réellement le délai minimum de trois secondes.
     */
    private const string SECRET_TEST = '$ecretf0rt3st';

    public function testFormIsDisplayedWithAccessibleLabels(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/contact');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('form.formulaire'));

        foreach (['name', 'email', 'subject', 'message', 'consent'] as $champ) {
            self::assertCount(
                1,
                $crawler->filter(\sprintf('label[for="contact_%s"]', $champ)),
                \sprintf('Le champ %s doit avoir un libellé associé.', $champ),
            );
        }

        // Le contenu Markdown de la page reste affiché au-dessus du formulaire.
        self::assertCount(1, $crawler->filter('.page__contenu'));
    }

    public function testValidSubmissionPersistsTheMessageAndQueuesAnEmail(): void
    {
        $client = static::createClient();
        $this->purge($client);

        $crawler = $client->request('GET', '/contact');
        $client->submit($this->remplir($crawler));

        self::assertResponseRedirects('/contact');

        // L'envoi passe par Messenger (CLAUDE.md §9) : le courriel est mis en
        // file, pas expédié dans le cycle de la requête. À vérifier avant de
        // suivre la redirection, qui redémarre le noyau et vide le journal.
        self::assertQueuedEmailCount(1);
        $email = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $email);
        self::assertStringContainsString('Une question sur vos actions', (string) $email->getSubject());

        $client->followRedirect();
        self::assertSelectorTextContains('.alerte--succes', 'a bien été envoyé');

        $messages = $this->messages($client)->findAll();
        self::assertCount(1, $messages);
        self::assertSame('Claire Dupont', $messages[0]->getName());
        self::assertSame('claire@example.org', $messages[0]->getEmail());
        self::assertTrue($messages[0]->hasConsent());
    }

    public function testTheEmailRepliesToTheSender(): void
    {
        $client = static::createClient();
        $this->purge($client);

        $crawler = $client->request('GET', '/contact');
        $client->submit($this->remplir($crawler));

        $email = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $email);

        $replyTo = $email->getHeaders()->get('Reply-To');
        self::assertNotNull($replyTo);
        self::assertStringContainsString('claire@example.org', $replyTo->getBodyAsString());
    }

    public function testTheEmailGoesToEveryConfiguredRecipient(): void
    {
        // CONTACT_TO accepte plusieurs adresses séparées par des virgules.
        $precedent = $_SERVER['CONTACT_TO'] ?? null;
        $_SERVER['CONTACT_TO'] = $_ENV['CONTACT_TO'] = 'a@example.org, b@example.org';

        try {
            $client = static::createClient();
            $this->purge($client);

            $crawler = $client->request('GET', '/contact');
            $client->submit($this->remplir($crawler));

            $email = self::getMailerMessage();
            self::assertInstanceOf(Email::class, $email);
            self::assertSame(
                ['a@example.org', 'b@example.org'],
                array_map(static fn ($adresse) => $adresse->getAddress(), $email->getTo()),
            );
        } finally {
            if (null === $precedent) {
                unset($_SERVER['CONTACT_TO'], $_ENV['CONTACT_TO']);
            } else {
                $_SERVER['CONTACT_TO'] = $_ENV['CONTACT_TO'] = $precedent;
            }
        }
    }

    public function testInvalidSubmissionShowsErrorsAndStoresNothing(): void
    {
        $client = static::createClient();
        $this->purge($client);

        $crawler = $client->request('GET', '/contact');
        $formulaire = $this->remplir($crawler, ['contact[name]' => '', 'contact[email]' => 'pas-une-adresse']);
        $crawler = $client->submit($formulaire);

        // Depuis Symfony 6.2, render() répond 422 quand un formulaire soumis
        // est invalide.
        self::assertResponseStatusCodeSame(422);
        self::assertGreaterThan(0, $crawler->filter('.champ__erreur')->count());

        // Chaque message d'erreur est rattaché à son champ.
        $erreur = $crawler->filter('.champ--erreur input')->first();
        self::assertSame('true', $erreur->attr('aria-invalid'));
        self::assertNotNull($erreur->attr('aria-describedby'));

        self::assertCount(0, $this->messages($client)->findAll());
        self::assertQueuedEmailCount(0);
    }

    public function testMissingConsentIsRejected(): void
    {
        $client = static::createClient();
        $this->purge($client);

        $crawler = $client->request('GET', '/contact');
        $formulaire = $this->remplir($crawler);
        $formulaire->remove('contact[consent]');
        $client->submit($formulaire);

        self::assertResponseStatusCodeSame(422);
        self::assertCount(0, $this->messages($client)->findAll());
    }

    /**
     * Le piège rempli doit produire exactement la même page qu'un envoi
     * réussi : rien ne doit apprendre au robot que le champ est surveillé.
     */
    public function testHoneypotSubmissionLooksSuccessfulButStoresNothing(): void
    {
        $client = static::createClient();
        $this->purge($client);

        $crawler = $client->request('GET', '/contact');
        $client->submit($this->remplir($crawler, ['contact[website]' => 'https://spam.example']));

        self::assertResponseRedirects('/contact');
        $client->followRedirect();
        self::assertSelectorExists('.alerte--succes');

        self::assertCount(0, $this->messages($client)->findAll());
        self::assertQueuedEmailCount(0);
    }

    public function testSubmissionSentTooFastIsRejected(): void
    {
        $client = static::createClient();
        $this->purge($client);

        $crawler = $client->request('GET', '/contact');

        // Horodatage d'origine : zéro seconde écoulée, sous le minimum.
        $formulaire = $this->remplir($crawler, [], antidater: false);
        $client->submit($formulaire);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.alerte--erreur', 'trop rapidement');
        self::assertCount(0, $this->messages($client)->findAll());
    }

    public function testForgedTimestampIsRejected(): void
    {
        $client = static::createClient();
        $this->purge($client);

        $crawler = $client->request('GET', '/contact');
        $formulaire = $this->remplir($crawler, [
            'contact[ts]' => (string) (time() - 600),
            'contact[signature]' => str_repeat('0', 64),
        ]);
        $client->submit($formulaire);

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.alerte--erreur');
        self::assertCount(0, $this->messages($client)->findAll());
    }

    public function testRateLimiterBlocksTheSixthMessage(): void
    {
        $client = static::createClient();
        $this->purge($client);

        for ($i = 1; $i <= 5; ++$i) {
            $crawler = $client->request('GET', '/contact');
            $client->submit($this->remplir($crawler, ['contact[subject]' => 'Message '.$i]));
            self::assertResponseRedirects('/contact', message: \sprintf('Le message %d doit passer.', $i));
        }

        $crawler = $client->request('GET', '/contact');
        $client->submit($this->remplir($crawler, ['contact[subject]' => 'Message 6']));

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.alerte--erreur', 'Trop de messages');
        self::assertCount(5, $this->messages($client)->findAll());
    }

    /**
     * @param array<string, string> $surcharges
     */
    private function remplir(Crawler $crawler, array $surcharges = [], bool $antidater = true): \Symfony\Component\DomCrawler\Form
    {
        $formulaire = $crawler->filter('form.formulaire')->form([
            'contact[name]' => 'Claire Dupont',
            'contact[email]' => 'claire@example.org',
            'contact[subject]' => 'Une question sur vos actions',
            'contact[message]' => 'Bonjour, je souhaiterais en savoir plus sur vos actions de terrain.',
            'contact[consent]' => true,
        ]);

        if ($antidater) {
            $ts = (string) (time() - 60);
            $formulaire['contact[ts]'] = $ts;
            $formulaire['contact[signature]'] = hash_hmac('sha256', $ts, self::SECRET_TEST);
        }

        foreach ($surcharges as $champ => $valeur) {
            $formulaire[$champ] = $valeur;
        }

        return $formulaire;
    }

    private function messages(KernelBrowser $client): ContactMessageRepository
    {
        $repository = $client->getContainer()->get(ContactMessageRepository::class);
        self::assertInstanceOf(ContactMessageRepository::class, $repository);

        return $repository;
    }

    /**
     * Remet à zéro les messages et le compteur de débit.
     *
     * Le compteur vit dans le cache applicatif, partagé entre les tests et
     * même entre deux exécutions de la suite : sans remise à zéro explicite,
     * un test finirait par en bloquer un autre.
     */
    private function purge(KernelBrowser $client): void
    {
        $manager = $client->getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $manager);
        $manager->createQuery('DELETE FROM '.ContactMessage::class.' m')->execute();

        $limiteur = $client->getContainer()->get('limiter.contact_form');
        self::assertInstanceOf(RateLimiterFactoryInterface::class, $limiteur);
        $limiteur->create('127.0.0.1')->reset();
    }
}
