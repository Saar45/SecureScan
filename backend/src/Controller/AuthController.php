<?php

namespace App\Controller;

use App\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/auth')]
class AuthController extends AbstractController
{
    #[Route('/github', methods: ['GET'])]
    public function githubRedirect(): RedirectResponse
    {
        $clientId = $_ENV['GITHUB_CLIENT_ID'] ?? '';
        $redirectUri = 'http://localhost:3000/api/auth/github/callback';
        $scope = 'repo user:email';

        $url = sprintf(
            'https://github.com/login/oauth/authorize?client_id=%s&redirect_uri=%s&scope=%s',
            urlencode($clientId),
            urlencode($redirectUri),
            urlencode($scope),
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
