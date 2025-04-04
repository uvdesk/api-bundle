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
use Symfony\Component\Security\Core\Encoder\UserPasswordEncoderInterface;

class APIGuard extends AbstractGuardAuthenticator
{
    /**
     * [API-*] API Exception Codes
     */
    const API_UNAUTHORIZED = 'API-001';
    const API_NOT_AUTHENTICATED = 'API-002';
    const API_INSUFFICIENT_PARAMS = 'API-003';

    /**
     * [CC-*] Campus Connect Exception Codes
     */
    const USER_NOT_FOUND = 'CC-001';
    const INVALID_CREDNETIALS = 'CC-002';
    const UNEXPECTED_ERROR = 'CC-005';

    public function __construct(FirewallMap $firewall, ContainerInterface $container, EntityManagerInterface $entityManager, UserPasswordEncoderInterface $encoder)
	{
        $this->firewall = $firewall;
        $this->container = $container;
        $this->entityManager = $entityManager;
        $this->encoder = $encoder;
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
        $accessToken = null;
        $authorization = $request->headers->get('Authorization');

        if (!empty($authorization) && strpos(strtolower($authorization), 'basic') === 0) {
            $accessToken = substr($authorization, 6);
        } else if (!empty($authorization) && strpos(strtolower($authorization), 'bearer') === 0) {
            $accessToken = substr($authorization, 7);
        }

        if (!empty($accessToken)) {
            try {
                if (in_array($request->attributes->get('_route'), ['uvdesk_api_bundle_sessions_api_v1.0_login_session'])) {
                    list($email, $password) = explode(':', base64_decode($accessToken));

                    return [
                        'email'    => $email, 
                        'password' => $password, 
                    ];
                } else {
                    $user = $this->entityManager->getRepository(ApiAccessCredential::class)->getUserEmailByAccessToken($accessToken);
                    
                    return [
                        'email'       => $user['email'], 
                        'accessToken' => $accessToken, 
                    ];
                }
            } catch (\Exception $e) {
                throw new AuthenticationException("An unexpected error occurred while authenticating credentials: {$e->getMessage()}");
            }
        }
        
        return [];
    }

    /**
     * Retrieve the current user on behalf of which the request is being performed.
     */
    public function getUser($credentials, UserProviderInterface $provider)
    {
        return !empty($credentials['email']) ? $provider->loadUserByUsername($credentials['email']) : null;
    }

    /**
     * Process the provided credentials and check whether the current request is properly authenticated.
     */
    public function checkCredentials($credentials, UserInterface $user)
    {
        if (!empty($credentials['password'])) {
            return $this->encoder->isPasswordValid($user, $credentials['password']);
        }

        if (!empty($credentials['accessToken'])) {
            $accessCredentials = $this->entityManager->getRepository(ApiAccessCredential::class)->findOneBy([
                'user'  => $user,
                'token' => $credentials['accessToken'],
            ]);

            if (
                ! empty($accessCredentials)
                && true == $accessCredentials->getIsEnabled()
                && false == $accessCredentials->getIsExpired()
            ) {
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
        switch ($exception->getMessageKey()) {
            case 'Username could not be found.':
                $data = [
                    'status'     => false,
                    'message'    => 'No such user found',
                    'error_code' => self::USER_NOT_FOUND,
                ];
                
                break;
            case 'Invalid Credentials.':
                $data = [
                    'status'     => false,
                    'message'    => 'Invalid credentials provided.',
                    'error_code' => self::INVALID_CREDNETIALS,
                ];

                break;
            case 'An authentication exception occurred.':
                if ($request->attributes->get('_route') == 'uvdesk_api_bundle_sessions_api_v1.0_logout_session'){
                    $data = [
                        'status'     => false,
                        'message'    => 'This Session token has been already expired successfully.',
                        'error_code' => self::INVALID_CREDNETIALS,
                    ];

                    return new JsonResponse($data, Response::HTTP_FORBIDDEN);
                }

                $data = [
                    'status'     => false,
                    'message'    => 'This api is disabled from admin end, please check once again.',
                    'error_code' => self::INVALID_CREDNETIALS,
                ];
                
                break;
            default:
                $data = [
                    'status'     => false,
                    'message'    => strtr($exception->getMessageKey(), $exception->getMessageData()),
                    'error_code' => self::UNEXPECTED_ERROR,
                ];

                break;
        }

        return new JsonResponse($data, Response::HTTP_FORBIDDEN);
    }

    public function start(Request $request, AuthenticationException $authException = null)
    {
        $data = [
            'status'     => false,
            'message'    => 'Authentication Required',
            'error_code' => self::API_NOT_AUTHENTICATED,
        ];

        return new JsonResponse($data, Response::HTTP_UNAUTHORIZED);
    }
}
