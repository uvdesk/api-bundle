<?php

namespace Webkul\UVDesk\ApiBundle\API;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Webkul\UVDesk\CoreFrameworkBundle\Entity\User;

class Me extends AbstractController
{
    public function loadCurrentAgentDetails(Request $request, ContainerInterface $container)
    {
        $user = $this->getUser();
        $userInstance = $user->getCurrentInstance();

        return new JsonResponse([
            'success' => true, 
            'me' => [
                'id' => $user->getId(), 
                'email' => $user->getEmail(), 
                'name' => trim(implode(' ', array_values(array_filter([$user->getFirstName(), $user->getLastName()])))), 
                'firstName' => $user->getFirstName(), 
                'lastName' => $user->getLastName(), 
                'isEnabled' => $user->getIsEnabled(), 
                'profileImage' => $userInstance->getProfileImagePath(), 
            ], 
        ]);
    }
}
