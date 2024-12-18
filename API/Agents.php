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
use Webkul\UVDesk\CoreFrameworkBundle\Entity\UserInstance;
use Webkul\UVDesk\CoreFrameworkBundle\Entity\SupportRole;
use Webkul\UVDesk\CoreFrameworkBundle\Entity\SupportTeam;
use Webkul\UVDesk\CoreFrameworkBundle\Entity\SupportGroup;
use Webkul\UVDesk\CoreFrameworkBundle\Entity\SupportPrivilege;
use Webkul\UVDesk\ApiBundle\Entity\ApiAccessCredential;
use Symfony\Component\Security\Core\Encoder\UserPasswordEncoderInterface;
use Webkul\UVDesk\CoreFrameworkBundle\Services\UserService;
use Webkul\UVDesk\CoreFrameworkBundle\Services\UVDeskService;
use Webkul\UVDesk\CoreFrameworkBundle\FileSystem\FileSystem;
use Webkul\UVDesk\CoreFrameworkBundle\Workflow\Events as CoreWorkflowEvents;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Filesystem\Filesystem as Fileservice;

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
            'success'    => true, 
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
            'id'            => $user->getId(), 
            'firstName'     => $user->getFirstName(),
            'lastName'      => $user->getLastName(),
            'userEmail'     => $user->getUsername(),
            'isEnabled'     => $user->getIsEnabled(),
            'isActive'      => $user->getAgentInstance()->getIsActive(),
            'isVerified'    => $user->getAgentInstance()->getIsVerified(),
            'contactNumber' => $user->getAgentInstance()->getContactNumber()
        ];
        
        return new JsonResponse([
            'success' => true, 
            'agent' => $agentDetails, 
        ]);
    }

    public function createAgentRecord(Request $request, ContainerInterface $container, EntityManagerInterface $entityManager, UserService $userService)
    {
        $params = $request->request->all()? : json_decode($request->getContent(),true);
        
        foreach ($params as $key => $value) {
            if (!in_array($key, ['email', 'user_form', 'firstName', 'lastName','contactNumber','isActive','signature','designation','role','ticketView','userSubGroup','groups','agentPrivilege'])) {
                unset($params[$key]);
            }
        }

        if (empty($params['email']) || empty($params['firstName']) || empty($params['groups']) || empty($params['role'])) {
            $json['error'] = $container->get('translator')->trans('required fields: email,firstName,lastName,groups and role.');
            
            return new JsonResponse($json, Response::HTTP_BAD_REQUEST);
        }

        $agentRecord = new User();
        $user = $entityManager->getRepository(User::class)->findOneByEmail($params['email']);
        $agentInstance = !empty($user) ? $user->getAgentInstance() : null;

        if (!empty($agentInstance)) {
            return new JsonResponse([
                'success' => false, 
                'message' => 'Agent with same email already exist.', 
            ]);
        }
        
        $uploadedFiles = $request->files->get('user_form');
        
        // Profile upload validation
        $validMimeType = ['image/jpeg', 'image/png', 'image/jpg'];

        if (isset($uploadedFiles)) {
            if (!in_array($uploadedFiles->getMimeType(), $validMimeType)) {
                return new JsonResponse([
                    'success' => false, 
                    'message' => 'Profile image is not valid, please upload a valid format', 
                ]);
            }
        }

        $fullname = trim(implode(' ', [$params['firstName'], $params['lastName']]));
        $supportRole = $entityManager->getRepository(SupportRole::class)->findOneByCode($params['role']);
        
        $user = $userService->createUserInstance($params['email'], $fullname, $supportRole, [
            'contact'     => $params['contactNumber'],
            'source'      => 'website',
            'active'      => !empty($params['isActive']) ? true : false,
            'image'       => $uploadedFiles ?  $uploadedFiles : null ,
            'signature'   => $params['signature'],
            'designation' => $params['designation']
        ]);

        if (!empty($user)) {
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
    }

    public function updateAgentRecord($id, Request $request, UVDeskService $uvdeskService, ContainerInterface $container, UserPasswordEncoderInterface $passwordEncoder, FileSystem $fileSystem, EventDispatcherInterface $eventDispatcher)
    {
        $params = $request->request->all()? : json_decode($request->getContent(),true);
    
        foreach ($params as $key => $value) {
            if (!in_array($key, ['email', 'user_form', 'firstName', 'lastName','contactNumber','isActive','signature','designation','role','ticketView','userSubGroup','groups','agentPrivilege'])) {
                unset($params[$key]);
            }
        }

        if (empty($params['email']) || empty($params['firstName']) || empty($params['groups']) || empty($params['role'])) {
            $json['error'] = $container->get('translator')->trans('required fields: email,firstName,lastName,groups and role.');
            return new JsonResponse($json, Response::HTTP_BAD_REQUEST);
        }

        $dataFiles = $request->files->get('user_form');
        $em = $this->getDoctrine()->getManager();
        $user = $em->getRepository(User::class)->find($id);
        $instanceRole = $user->getAgentInstance()->getSupportRole()->getCode();
        
        if (empty($user)) {
            return new JsonResponse([
                'success' => false, 
                'message' => 'Agent not found.', 
            ], 404);
        }

        // Agent Profile upload validation
        $validMimeType = ['image/jpeg', 'image/png', 'image/jpg'];
        if (isset($dataFiles)) {
            if (!in_array($dataFiles->getMimeType(), $validMimeType)){
                return new JsonResponse([
                    'success' => false, 
                    'message' => 'Profile image is not valid, please upload a valid format.', 
                ], 404);
            }
        }

        $checkUser = $em->getRepository(User::class)->findOneBy(array('email'=> $params['email']));
        $errorFlag = 0;
        
        if ($checkUser && $checkUser->getId() != $id) {
            $errorFlag = 1;
        }

        if (!$errorFlag) {
            if (
                isset($params['password']['first']) && !empty(trim($params['password']['first'])) 
                && isset($params['password']['second'])  && !empty(trim($params['password']['second']))) {
                    if (trim($params['password']['first']) == trim($params['password']['second'])){
                        $encodedPassword = $passwordEncoder->encodePassword($user, $params['password']['first']);
                        $user->setPassword($encodedPassword);
                    } else {
                        return new JsonResponse([
                            'success' => false, 
                            'message' => 'Both password does not match together.', 
                        ], 404);
                    }
            } 

            $user->setFirstName($params['firstName']);
            $user->setLastName($params['lastName']);
            $user->setEmail($params['email']);
            $user->setIsEnabled(true);
            
            $userInstance = $em->getRepository(UserInstance::class)->findOneBy(array('user' => $id, 'supportRole' => array(1, 2, 3)));        
            $oldSupportTeam = ($supportTeamList = $userInstance != null ? $userInstance->getSupportTeams() : null) ? $supportTeamList->toArray() : [];
            $oldSupportGroup  = ($supportGroupList = $userInstance != null ? $userInstance->getSupportGroups() : null) ? $supportGroupList->toArray() : [];
            $oldSupportedPrivilege = ($supportPrivilegeList = $userInstance != null ? $userInstance->getSupportPrivileges() : null)? $supportPrivilegeList->toArray() : [];
            
            if (isset($params['role'])) {
                $role = $em->getRepository(SupportRole::class)->findOneBy(array('code' => $params['role']));
                $userInstance->setSupportRole($role);
            }

            if (isset($params['ticketView'])) {
                $userInstance->setTicketAccessLevel($params['ticketView']);
            }

            $userInstance->setDesignation($params['designation']);
            $userInstance->setContactNumber($params['contactNumber']);
            $userInstance->setSource('website');

            if (isset($dataFiles)) {
                // Removed profile image from database and path   
                $fileService = new Fileservice;
                if ($userInstance->getProfileImagePath()) {
                    $fileService->remove($this->getParameter('kernel.project_dir').'/public'.$userInstance->getProfileImagePath());
                }
                $assetDetails = $fileSystem->getUploadManager()->uploadFile($dataFiles, 'profile');
                $userInstance->setProfileImagePath($assetDetails['path']);
            } else {
                $userInstance->setProfileImagePath(null);
            }


            $userInstance->setSignature($params['signature']);
            $userInstance->setIsActive(isset($params['isActive']) ? $params['isActive'] : 0);

            // Team support to agent 
            if (isset($params['userSubGroup'])) {
                foreach ($params['userSubGroup'] as $userSubGroup) {
                    if ($userSubGrp = $uvdeskService->getEntityManagerResult(
                        SupportTeam::class,
                        'findOneBy', [
                            'id' => $userSubGroup
                        ]
                    ))

                    if (! $oldSupportTeam || !in_array($userSubGrp, $oldSupportTeam)) {
                        $userInstance->addSupportTeam($userSubGrp);

                    } elseif ($oldSupportTeam && ($key = array_search($userSubGrp, $oldSupportTeam)) !== false)
                        unset($oldSupportTeam[$key]);
                }

                foreach ($oldSupportTeam as $removeteam) {
                    $userInstance->removeSupportTeam($removeteam);
                    $em->persist($userInstance);
                }
            }

            //Group support  
            if (isset($params['groups'])) {
                foreach ($params['groups'] as $userGroup) {
                    if ($userGrp = $uvdeskService->getEntityManagerResult(
                        SupportGroup::class,
                        'findOneBy', [
                            'id' => $userGroup
                        ]
                    ))

                    if (!$oldSupportGroup || !in_array($userGrp, $oldSupportGroup)) {
                        $userInstance->addSupportGroup($userGrp);

                    } elseif ($oldSupportGroup && ($key = array_search($userGrp, $oldSupportGroup)) !== false)
                        unset($oldSupportGroup[$key]);
                }

                foreach ($oldSupportGroup as $removeGroup) {
                    $userInstance->removeSupportGroup($removeGroup);
                    $em->persist($userInstance);
                }
            }

            // Privilege support 
            if (isset($params['agentPrivilege'])){
                foreach ($params['agentPrivilege'] as $supportPrivilege) {
                    if ($supportPlg = $uvdeskService->getEntityManagerResult(
                        SupportPrivilege::class,
                        'findOneBy', [
                            'id' => $supportPrivilege
                        ]
                    ))

                    if (! $oldSupportedPrivilege || !in_array($supportPlg, $oldSupportedPrivilege)){
                        $userInstance->addSupportPrivilege($supportPlg);

                    } elseif ($oldSupportedPrivilege && ($key = array_search($supportPlg, $oldSupportedPrivilege)) !== false)
                        unset($oldSupportedPrivilege[$key]);
                }

                foreach ($oldSupportedPrivilege as $removeGroup) {
                    $userInstance->removeSupportPrivilege($removeGroup);
                    $em->persist($userInstance);
                }
            }

            $userInstance->setUser($user);
            $user->addUserInstance($userInstance);
            $em->persist($user);
            $em->persist($userInstance);
            $em->flush();

            // Trigger customer Update event
            $event = new CoreWorkflowEvents\Agent\Update();
            $event
                ->setUser($user)
            ;

            $eventDispatcher->dispatch($event, 'uvdesk.automation.workflow.execute');

            return new JsonResponse([
                'success' => true, 
                'message' => 'Agent updated successfully.', 
            ]);
            
        } else {
            return new JsonResponse([
                'success' => false, 
                'message' => 'User with same email is already exist.', 
            ], 404);
        }
    }

    public function deleteAgentRecord(Request $request, $agentId, EventDispatcherInterface $eventDispatcher, UserService $userService)
    {
        $entityManager = $this->getDoctrine()->getManager();
        if ($agentId) {
            $user = $entityManager->createQueryBuilder()
                ->select('u')
                ->from(User::class, 'u')
                ->leftJoin('u.userInstance', 'userInstance')
                ->where('u.id = :userId')->setParameter('userId', $agentId)
                ->andWhere('userInstance.supportRole != :roles')->setParameter('roles', 4)
                ->getQuery()
                ->getOneOrNullResult()
            ;
           
            if (empty($user)) {
                return new JsonResponse([
                    'success' => false, 
                    'message' => 'Agent information not found.', 
                ], 404);
            }
         
            if ($user->getAgentInstance()->getSupportRole()->getCode() != "ROLE_SUPER_ADMIN") {
                // Trigger agent delete event
                $event = new CoreWorkflowEvents\Agent\Delete();
                $event
                ->setUser($user)
                ;
                
                $eventDispatcher->dispatch($event, 'uvdesk.automation.workflow.execute');
                
                // Removing profile image from physical path
                $fileService = new Fileservice;
                
                if ($user->getAgentInstance()->getProfileImagePath()) {
                    $fileService->remove($this->getParameter('kernel.project_dir'). '/public' . $user->getAgentInstance()->getProfileImagePath());
                }
                
                $userService->removeAgent($user);

                return new JsonResponse([
                    'success' => true, 
                    'message' => 'Agent removed successfully.', 
                ]);

            } else {
                return new JsonResponse([
                    'success' => false, 
                    'message' => 'Authorization failed.', 
                ], 404);
            }
        }
    }
}