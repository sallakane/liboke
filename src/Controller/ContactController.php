<?php

declare(strict_types=1);

namespace App\Controller;

use App\Content\Page;
use App\Content\PageRepository;
use App\Entity\ContactMessage;
use App\Form\ContactInput;
use App\Form\ContactType;
use App\Form\SubmissionTimer;
use App\Repository\ContactMessageRepository;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

final class ContactController extends AbstractController
{
    public function __construct(
        private readonly PageRepository $pages,
        private readonly ContactMessageRepository $messages,
        private readonly SubmissionTimer $timer,
        private readonly MailerInterface $mailer,
        private readonly LoggerInterface $logger,
        #[Autowire(service: 'limiter.contact_form')]
        private readonly RateLimiterFactoryInterface $limiter,
        #[Autowire('%env(CONTACT_TO)%')]
        private readonly string $destinataire,
        #[Autowire('%env(MAILER_FROM)%')]
        private readonly string $expediteur,
    ) {
    }

    /**
     * Priorité par défaut : cette route l'emporte sur l'attrape-tout
     * `/{slug}` de PageController, dont la priorité est négative.
     */
    #[Route('/contact', name: 'app_contact', methods: ['GET', 'POST'])]
    public function index(Request $request): Response
    {
        $page = $this->pages->find('contact');

        if (null === $page) {
            throw $this->createNotFoundException('La page content/pages/contact.md est introuvable.');
        }

        $saisie = new ContactInput();
        $form = $this->createForm(ContactType::class, $saisie, ['timer' => $this->timer->issue()]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $piege = $form->get('website')->getData();

            // Piège rempli : c'est un robot. On affiche le même message de
            // succès que pour un envoi réel, pour ne pas lui apprendre que
            // le champ est surveillé.
            if (\is_string($piege) && '' !== trim($piege)) {
                $this->logger->info('Formulaire de contact : piège à robots déclenché.');

                return $this->succes();
            }

            $ts = $form->get('ts')->getData();
            $signature = $form->get('signature')->getData();

            if (!$this->timer->elapsedEnough(\is_string($ts) ? $ts : null, \is_string($signature) ? $signature : null)) {
                // Message explicite plutôt que rejet silencieux : un humain
                // très rapide doit pouvoir récupérer sa saisie.
                $this->addFlash('erreur', 'Votre message a été envoyé trop rapidement après l\'ouverture de la page. Merci de réessayer.');

                return $this->rendu($page, $form);
            }

            if (!$this->limiter->create($request->getClientIp() ?? 'anonyme')->consume()->isAccepted()) {
                $this->addFlash('erreur', 'Trop de messages ont été envoyés depuis cette connexion. Merci de réessayer plus tard.');

                return $this->rendu($page, $form);
            }

            $message = new ContactMessage(
                $saisie->name,
                $saisie->email,
                $saisie->subject,
                $saisie->message,
                $saisie->consent,
            );

            // Persistance synchrone d'abord : si l'envoi échoue, le message
            // reste consultable (CLAUDE.md §9).
            $this->messages->save($message);

            $this->mailer->send(
                (new TemplatedEmail())
                    ->from(new Address($this->expediteur, 'Site association LIBOKÉ'))
                    ->to($this->destinataire)
                    ->replyTo(new Address($saisie->email, $saisie->name))
                    ->subject(\sprintf('[Contact] %s', $saisie->subject))
                    ->htmlTemplate('emails/contact.html.twig')
                    ->textTemplate('emails/contact.txt.twig')
                    ->context(['message' => $message])
            );

            return $this->succes();
        }

        return $this->rendu($page, $form);
    }

    private function succes(): Response
    {
        $this->addFlash('succes', 'Votre message a bien été envoyé. L\'association vous répondra dès que possible.');

        // POST → redirect → GET : empêche le renvoi du formulaire au rafraîchissement.
        return $this->redirectToRoute('app_contact');
    }

    /**
     * @param FormInterface<ContactInput> $form
     */
    private function rendu(Page $page, FormInterface $form): Response
    {
        return $this->render('contact/index.html.twig', [
            'page' => $page,
            'form' => $form,
        ]);
    }
}
