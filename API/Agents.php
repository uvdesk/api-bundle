<?php

namespace Webkul\UVDesk\ApiBundle\API;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Webkul\UVDesk\CoreFrameworkBundle\Entity\User;
use Webkul\UVDesk\ApiBundle\Entity\ApiAccessCredential;


class Agents extends AbstractController
{
    public function loadAgents(Request $request, ContainerInterface $container, EntityManagerInterface $entityManager)
    {
        $collection = [];
        $qb = $entityManager->createQueryBuilder();
        
        $qb->select(" u.id,u.email,u.firstName,u.lastName,u.isEnabled,userInstance.isActive, userInstance.isVerified, userInstance.designation, userInstance.contactNumber")
            ->from(User::class, 'u')
            ->leftJoin('u.userInstance', 'userInstance')
            ->andwhere('userInstance.supportRole != :roles')
            ->setParameter('roles', 4)
        ;

        $result = $qb->getQuery()->getResult();
        if ($result) {
            return new JsonResponse([
                'success' => true, 
                'collection' =>  $result
            ]);
        } else {
            return new JsonResponse([
                'success' => true, 
                'collection' =>  'Collection not found.'
            ]);
        }
    }

    public function loadAgentDetails($id, Request $request, ContainerInterface $container)
    {
        $collection = [];
        $user = $this->getDoctrine()->getRepository(User::class)->findOneById($id);

        if ($user->getIsEnabled() == 'true') {
            $agentDetail = [
                'id' => $user->getId(), 
                'firstName' => $user->getFirstName(),
                'lastName' => $user->getLastName(),
                'userEmail' => $user->getUsername(),
                'isEnabled' => $user->getIsEnabled(),
                'isActive' => $user->getAgentInstance()->getIsActive(),
                'isVerified' => $user->getAgentInstance()->getIsVerified(),
                'contactNumber' => $user->getAgentInstance()->getContactNumber()
            ];
            return new JsonResponse([
                'success' => true, 
                'agent' => $agentDetail
            ]);

        } else {
            return new JsonResponse([
                'success' => false, 
                'message' => 'Agent account is disabled.', 
            ]);
        }
        
    }
}