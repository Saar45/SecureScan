<?php

namespace App\Security;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\TokenEncryptor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class GitHubAuthenticator extends AbstractAuthenticator
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly UserRepository $userRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly TokenEncryptor $tokenEncryptor,
    ) {
    }

    public function supports(Request $request): ?bool
    {
        return $request->getPathInfo() === '/api/auth/github/callback' && $request->query->has('code');
    }

    public function authenticate(Request $request): Passport
    {
        $state = $request->query->get('state', '');
        $expectedState = $request->getSession()->remove('oauth_state');

        if (!$expectedState || !hash_equals($expectedState, $state)) {
            throw new AuthenticationException('Invalid OAuth state parameter.');
        }

        $code = $request->query->get('code');

        $tokenResponse = $this->httpClient->request('POST', 'https://github.com/login/oauth/access_token', [
            'headers' => ['Accept' => 'application/json'],
            'body' => [
                'client_id' => $_ENV['GITHUB_CLIENT_ID'] ?? '',
                'client_secret' => $_ENV['GITHUB_CLIENT_SECRET'] ?? '',
                'code' => $code,
            ],
        ]);

        $tokenData = $tokenResponse->toArray();
        $accessToken = $tokenData['access_token'] ?? null;

        if (!$accessToken) {
            throw new AuthenticationException('Failed to obtain GitHub access token.');
        }

        $profileResponse = $this->httpClient->request('GET', 'https://api.github.com/user', [
            'headers' => [
                'Authorization' => sprintf('Bearer %s', $accessToken),
                'Accept' => 'application/vnd.github+json',
            ],
        ]);

        $profile = $profileResponse->toArray();
        $githubId = $profile['id'] ?? null;

        if (!$githubId) {
            throw new AuthenticationException('Failed to fetch GitHub profile.');
        }

        $user = $this->userRepository->findByGithubId($githubId);

        if ($user === null) {
            $user = new User();
            $user->setGithubId($githubId);
            $this->entityManager->persist($user);
        }

        $user->setUsername($profile['login'] ?? 'unknown');
        $user->setAvatarUrl($profile['avatar_url'] ?? null);
        $user->setGithubToken($this->tokenEncryptor->encrypt($accessToken));
        $this->entityManager->flush();

        return new SelfValidatingPassport(
            new UserBadge((string) $githubId, fn () => $user)
        );
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return new RedirectResponse('http://localhost:3000/dashboard');
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        return new RedirectResponse('http://localhost:3000/login?error=auth_failed');
    }
}
