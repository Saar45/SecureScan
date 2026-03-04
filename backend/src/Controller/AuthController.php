<?php

namespace App\Controller;

use App\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/auth')]
class AuthController extends AbstractController
{
    #[Route('/github', methods: ['GET'])]
    public function githubRedirect(Request $request): RedirectResponse
    {
        $clientId = $_ENV['GITHUB_CLIENT_ID'] ?? '';
        $redirectUri = !empty($_ENV['GITHUB_CALLBACK_URL']) ? $_ENV['GITHUB_CALLBACK_URL'] : 'http://localhost:3000/api/auth/github/callback';
        $scope = 'repo user:email';

        $state = bin2hex(random_bytes(16));
        $request->getSession()->set('oauth_state', $state);

        $url = sprintf(
            'https://github.com/login/oauth/authorize?client_id=%s&redirect_uri=%s&scope=%s&state=%s',
            urlencode($clientId),
            urlencode($redirectUri),
            urlencode($scope),
            urlencode($state),
        );

        return new RedirectResponse($url);
    }

    #[Route('/github/callback', methods: ['GET'])]
    public function githubCallback(): Response
    {
        // Handled by GitHubAuthenticator — this is a fallback
        return new RedirectResponse('http://localhost:3000/dashboard');
    }

    #[Route('/me', methods: ['GET'])]
    public function me(): JsonResponse
    {
        $user = $this->getUser();

        if (!$user instanceof User) {
            return $this->json(['error' => 'Not authenticated'], 401);
        }

        return $this->json([
            'id' => $user->getId(),
            'username' => $user->getUsername(),
            'avatarUrl' => $user->getAvatarUrl(),
            'githubId' => $user->getGithubId(),
        ]);
    }

    #[Route('/logout', methods: ['POST'])]
    public function logout(): void
    {
        // Handled by Symfony security logout
    }
}
