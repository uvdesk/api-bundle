<?php

namespace Webkul\UVDesk\ApiBundle\Repository;

use Webkul\UVDesk\ApiBundle\Entity\ApiAccessCredential;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Common\Persistence\ManagerRegistry;

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

    // /**
    //  * @return ApiAccessCredential[] Returns an array of ApiAccessCredential objects
    //  */
    /*
    public function findByExampleField($value)
    {
        return $this->createQueryBuilder('a')
            ->andWhere('a.exampleField = :val')
            ->setParameter('val', $value)
            ->orderBy('a.id', 'ASC')
            ->setMaxResults(10)
            ->getQuery()
            ->getResult()
        ;
    }
    */

    /*
    public function findOneBySomeField($value): ?ApiAccessCredential
    {
        return $this->createQueryBuilder('a')
            ->andWhere('a.exampleField = :val')
            ->setParameter('val', $value)
            ->getQuery()
            ->getOneOrNullResult()
        ;
    }
    */
}
