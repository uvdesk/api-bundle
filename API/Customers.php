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
use Webkul\UVDesk\CoreFrameworkBundle\Entity\UserInstance;
use Webkul\UVDesk\CoreFrameworkBundle\Services\UserService;
use Webkul\UVDesk\CoreFrameworkBundle\FileSystem\FileSystem;
use Webkul\UVDesk\CoreFrameworkBundle\Workflow\Events as CoreWorkflowEvents;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Filesystem\Filesystem as Fileservice;

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
            'success'    => true, 
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
            'id'            => $user->getId(), 
            'firstName'     => $user->getFirstName(),
            'lastName'      => $user->getLastName(),
            'userEmail'     => $user->getUsername(),
            'isEnabled'     => $user->getIsEnabled(),
            'isActive'      => $user->getCustomerInstance()->getIsActive(),
            'isVerified'    => $user->getCustomerInstance()->getIsVerified(),
            'contactNumber' => $user->getCustomerInstance()->getContactNumber()
        ];

        return new JsonResponse([
            'success'  => true, 
            'customer' => $customerDetails
        ]);
    }

    public function createCustomerRecord(Request $request, ContainerInterface $container, EntityManagerInterface $entityManager, UserService $userService)
    {
        $params = $request->request->all()? : json_decode($request->getContent(),true);
        foreach($params as $key => $value) {
            if (!in_array($key, ['email', 'user_form', 'firstName', 'lastName','contactNumber','isActive'])) {
                unset($params[$key]);
            }
        }

        if (empty($params['email']) || empty($params['firstName'])) {
            $json['error'] = $container->get('translator')->trans('required fields: email and firstName.');
            
            return new JsonResponse($json, Response::HTTP_BAD_REQUEST);
        }

        $user = $entityManager->getRepository(User::class)->findOneBy(array('email' => $params['email']));
        $customerInstance = !empty($user) ? $user->getCustomerInstance() : null;
        $uploadedFiles = $request->files->get('user_form');

        // Profile upload validation
        $validMimeType = ['image/jpeg', 'image/png', 'image/jpg'];

        if (isset( $uploadedFiles)) {
            if (!in_array($uploadedFiles->getMimeType(), $validMimeType)) {
                return new JsonResponse([
                    'success' => false, 
                    'message' => 'Profile image is not valid, please upload a valid format.', 
                ], 404);
            }
        }

        if (!empty($customerInstance)) {
            return new JsonResponse([
                'success' => false, 
                'message' => 'User with same email already exist.', 
            ], 404);
        }

        $fullname = trim(implode(' ', [$params['firstName'], $params['lastName']]));
        $supportRole = $entityManager->getRepository(SupportRole::class)->findOneByCode('ROLE_CUSTOMER');
        
        $user = $userService->createUserInstance($params['email'], $fullname, $supportRole, [
            'contact' => $params['contactNumber'],
            'source'  => 'website',
            'active'  => !empty($params['isActive']) ? true : false,
            'image'   => $uploadedFiles,
        ]);

        if (!empty($user)) {
            $user->setIsEnabled(true);
            $entityManager->persist($user);
            $entityManager->flush();
        }

        return new JsonResponse([
            'success' => true, 
            'message' => 'Customer saved successfully.', 
        ]);
    }


    public function updateCustomerRecord($id, Request $request, FileSystem $fileSystem, ContainerInterface $container, EventDispatcherInterface $eventDispatcher, UserPasswordEncoderInterface $passwordEncoder)
    {
        $params = $request->request->all()? : json_decode($request->getContent(),true);
        foreach ($params as $key => $value) {
            if (!in_array($key, ['email', 'user_form', 'firstName', 'lastName','contactNumber','isActive'])) {
                unset($params[$key]);
            }
        }

        if (empty($params['email']) || empty($params['firstName'])) {
            $json['error'] = $container->get('translator')->trans('required fields: email and firstName.');
            
            return new JsonResponse($json, Response::HTTP_BAD_REQUEST);
        }

        $dataFiles = $request->files->get('user_form');
        $em = $this->getDoctrine()->getManager();
        $repository = $em->getRepository(User::class);
        
        if ($id) {
            $user = $repository->findOneBy(['id' =>  $id]);
            if (!$user) {
                $id = $id;
                return new JsonResponse([
                    'success' => false, 
                    'message' => "User not found with this id '$id' ."
                ], 404);
            }    
        }
        
        // Customer Profile upload validation
        $validMimeType = ['image/jpeg', 'image/png', 'image/jpg'];
        if (isset($dataFiles)) {
            if (!in_array($dataFiles->getMimeType(), $validMimeType)) {
                return new JsonResponse([
                    'success' => false, 
                    'message' => 'Profile image is not valid, please upload a valid format', 
                ],404);
            }
        }
        
        if ($id) {
            $checkUser = $em->getRepository(User::class)->findOneBy(array('email' => $params['email']));
            $errorFlag = 0;
            
            if ($checkUser) {
                if($checkUser->getId() != $id)
                $errorFlag = 1;
            }
            
            if (!$errorFlag && 'hello@uvdesk.com' !== $user->getEmail()) {
                if (
                    isset($params['password']['first']) 
                    && !empty(trim($params['password']['first'])) 
                    && isset($params['password']['second'])  
                    && !empty(trim($params['password']['second']))
                ) {
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
                
                $email = $user->getEmail();
                $user->setFirstName($params['firstName']);
                $user->setLastName($params['lastName']);
                $user->setEmail($email);
                $user->setIsEnabled(true);
                $em->persist($user);
                
                // User Instance
                $userInstance = $em->getRepository(UserInstance::class)->findOneBy(array('user' => $user->getId(), 'supportRole' => 4));
                $userInstance->setUser($user);
                $userInstance->setIsActive(isset($params['isActive']) ? $params['isActive'] : 0);
                $userInstance->setIsVerified(0);
                
                if (isset($params['contactNumber'])) {
                    $userInstance->setContactNumber($params['contactNumber']);
                }
                
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
                
                $em->persist($userInstance);
                $em->flush();
                
                $user->addUserInstance($userInstance);
                $em->persist($user);
                $em->flush();

                // Trigger customer created event
                $event = new CoreWorkflowEvents\Customer\Update();
                $event
                    ->setUser($user)
                ;
                
                $eventDispatcher->dispatch($event, 'uvdesk.automation.workflow.execute');

                return new JsonResponse([
                    'success' => true, 
                    'message' => 'Customer updated successfully.', 
                ]);
                
            }
        }

        return new JsonResponse([
            'success' => false, 
            'message' => "Invalid credentials provided."
        ], 404);
    }

    public function deleteCustomerRecord(Request $request, $customerId, UserService $userService, EventDispatcherInterface $eventDispatcher)
    {
        $em = $this->getDoctrine()->getManager();
        $user = $em->getRepository(User::class)->findOneBy(['id' => $customerId]);
        
        if (empty($user)) {
            return new JsonResponse([
                'success' => false, 
                'message' => "Customer not found with this id '$customerId'."
            ],404);
        }
        
        $userInstance = $em->getRepository(UserInstance::class)->findOneBy(array('user' => $user->getId(), 'supportRole' => 4));

        if (empty($userInstance)) {
            return new JsonResponse([
                'success' => false, 
                'message' => "Authorization failed."
            ], 404);
        }

        $userService->removeCustomer($user);
        // Trigger customer created event
        $event = new CoreWorkflowEvents\Customer\Delete();
        $event
            ->setUser($user)
        ;

        $eventDispatcher->dispatch($event, 'uvdesk.automation.workflow.execute');

        return new JsonResponse([
            'success' => true, 
            'message' => "Customer removed successfully."
        ]);
    }
}