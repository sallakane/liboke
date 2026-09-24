<?php

declare(strict_types=1);

namespace App\Controller;

use App\Content\Page;
use App\Content\PageRepository;
use App\Entity\Donation;
use App\Form\DonationInput;
use App\Form\DonationType;
use App\Repository\DonationRepository;
use App\Seo\CanonicalUrl;
use App\Stripe\CheckoutSessionFactory;
use App\Stripe\DonationAmounts;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Throwable;

final class DonationController extends AbstractController
{
    public function __construct(
        private readonly PageRepository $pages,
        private readonly DonationRepository $donations,
        private readonly DonationAmounts $amounts,
        private readonly CheckoutSessionFactory $checkout,
        private readonly CanonicalUrl $canonical,
        private readonly LoggerInterface $logger,
        #[Autowire(service: 'limiter.donation_checkout')]
        private readonly RateLimiterFactoryInterface $limiter,
    ) {
    }

    #[Route('/nous-soutenir', name: 'app_donation', methods: ['GET'])]
    public function index(): Response
    {
        return $this->rendu($this->page(), $this->createForm(DonationType::class, new DonationInput()));
    }

    /**
     * Crée la session Stripe puis redirige vers la page hébergée.
     */
    #[Route('/don/checkout', name: 'app_donation_checkout', methods: ['POST'])]
    public function checkout(Request $request): Response
    {
        $saisie = new DonationInput();
        $form = $this->createForm(DonationType::class, $saisie);
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            return $this->rendu($this->page(), $form);
        }

        $centimes = $this->resoudreMontant($saisie, $form);

        if (null === $centimes) {
            return $this->rendu($this->page(), $form);
        }

        if (!$this->limiter->create($request->getClientIp() ?? 'anonyme')->consume()->isAccepted()) {
            $this->addFlash('erreur', 'Trop de tentatives depuis cette connexion. Merci de réessayer plus tard.');

            return $this->rendu($this->page(), $form);
        }

        try {
            // URLs construites depuis l'hôte canonique, pas depuis l'en-tête
            // Host de la requête : Stripe doit renvoyer le donateur sur le
            // vrai domaine, quel que soit le proxy en amont.
            $session = $this->checkout->create(
                $centimes,
                $this->amounts->currency,
                $this->canonical->forPath('/don/merci'),
                $this->canonical->forPath('/don/annule'),
            );
        } catch (Throwable $erreur) {
            $this->logger->error('Création de session Stripe impossible.', ['exception' => $erreur]);
            $this->addFlash('erreur', 'Le paiement en ligne est momentanément indisponible. Merci de réessayer dans quelques instants.');

            return $this->rendu($this->page(), $form);
        }

        // Le don est enregistré « en attente » avant la redirection : seul le
        // webhook le passera à « payé » (CLAUDE.md §10).
        $this->donations->save(new Donation($session->id, $centimes, $this->amounts->currency));

        return new RedirectResponse($session->url, Response::HTTP_SEE_OTHER);
    }

    #[Route('/don/merci', name: 'app_donation_thanks', methods: ['GET'])]
    public function thanks(): Response
    {
        return $this->render('donation/merci.html.twig', ['noindex' => true]);
    }

    #[Route('/don/annule', name: 'app_donation_cancel', methods: ['GET'])]
    public function cancel(): Response
    {
        return $this->render('donation/annule.html.twig', ['noindex' => true]);
    }

    /**
     * Recalcule le montant côté serveur. La valeur postée ne fait jamais foi.
     *
     * @param FormInterface<DonationInput> $form
     */
    private function resoudreMontant(DonationInput $saisie, FormInterface $form): ?int
    {
        if (DonationInput::LIBRE !== $saisie->preset) {
            $centimes = (int) $saisie->preset;

            // Le montant doit faire partie des suggestions publiées : sinon
            // un navigateur pourrait poster n'importe quelle valeur.
            if (!\in_array($centimes, $this->amounts->suggestions, true)) {
                $form->get('preset')->addError(new \Symfony\Component\Form\FormError('Ce montant n\'est pas proposé.'));

                return null;
            }

            return $centimes;
        }

        if (null === $saisie->custom) {
            $form->get('custom')->addError(new \Symfony\Component\Form\FormError('Merci d\'indiquer le montant de votre don.'));

            return null;
        }

        $centimes = $saisie->custom * 100;

        if (!$this->amounts->isAllowed($centimes)) {
            $form->get('custom')->addError(new \Symfony\Component\Form\FormError(\sprintf(
                'Le montant doit être compris entre %s € et %s €.',
                $this->amounts->toEuros($this->amounts->minimum),
                $this->amounts->toEuros($this->amounts->maximum),
            )));

            return null;
        }

        return $centimes;
    }

    private function page(): Page
    {
        $page = $this->pages->find('nous-soutenir');

        if (null === $page) {
            throw $this->createNotFoundException('La page content/pages/nous-soutenir.md est introuvable.');
        }

        return $page;
    }

    /**
     * @param FormInterface<DonationInput> $form
     */
    private function rendu(Page $page, FormInterface $form): Response
    {
        return $this->render('donation/index.html.twig', [
            'page' => $page,
            'form' => $form,
            'montants' => $this->amounts,
        ]);
    }
}
