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
use Webkul\UVDesk\CoreFrameworkBundle\Services\UserService;

class Customers extends AbstractController
{
    public function loadCustomers(Request $request, EntityManagerInterface $entityManager)
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
            ->where('userInstance.supportRole = :roles')
            ->setParameter('roles', 4)
        ;

        $collection = $qb->getQuery()->getResult();

        return new JsonResponse([
            'success' => true, 
            'collection' => $collection, 
        ]);
    }

    public function loadCustomerDetails($id, Request $request)
    {
        $user = $this->getDoctrine()->getRepository(User::class)->findOneById($id);

        if (empty($user)) {
            return new JsonResponse([
                'success' => false, 
                'message' => "No customer account details were found with id '$id'.", 
            ], 404);
        }

        $customerDetails = [
            'id' => $user->getId(), 
            'firstName' => $user->getFirstName(),
            'lastName' => $user->getLastName(),
            'userEmail' => $user->getUsername(),
            'isEnabled' => $user->getIsEnabled(),
            'isActive' => $user->getCustomerInstance()->getIsActive(),
            'isVerified' => $user->getCustomerInstance()->getIsVerified(),
            'contactNumber' => $user->getCustomerInstance()->getContactNumber()
        ];

        return new JsonResponse([
            'success' => true, 
            'customer' => $customerDetails
        ]);
    }

    public function createCustomerRecored(Request $request, EntityManagerInterface $entityManager, UserService $userService)
    {
        $params = $request->request->all();
        $user = $entityManager->getRepository(User::class)->findOneBy(array('email' => $params['email']));
        $customerInstance = !empty($user) ? $user->getCustomerInstance() : null;
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

        if (!empty($customerInstance)) {
            return new JsonResponse([
                'success' => false, 
                'message' => 'User with same email already exist.', 
            ],404);
        }

        $fullname = trim(implode(' ', [$params['firstName'], $params['lastName']]));
        $supportRole = $entityManager->getRepository(SupportRole::class)->findOneByCode('ROLE_CUSTOMER');
        
        $user = $userService->createUserInstance($params['email'], $fullname, $supportRole, [
            'contact' => $params['contactNumber'],
            'source' => 'website',
            'active' => !empty($params['isActive']) ? true : false,
            'image' => $uploadedFiles,
        ]);

        if(!empty($user)){
            $user->setIsEnabled(true);
            $entityManager->persist($user);
            $entityManager->flush();
        }

        return new JsonResponse([
            'success' => true, 
            'message' => 'Customer saved successfully.', 
        ]);
    }
}
