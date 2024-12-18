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

class Team extends AbstractController
{
    public function loadTeams(Request $request, ContainerInterface $container)
    {
        $teamCollection = $this->getDoctrine()->getRepository(SupportTeam::class)->getAllSupportTeams($request->query, $container);

        if(empty($teamCollection)){
            return new JsonResponse([
                'success' => false, 
                'message' => " No record found.", 
            ], 404);
        }

        return new JsonResponse([
            'success'    => true, 
            'collection' => $teamCollection
        ]);
    }

    public function loadTeamsDetails(Request $request, $teamId)
    {
        $team = $this->getDoctrine()->getRepository(SupportTeam::class)->findOneById($teamId);
        
        if (empty($team)) {
            return new JsonResponse([
                'success' => false, 
                'message' => " No team details were found with id '$teamId'.", 
            ], 404);
        }

        $teamDetails = [
            'id'          => $team->getId(),
            'name'        => $team->getName(),
            'description' => $team->getDescription(),
            'isActive'    => $team->getIsActive() 
        ];

        return new JsonResponse([
            'success' => true,
            'team'    => $teamDetails,
        ]);
    }

    public function createTeams(Request $request, ContainerInterface $container)
    {
        $params = $request->request->all()? : json_decode($request->getContent(),true);
        
        foreach ($params as $key => $value) {
            if (!in_array($key, ['name', 'description','isActive','users','tempUsers','tempGroups','groups'])) {
                unset($params[$key]);
            }
        }
        
        if (empty($params['name']) || empty($params['description']) || empty($params['users']) || empty($params['groups'])) {
            $json['error'] = $container->get('translator')->trans('required fields: name,description,groups and users.');
            
            return new JsonResponse($json, Response::HTTP_BAD_REQUEST);
        }
        
        $supportTeam = new SupportTeam();
        $em = $this->getDoctrine()->getManager();

        $request->request->set('users', explode(',', $request->request->get('tempUsers')));
        $request->request->set('groups', explode(',', $request->request->get('tempGroups')));
        
        $oldUsers = ($usersList = $supportTeam->getUsers()) ? $usersList->toArray() : $usersList;
        $oldGroups = ($grpList =  $supportTeam->getSupportGroups()) ? $grpList->toArray() : $grpList;

        $supportTeam->setName($params['name']);
        $supportTeam->setDescription($params['description']);
        $supportTeam->setIsActive((bool) isset($params['isActive']));
        $em->persist($supportTeam);

        $usersList = (!empty($params['users']))? $params['users'] : [];
        $usersGroup  = (!empty($params['groups']))? $params['groups'] : [];

        if (!empty($usersList)) {
            $usersList = array_map(function ($user) { return 'user.id = ' . $user; }, $usersList);

            $userList = $em->createQueryBuilder()->select('user')
                ->from(User::class, 'user')
                ->where(implode(' OR ', $usersList))
                ->getQuery()->getResult()
            ;
        }
        
        if (!empty($usersGroup)) {
            $usersGroup = array_map(function ($group) { return 'p.id = ' . $group; }, $usersGroup);

            $userGroup = $em->createQueryBuilder('p')->select('p')
                ->from(SupportGroup::class, 'p')
                ->where(implode(' OR ', $usersGroup))
                ->getQuery()->getResult()
            ;
        }
        
        foreach ($userList as $user) {
            $userInstance = $user->getAgentInstance();
            $userInstance->addSupportTeam($supportTeam);
            $em->persist($userInstance);
        }
        
        // Add Teams to Group
        foreach ($userGroup as $supportGroup) {
            $supportGroup->addSupportTeam($supportTeam);
            $em->persist($supportGroup);
        }

        $em->persist($supportTeam);
        $em->flush();

        return new JsonResponse([
            'success' => true,
            'message' => 'Team information saved successfully.'
        ]);
    }

    public function updateTeamsDetails(Request $request, $teamId, ContainerInterface $container)
    {
        $supportTeam = $this->getDoctrine()->getRepository(SupportTeam::class)->findSubGroupById(['id' => $teamId]);
        
        if (empty($supportTeam)) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Team not found.'
            ],404);
        }
        
        $params = $request->request->all()? : json_decode($request->getContent(), true);
        $em = $this->getDoctrine()->getManager();
        
        foreach($params as $key => $value) {
            if (!in_array($key, ['name', 'description','isActive','users','tempUsers','tempGroups','groups'])) {
                unset($params[$key]);
            }
        }
        
        if (empty($params['name']) || empty($params['description']) || empty($params['users']) || empty($params['groups'])) {
            $json['error'] = $container->get('translator')->trans('required fields: name,description,groups and users.');
            
            return new JsonResponse($json, Response::HTTP_BAD_REQUEST);
        }

        $request->request->set('users', explode(',', $request->request->get('tempUsers')));
        $request->request->set('groups', explode(',', $request->request->get('tempGroups')));
        $oldUsers = ($usersList = $supportTeam->getUsers()) ? $usersList->toArray() : $usersList;
        $oldGroups = ($grpList = $supportTeam->getSupportGroups()) ? $grpList->toArray() : $grpList;

        $supportTeam->setName($params['name']);
        $supportTeam->setDescription($params['description']);
        $supportTeam->setIsActive((bool) isset($params['isActive']));

        $usersList = (!empty($params['users']))? $params['users'] : [];
        $usersGroup  = (!empty($params['groups']))? $params['groups'] : [];

        if (!empty($usersList)) {
            $usersList = array_map(function ($user) { return 'p.id = ' . $user; }, $usersList);
            
            $userList = $em->createQueryBuilder('p')->select('p')
                ->from(User::class, 'p')
                ->where(implode(' OR ', $usersList))
                ->getQuery()->getResult()
            ;
        }

        if (!empty($usersGroup)) {
            $usersGroup = array_map(function ($group) { return 'p.id = ' . $group; }, $usersGroup);

            $userGroup = $em->createQueryBuilder('p')->select('p')
                ->from(SupportGroup::class, 'p')
                ->where(implode(' OR ', $usersGroup))
                ->getQuery()->getResult()
            ;
        }

        foreach ($userList as $user) {
            $userInstance = $user->getAgentInstance();
            if (!$oldUsers || !in_array($userInstance, $oldUsers)) {
                $userInstance->addSupportTeam($supportTeam);
                $em->persist($userInstance);
            } elseif ($oldUsers && ($key = array_search($userInstance, $oldUsers)) !== false)
                unset($oldUsers[$key]);
        }

        foreach ($oldUsers as $removeUser) {
            $removeUser->removeSupportTeam($supportTeam);
            $em->persist($removeUser);
        }

        // Add Group to team
        foreach ($userGroup as $supportGroup) {
            if (!$oldGroups || !in_array($supportGroup, $oldGroups)) {
                $supportGroup->addSupportTeam($supportTeam);
                $em->persist($supportGroup);

            } elseif ($oldGroups && ($key = array_search($supportGroup, $oldGroups)) !== false)
                unset($oldGroups[$key]);
        }

        foreach ($oldGroups as $removeGroup) {
            $removeGroup->removeSupportTeam($supportTeam);
            $em->persist($removeGroup);
        }

        $em->persist($supportTeam);
        $em->flush();

        return new JsonResponse([
            'success' => true,
            'message' => 'Team information updated successfully.'
        ]);
    }

    public function deleteTeamsDetails(Request $request, $teamId)
    {
        $entityManager = $this->getDoctrine()->getManager();
        $supportTeam = $entityManager->getRepository(SupportTeam::class)->findOneById($teamId);
        if (empty($supportTeam)) {
            
            return new Response(json_encode([
                'success' => 'success',
                'message' => 'Team not found.'
            ]), 404);
        }

        $entityManager->remove($supportTeam);
        $entityManager->flush();

        return new JsonResponse([
            'success' => true,
            'message' => 'Team removed successfully.'
        ]);
    }
}