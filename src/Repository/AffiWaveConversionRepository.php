<?php

declare(strict_types=1);

namespace AffiWave\Bundle\Repository;

use AffiWave\Bundle\Entity\AffiWaveConversion;
use AffiWave\Bundle\Entity\ConversionStatus;
use Doctrine\ORM\EntityManagerInterface;

class AffiWaveConversionRepository
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function findOneByExternalOrderId(string $externalOrderId): ?AffiWaveConversion
    {
        return $this->em->getRepository(AffiWaveConversion::class)->findOneBy(['externalOrderId' => $externalOrderId]);
    }

    /**
     * Conversions still to deliver (pending or failed), least recently attempted first, so old stuck rows do not starve new ones.
     *
     * @return list<AffiWaveConversion>
     */
    public function findRetryable(int $limit = 100): array
    {
        /** @var list<AffiWaveConversion> */
        return $this->em->createQueryBuilder()
            ->select('c', 'COALESCE(c.lastAttemptAt, c.createdAt) AS HIDDEN lastTouch')
            ->from(AffiWaveConversion::class, 'c')
            ->where('c.status IN (:statuses)')
            ->setParameter('statuses', [ConversionStatus::Pending, ConversionStatus::Failed])
            ->orderBy('lastTouch', 'ASC')
            ->addOrderBy('c.id', 'ASC')
            ->setMaxResults(max(1, $limit))
            ->getQuery()
            ->getResult();
    }
}
