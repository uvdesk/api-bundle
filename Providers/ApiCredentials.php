<?php

namespace Webkul\UVDesk\ApiBundle\Providers;

use Doctrine\ORM\EntityManagerInterface;
use Webkul\UVDesk\CoreFrameworkBundle\Entity\User;
use Webkul\UVDesk\CoreFrameworkBundle\Entity\UserInstance;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Bundle\SecurityBundle\Security\FirewallMap;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\Exception\UsernameNotFoundException;

class ApiCredentials implements UserProviderInterface
{
    public function __construct(FirewallMap $firewall, ContainerInterface $container, RequestStack $requestStack, EntityManagerInterface $entityManager)
    {
        $this->firewall = $firewall;
        $this->container = $container;
        $this->requestStack = $requestStack; 
        $this->entityManager = $entityManager;
    }

    public function loadUserByUsername($username)
    {
        $queryBuilder = $this->entityManager->createQueryBuilder()
            ->select('user, userInstance')
            ->from(User::class, 'user')
            ->leftJoin(UserInstance::class, 'userInstance', 'WITH', 'user.id = userInstance.user')
            ->leftJoin('userInstance.supportRole', 'supportRole')
            ->where('user.email = :email')->setParameter('email', trim($username))
            ->andWhere('userInstance.isActive = :isActive')->setParameter('isActive', true)
            ->andWhere('supportRole.id = :roleOwner OR supportRole.id = :roleAdmin OR supportRole.id = :roleAgent')
            ->setParameter('roleOwner', 1)
            ->setParameter('roleAdmin', 2)
            ->setParameter('roleAgent', 3)
            ->setMaxResults(1)
        ;
        
        $response = $queryBuilder->getQuery()->getResult();

        try {
            if (!empty($response) && is_array($response)) {
                list($user, $userInstance) = $response;

                // Set currently active instance
                $user->setCurrentInstance($userInstance);
                $user->setRoles((array) $userInstance->getSupportRole()->getCode());

                return $user;
            }
        } catch (\Exception $e) {
            // Do nothing...
        }

        throw new UsernameNotFoundException(sprintf('Username "%s" does not exist.', $username));
    }

    public function refreshUser(UserInterface $user)
    {
        
        if ($this->supportsClass(get_class($user))) {
            return $this->loadUserByUsername($user->getEmail());
        }

        throw new UnsupportedUserException('Invalid user type');
    }

    public function supportsClass($class)
    {
        return User::class === $class;
    }
}
