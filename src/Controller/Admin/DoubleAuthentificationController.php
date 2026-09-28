<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\User;
use App\Service\QrCodeGenerateur;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Scheb\TwoFactorBundle\Model\Totp\TotpConfigurationInterface;
use Scheb\TwoFactorBundle\Model\Totp\TwoFactorInterface;
use Scheb\TwoFactorBundle\Security\TwoFactor\Provider\Totp\TotpAuthenticatorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Première activation de la double authentification pour un compte admin.
 *
 * Tant qu'elle n'est pas activée, ExigerDoubleAuthentificationSubscriber
 * redirige ici toute page de l'admin.
 */
#[IsGranted(User::ROLE_ADMIN)]
final class DoubleAuthentificationController extends AbstractController
{
    private const CLE_SESSION = 'admin_totp_secret_en_attente';

    public function __construct(
        private readonly TotpAuthenticatorInterface $totp,
        private readonly QrCodeGenerateur $qrCode,
        private readonly EntityManagerInterface $entityManager,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('/admin/securite/double-authentification', name: 'admin_2fa_configurer', methods: ['GET', 'POST'])]
    public function configurer(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        if ($user->isTotpAuthenticationEnabled()) {
            return $this->redirectToRoute('admin');
        }

        $session = $request->getSession();
        $secret = $session->get(self::CLE_SESSION);
        if (!\is_string($secret)) {
            $secret = $this->totp->generateSecret();
            $session->set(self::CLE_SESSION, $secret);
        }

        $candidat = $this->utilisateurTemporaire($user->getTotpAuthenticationUsername(), $secret);
        $erreur = null;

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('admin_2fa_configurer', (string) $request->request->get('_csrf_token'))) {
                throw $this->createAccessDeniedException('Jeton CSRF invalide.');
            }

            $code = preg_replace('/\s+/', '', (string) $request->request->get('code'));
            if (null !== $code && $this->totp->checkCode($candidat, $code)) {
                $user->activerTotp($secret, $this->clock->now());
                $this->entityManager->flush();
                $session->remove(self::CLE_SESSION);
                $this->logger->notice('Double authentification activée.', ['utilisateur' => $user->getUserIdentifier()]);
                $this->addFlash('success', 'Double authentification activée. Elle vous sera demandée à chaque connexion.');

                return $this->redirectToRoute('admin');
            }

            $erreur = 'Code incorrect. Vérifiez l\'heure de votre téléphone et saisissez le code affiché actuellement.';
        }

        return $this->render('admin/double_authentification.html.twig', [
            'qr_code' => $this->qrCode->dataUri($this->totp->getQRContent($candidat)),
            'secret' => $secret,
            'erreur' => $erreur,
        ]);
    }

    /**
     * Porte le secret en attente sans le poser sur l'entité User,
     * pour qu'aucun flush ne l'enregistre avant la vérification du code.
     */
    private function utilisateurTemporaire(string $nom, string $secret): TwoFactorInterface
    {
        return new class($nom, $secret) implements TwoFactorInterface {
            public function __construct(private readonly string $nom, private readonly string $secret)
            {
            }

            public function isTotpAuthenticationEnabled(): bool
            {
                return true;
            }

            public function getTotpAuthenticationUsername(): string
            {
                return $this->nom;
            }

            public function getTotpAuthenticationConfiguration(): TotpConfigurationInterface
            {
                return User::configurationTotp($this->secret);
            }
        };
    }
}
