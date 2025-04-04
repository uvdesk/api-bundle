<?php

namespace Webkul\UVDesk\ApiBundle\API;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Webkul\UVDesk\CoreFrameworkBundle\Entity\SupportGroup;
use Webkul\UVDesk\CoreFrameworkBundle\Entity\SupportTeam;
use Webkul\UVDesk\CoreFrameworkBundle\Entity\User;
use Webkul\UVDesk\CoreFrameworkBundle\Entity\UserInstance;

class Group extends AbstractController
{
    public function loadGroup(Request $request, ContainerInterface $container)
    {
        $groupCollection = $this->getDoctrine()->getRepository(SupportGroup::class)->getAllGroups($request->query, $container);
        if (empty($groupCollection)) {
            return new JsonResponse([
                'success' => false, 
                'message' => " No record found.", 
            ], 404);
        }

        return new JsonResponse([
            'success'    => true, 
            'collection' => !empty($groupCollection) ? $groupCollection : [], 
        ]);
    }

    public function loadGroupDetails(Request $request, $id)
    {
        $group = $this->getDoctrine()->getRepository(SupportGroup::class)->findOneById($id);

        if (empty($group)) {
            return new JsonResponse([
                'success' => false, 
                'message' => " No group details were found with id '$id'.", 
            ], 404);
        }
        
        $groupDetails = [
            'id'          => $group->getId(),
            'name'        => $group->getName(),
            'description' => $group->getDescription(),
            'isActive'    => $group->getIsActive() 
        ];

        return new JsonResponse([
            'success' => true, 
            'group'   => $groupDetails, 
        ]);
    }

    public function createGroupRecord(Request $request, ContainerInterface $container)
    {
        $params = $request->request->all()? : json_decode($request->getContent(),true);
        
        foreach ($params as $key => $value) {
            if (!in_array($key, ['name', 'description','isActive','users','supportTeams'])) {
                unset($params[$key]);
            }
        }
        
        if (empty($params['name']) || empty($params['description'])) {
            $json['error'] = $container->get('translator')->trans('required fields: name and description.');
            
            return new JsonResponse($json, Response::HTTP_BAD_REQUEST);
        }
        
        $group = new SupportGroup;
        $em = $this->getDoctrine()->getManager();
        $group->setName($params['name']);
        $group->setDescription($params['description']);
        $group->setIsActive((bool) isset($params['isActive']));
        $usersList = (!empty($params['users'])) ? $params['users'] : [];
        $userTeam  = (!empty($params['supportTeams'])) ? $params['supportTeams'] : [];
        
        if (!empty($usersList)) {
            $usersList = array_map(function ($user) { return 'user.id = ' . $user; }, $usersList);
            
            $userList = $em->createQueryBuilder()->select('user')
                ->from(User::class, 'user')
                ->where(implode(' OR ', $usersList))
                ->getQuery()->getResult()
            ;
        }
        
        if (!empty($userTeam)) {
            $userTeam = array_map(function ($team) { return 'team.id = ' . $team; }, $userTeam);

            $userTeam = $em->createQueryBuilder()->select('team')
                ->from(SupportTeam::class, 'team')
                ->where(implode(' OR ', $userTeam))
                ->getQuery()->getResult()
            ;
        }

        if (!empty($userList)) {
            foreach ($userList as $user) {
                $userInstance = $user->getAgentInstance();

                $userInstance->addSupportGroup($group);
                $em->persist($userInstance);
            }
        }

        // Add Teams to Group
        foreach ($userTeam as $supportTeam) {
            $group->addSupportTeam($supportTeam);
        }

        $em->persist($group);
        $em->flush();

        return new JsonResponse([
            'success' => true, 
            'group'   => 'Group information saved successfully.' 
        ]);
    }
    
    public function updateGroupRecord(Request $request, $groupId, ContainerInterface $container)
    {
        $params = $request->request->all()? : json_decode($request->getContent(),true);
        
        foreach ($params as $key => $value) {
            if(!in_array($key, ['name', 'description','isActive','users','supportTeams'])) {
                unset($params[$key]);
            }
        }
        
        if (empty($params['name']) || empty($params['description'])) {
            $json['error'] = $container->get('translator')->trans('required fields: name and description.');
            
            return new JsonResponse($json, Response::HTTP_BAD_REQUEST);
        }

        $group = new SupportGroup;
        $em = $this->getDoctrine()->getManager();

        if ($groupId) {
            $group = $this->getDoctrine()->getRepository(SupportGroup::class)->findGroupById(['id' => $groupId]);
            if (empty($group)){
                return new JsonResponse([
                    'success' => true, 
                    'group'   => 'Support group not found.' 
                ]);  
            }

            $request->request->replace($params);

            if ($params('tempUsers')) {
                $request->request->set('users', explode(',', $params('tempUsers')));
            }
            
            if ($params('tempTeams')) {
                $request->request->set('supportTeams', explode(',', $params('tempTeams')));
            }
            
            $oldUsers = ($usersList = $group->getUsers()) ? $usersList->toArray() : [];
            $oldTeam  = ($teamList = $group->getSupportTeams()) ? $teamList->toArray() : [];
            
            $group->setName($params['name']);
            $group->setDescription($params['description']);
            $group->setIsActive((bool) isset($params['isActive']));
            
            $usersList = (!empty($params['users']))? $params['users'] : [];
            $userTeam  = (!empty($params['supportTeams']))? $params['supportTeams'] : [];

            if (!empty($usersList)) {
                $usersList = array_map(function ($user) { return 'user.id = ' . $user; }, $usersList);
                $userList = $em->createQueryBuilder()->select('user')
                    ->from(User::class, 'user')
                    ->where(implode(' OR ', $usersList))
                    ->getQuery()->getResult()
                ;
            }
            
            if (!empty($userTeam)) {
                $userTeam = array_map(function ($team) { return 'team.id = ' . $team; }, $userTeam);
                
                $userTeam = $em->createQueryBuilder()->select('team')
                    ->from(SupportTeam::class, 'team')
                    ->where(implode(' OR ', $userTeam))
                    ->getQuery()->getResult()
                ;
            }
            
            if (!empty($userList)) {
                // Add Users to Group
                foreach ($userList as $user) {
                    $userInstance = $user->getAgentInstance();

                    if (!$oldUsers || !in_array($userInstance, $oldUsers)) {
                        $userInstance->addSupportGroup($group);
                        $em->persist($userInstance);
                    } elseif ($oldUsers && ($key = array_search($userInstance, $oldUsers)) !== false)
                        unset($oldUsers[$key]);
                }

                foreach ($oldUsers as $removeUser) {
                    $removeUser->removeSupportGroup($group);
                    $em->persist($removeUser);
                }
                
            } else {
                foreach ($oldUsers as $removeUser) {
                    $removeUser->removeSupportGroup($group);
                    $em->persist($removeUser);
                }
            }

            if (!empty($userTeam)) {
                // Add Teams to Group
                foreach ($userTeam as $supportTeam) {
                    if (!$oldTeam || !in_array($supportTeam, $oldTeam)){
                        $group->addSupportTeam($supportTeam);
                    } elseif ($oldTeam && ($key = array_search($supportTeam, $oldTeam)) !== false)
                        unset($oldTeam[$key]);
                }

                foreach ($oldTeam as $removeTeam) {
                    $group->removeSupportTeam($removeTeam);
                    $em->persist($group);
                }

            } else {
                foreach ($oldTeam as $removeTeam) {
                    $group->removeSupportTeam($removeTeam);
                    $em->persist($group);
                }
            }

            $em->persist($group);
            $em->flush();
            
            return new JsonResponse([
                'success' => true, 
                'group'   => 'Group information update successfully.' 
            ]);   
        }
    }

    public function deleteGroupRecord(Request $request, $groupId)
    {
        $entityManager = $this->getDoctrine()->getManager();
        $supportGroup = $entityManager->getRepository(SupportGroup::class)->findOneById($groupId);
        
        if (empty($supportGroup)) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Support Group not found.',
            ], 404);
        }

        $entityManager->remove($supportGroup);
        $entityManager->flush();
        
        return new JsonResponse([
            'success' => true, 
            'group'   => 'Support Group removed successfully.'
        ]);
    }

}