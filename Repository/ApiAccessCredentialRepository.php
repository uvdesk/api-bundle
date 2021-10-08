<?php

namespace Webkul\UVDesk\ApiBundle\Repository;

use Webkul\UVDesk\ApiBundle\Entity\ApiAccessCredential;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @method ApiAccessCredential|null find($id, $lockMode = null, $lockVersion = null)
 * @method ApiAccessCredential|null findOneBy(array $criteria, array $orderBy = null)
 * @method ApiAccessCredential[]    findAll()
 * @method ApiAccessCredential[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class ApiAccessCredentialRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ApiAccessCredential::class);
    }

    // Get User by access token
    public function getUserEmailByAccessToken($accessToken) {
        $qb = $this->getEntityManager()->createQueryBuilder();
        $qb->select('user.email')->from($this->getEntityName(), 'aac')
            ->leftJoin('aac.user', 'user')
            ->andWhere('aac.token = :accessToken')
            ->andWhere('aac.isEnabled = 1')
            ->setParameter('accessToken', $accessToken);

        return $qb->getQuery()->getOneOrNullResult();
    }
}
