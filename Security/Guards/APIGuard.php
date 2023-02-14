<?php

namespace Webkul\UVDesk\ApiBundle\Security\Guards;

use Doctrine\ORM\Tools\Setup;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Bundle\SecurityBundle\Security\FirewallMap;
use Symfony\Component\Security\Core\User\UserInterface;
use Webkul\UVDesk\ApiBundle\Entity\ApiAccessCredential;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;
use Symfony\Component\Security\Guard\AbstractGuardAuthenticator;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;

class APIGuard extends AbstractGuardAuthenticator
{
    /**
     * [API-*] API Exception Codes
     */
    const API_UNAUTHORIZED = 'API-001';
    const API_NOT_AUTHENTICATED = 'API-002';
    const API_UNEXPECTED_ERROR = 'API-003';

    public function __construct(FirewallMap $firewall, ContainerInterface $container, EntityManagerInterface $entityManager)
	{
        $this->firewall = $firewall;
        $this->container = $container;
        $this->entityManager = $entityManager;
	}

    /**
     * Check whether this guard is applicable for the current request.
     */
    public function supports(Request $request)
    {
        return 'OPTIONS' != $request->getRealMethod() && 'uvdesk_api' === $this->firewall->getFirewallConfig($request)->getName();
    }

    /**
     * Retrieve and prepare credentials from the request.
     */
    public function getCredentials(Request $request)
    {
        if ($request->headers->get('Authorization') != null) {
            $authorizationToken = null;
            
            if (strpos(strtolower($request->headers->get('Authorization')), 'basic') === 0) {
                $authorizationToken = substr($request->headers->get('Authorization'), 6);
            } else if (strpos(strtolower($request->headers->get('Authorization')), 'bearer') === 0) {
                $authorizationToken = substr($request->headers->get('Authorization'), 7);
            }
            
            if (!empty($authorizationToken)) {
                $user = $this->entityManager->getRepository(ApiAccessCredential::class)->getUserEmailByAccessToken($authorizationToken);

                return [
                    'email' => $user['email'], 
                    'authorizationToken' => $authorizationToken, 
                ];
            }
        }
        
        return [];
    }

    /**
     * Retrieve the current user on behalf of which the request is being performed.
     */
    public function getUser($credentials, UserProviderInterface $provider)
    {
        if (empty($credentials['email'])) {
            return null;
        }

        return $provider->loadUserByUsername($credentials['email']);
    }

    /**
     * Process the provided credentials and check whether the current request is properly authenticated.
     */
    public function checkCredentials($credentials, UserInterface $user)
    {
        if (!empty($credentials['authorizationToken'])) {
            $accessCredentials = $this->entityManager->getRepository(ApiAccessCredential::class)->findOneBy([
                'user' => $user,
                'token' => $credentials['authorizationToken'],
            ]);

            if (!empty($accessCredentials) && true == $accessCredentials->getIsEnabled() && false == $accessCredentials->getIsExpired()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Disable support for the "remember me" functionality.
     */
    public function supportsRememberMe()
    {
        return false;
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, $providerKey)
    {
        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception)
    {
        $responseContent = [
            'status' => false,
            'message' => strtr($exception->getMessageKey(), $exception->getMessageData()),
            'error_code' => self::API_UNEXPECTED_ERROR,
        ];

        switch (strtolower($exception->getMessageKey())) {
            case 'username could not be found.':
                $responseContent = [
                    'status' => false,
                    'message' => 'No such user found',
                    'error_code' => self::API_UNAUTHORIZED,
                ];

                break;
            case 'invalid credentials.':
                $responseContent = [
                    'status' => false,
                    'message' => 'Invalid credentials provided.',
                    'error_code' => self::API_NOT_AUTHENTICATED,
                ];

                break;
            default:
                

                break;
        }

        return new JsonResponse($responseContent, Response::HTTP_FORBIDDEN);
    }

    public function start(Request $request, AuthenticationException $authException = null)
    {
        $data = [
            'status' => false,
            'message' => 'Authentication Required',
            'error_code' => self::API_NOT_AUTHENTICATED,
        ];

        return new JsonResponse($data, Response::HTTP_UNAUTHORIZED);
    }
}
