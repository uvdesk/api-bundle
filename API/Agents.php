<?php

namespace Webkul\UVDesk\ApiBundle\API;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Webkul\UVDesk\CoreFrameworkBundle\Entity\User;
use Webkul\UVDesk\CoreFrameworkBundle\Entity\SupportRole;
use Webkul\UVDesk\CoreFrameworkBundle\Entity\SupportTeam;
use Webkul\UVDesk\CoreFrameworkBundle\Entity\SupportGroup;
use Webkul\UVDesk\CoreFrameworkBundle\Entity\SupportPrivilege;
use Webkul\UVDesk\ApiBundle\Entity\ApiAccessCredential;
use Webkul\UVDesk\CoreFrameworkBundle\Services\UserService;

class Agents extends AbstractController
{
    public function loadAgents(Request $request, EntityManagerInterface $entityManager)
    {
        $qb = $entityManager->createQueryBuilder();
        $qb
            ->select("
                u.id, 
                u.email, 
                u.firstName, 
                u.lastName, 
                u.isEnabled, 
                userInstance.isActive, 
                userInstance.isVerified, 
                userInstance.designation, 
                userInstance.contactNumber
            ")
            ->from(User::class, 'u')
            ->leftJoin('u.userInstance', 'userInstance')
            ->where('userInstance.supportRole != :roles')
            ->setParameter('roles', 4)
        ;

        $collection = $qb->getQuery()->getResult();

        return new JsonResponse([
            'success' => true, 
            'collection' => !empty($collection) ? $collection : [], 
        ]);
    }

    public function loadAgentDetails($id, Request $request)
    {
        $user = $this->getDoctrine()->getRepository(User::class)->findOneById($id);

        if (empty($user)) {
            return new JsonResponse([
                'success' => false, 
                'message' => "No agent account details were found with id '$id'.", 
            ], 404);
        }
        
        $agentDetails = [
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
            'agent' => $agentDetails, 
        ]);
    }

    public function createAgentRecord(Request $request,EntityManagerInterface $entityManager, UserService $userService)
    {
        $params = $request->request->all();
        $agentRecord = new User();
        $user = $entityManager->getRepository(User::class)->findOneByEmail($params['email']);
        $agentInstance = !empty($user) ? $user->getAgentInstance() : null;

        if (empty($agentInstance)) {
            
            $formDetails = $request->request->get('user_form');
            $uploadedFiles = $request->files->get('user_form');
            
            // Profile upload validation
            $validMimeType = ['image/jpeg', 'image/png', 'image/jpg'];

            if (isset( $uploadedFiles)) {
                if(!in_array($uploadedFiles->getMimeType(), $validMimeType)){
                    return new JsonResponse([
                        'success' => false, 
                        'message' => 'Profile image is not valid, please upload a valid format', 
                    ]);
                }
            }

            $fullname = trim(implode(' ', [$params['firstName'], $params['lastName']]));
            $supportRole = $entityManager->getRepository(SupportRole::class)->findOneByCode($params['role']);
            
            $user = $userService->createUserInstance($request->request->get('email'), $fullname, $supportRole, [
                'contact' => $params['contactNumber'],
                'source' => 'website',
                'active' => !empty($params['isActive']) ? true : false,
                'image' =>  $uploadedFiles ?  $uploadedFiles : null ,
                'signature' => $params['signature'],
                'designation' =>$params['designation']
            ]);

            if(!empty($user)){
                $user->setIsEnabled(true);
                $entityManager->persist($user);
                $entityManager->flush();
            }

            $userInstance = $user->getAgentInstance();

            if (isset($params['ticketView'])) {
                $userInstance->setTicketAccessLevel($params['ticketView']);
            }

            // Map support team
            if (!empty($params['userSubGroup'])) {
                $supportTeamRepository = $entityManager->getRepository(SupportTeam::class);

                foreach ($params['userSubGroup'] as $supportTeamId) {
                    $supportTeam = $supportTeamRepository->findOneById($supportTeamId);

                    if (!empty($supportTeam)) {
                        $userInstance->addSupportTeam($supportTeam);
                    }
                }
            }
            
            // Map support group
            if (!empty($params['groups'])) {
                $supportGroupRepository = $entityManager->getRepository(SupportGroup::class);

                foreach ($params['groups'] as $supportGroupId) {
                    $supportGroup = $supportGroupRepository->findOneById($supportGroupId);

                    if (!empty($supportGroup)) {
                        $userInstance->addSupportGroup($supportGroup);
                    }
                }
            }

            // Map support privileges
            if (!empty($params['agentPrivilege'])) {
                $supportPrivilegeRepository = $entityManager->getRepository(SupportPrivilege::class);

                foreach($params['agentPrivilege'] as $supportPrivilegeId) {
                    $supportPrivilege = $supportPrivilegeRepository->findOneById($supportPrivilegeId);

                    if (!empty($supportPrivilege)) {
                        $userInstance->addSupportPrivilege($supportPrivilege);
                    }
                }
            }

            $entityManager->persist($userInstance);
            $entityManager->flush();

            return new JsonResponse([
                'success' => true, 
                'message' => 'Agent added successfully.', 
            ]);

        
        } else {
            return new JsonResponse([
                'success' => false, 
                'message' => 'Agent with same email already exist.', 
            ]);
        }
    }
}
