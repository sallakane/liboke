<?php

declare(strict_types=1);

namespace App\Controller;

use App\Admin\DonationCsvExporter;
use App\Admin\DonationFilter;
use App\Entity\DonationStatus;
use App\Repository\ContactMessageRepository;
use App\Repository\DonationRepository;
use LogicException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\InvalidCsrfTokenException;
use Symfony\Component\Security\Core\Exception\TooManyLoginAttemptsAuthenticationException;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

/**
 * Espace d'administration (CLAUDE.md §10) : consultation des dons et des
 * messages de contact, export CSV.
 *
 * Lecture seule, volontairement : aucune route ne modifie une donnée. Une
 * correction sur un don se fait dans le tableau de bord Stripe, qui fait foi,
 * et revient ici par webhook.
 *
 * L'accès est réservé par config/packages/security.yaml (pare-feu `admin`).
 * Le `noindex` et l'absence de cache sont posés par AdminResponseListener.
 */
#[Route('/admin')]
final class AdminController extends AbstractController
{
    private const int PAR_PAGE = 50;

    public function __construct(
        private readonly DonationRepository $donations,
        private readonly ContactMessageRepository $messages,
    ) {
    }

    #[Route('', name: 'app_admin', methods: ['GET'])]
    public function index(): Response
    {
        return $this->redirectToRoute('app_admin_donations');
    }

    #[Route('/connexion', name: 'app_admin_login', methods: ['GET', 'POST'])]
    public function login(AuthenticationUtils $authentication): Response
    {
        if ($this->isGranted('ROLE_ADMIN')) {
            return $this->redirectToRoute('app_admin_donations');
        }

        return $this->render('admin/login.html.twig', [
            'noindex' => true,
            'dernier_identifiant' => $authentication->getLastUsername(),
            'erreur' => $this->messageErreur($authentication->getLastAuthenticationError()),
        ]);
    }

    /**
     * Jamais exécutée : le pare-feu intercepte la route.
     */
    #[Route('/deconnexion', name: 'app_admin_logout', methods: ['GET'])]
    public function logout(): never
    {
        throw new LogicException('Interceptée par le pare-feu « admin ».');
    }

    #[Route('/dons', name: 'app_admin_donations', methods: ['GET'])]
    public function donations(Request $request): Response
    {
        $filtre = DonationFilter::fromQuery($request->query);
        $page = max(1, $request->query->getInt('page', 1));
        $resultats = $this->donations->search($filtre, $page, self::PAR_PAGE);

        return $this->render('admin/donations.html.twig', [
            'noindex' => true,
            'dons' => $resultats,
            'filtre' => $filtre,
            'statuts' => DonationStatus::cases(),
            'totaux' => $this->donations->totalsByStatus($filtre),
            'page_courante' => $page,
            'pages' => max(1, (int) ceil(\count($resultats) / self::PAR_PAGE)),
        ]);
    }

    #[Route('/dons.csv', name: 'app_admin_donations_csv', methods: ['GET'])]
    public function donationsCsv(Request $request, DonationCsvExporter $exporter): StreamedResponse
    {
        $filtre = DonationFilter::fromQuery($request->query);

        $response = new StreamedResponse(function () use ($exporter, $filtre): void {
            $flux = fopen('php://output', 'w');

            if (false === $flux) {
                throw new LogicException('Impossible d\'ouvrir la sortie standard.');
            }

            $exporter->write($flux, $this->donations->iterate($filtre));
            fclose($flux);
        });

        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set('Content-Disposition', HeaderUtils::makeDisposition(
            HeaderUtils::DISPOSITION_ATTACHMENT,
            \sprintf('dons-liboke-%s.csv', date('Y-m-d')),
        ));

        return $response;
    }

    #[Route('/messages', name: 'app_admin_messages', methods: ['GET'])]
    public function messages(Request $request): Response
    {
        $page = max(1, $request->query->getInt('page', 1));
        $resultats = $this->messages->paginate($page, self::PAR_PAGE);

        return $this->render('admin/messages.html.twig', [
            'noindex' => true,
            'messages' => $resultats,
            'page_courante' => $page,
            'pages' => max(1, (int) ceil(\count($resultats) / self::PAR_PAGE)),
        ]);
    }

    /**
     * Message volontairement vague : il ne dit pas si c'est l'identifiant ou
     * le mot de passe qui est faux.
     */
    private function messageErreur(?AuthenticationException $erreur): ?string
    {
        return match (true) {
            null === $erreur => null,
            $erreur instanceof TooManyLoginAttemptsAuthenticationException => 'Trop de tentatives de connexion. Merci de réessayer dans quelques minutes.',
            $erreur instanceof InvalidCsrfTokenException => 'La session a expiré. Merci de recharger la page et de réessayer.',
            default => 'Identifiant ou mot de passe incorrect.',
        };
    }
}
