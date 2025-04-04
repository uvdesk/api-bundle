<?php

namespace Webkul\UVDesk\ApiBundle\API;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Webkul\UVDesk\ApiBundle\Entity\ApiAccessCredential;
use Webkul\UVDesk\CoreFrameworkBundle\Entity\User;
use Webkul\UVDesk\CoreFrameworkBundle\Utils\TokenGenerator;
use Webkul\UVDesk\CoreFrameworkBundle\Services\UVDeskService as Uvdesk;

class Sessions extends AbstractController
{
    public function loginSession(Request $request, EntityManagerInterface $entityManager, Uvdesk $uvdesk)
    {
        $user = $this->getUser();
        $userInstance = $user->getCurrentInstance();

        if (empty($user)) {
            return new JsonResponse([
                'success' => false, 
                'message' => "Invalid or no user credentials were provided.", 
            ], 403);
        }

        $accessCredential = new ApiAccessCredential();
        $accessCredential
            ->setUser($user)
            ->setName('API Session')
            ->setToken(strtoupper(TokenGenerator::generateToken(64)))
            ->setCreatedOn(new \DateTime('now'))
            ->setIsEnabled(true)
            ->setIsExpired(false)
        ;

        $entityManager->persist($accessCredential);
        $entityManager->flush();

        return new JsonResponse([
            'success'     => true, 
            'accessToken' => $accessCredential->getToken(), 
            'scopes'      => $uvdesk->getAvailableUserAccessScopes($user, $userInstance), 
        ]);
    }

    public function logoutSession(Request $request, EntityManagerInterface $entityManager)
    {
        $user = $this->getUser();

        $accessToken = null;
        $authorization = $request->headers->get('Authorization');

        if (!empty($authorization) && strpos(strtolower($authorization), 'basic') === 0) {
            $accessToken = substr($authorization, 6);
        } else if (!empty($authorization) && strpos(strtolower($authorization), 'bearer') === 0) {
            $accessToken = substr($authorization, 7);
        }
        
        if (empty($accessToken)) {
            return new JsonResponse([
                'success' => false, 
                'message' => "Unsupported or invalid credentials provided.", 
            ]);
        }

        $apiAccessCredential = $entityManager->getRepository(ApiAccessCredential::class)->findOneByToken($accessToken);

        if (empty($apiAccessCredential)) {
            return new JsonResponse([
                'success' => false, 
                'message' => "Invalid credentials provided.", 
            ]);
        }

        $apiAccessCredential
            ->setIsEnabled(false)
            ->setIsExpired(true)
        ;

        $entityManager->persist($apiAccessCredential);
        $entityManager->flush();

        return new JsonResponse([
            'status'  => true,
            'message' => 'Session token has been expired successfully.'
        ]);
    }
}