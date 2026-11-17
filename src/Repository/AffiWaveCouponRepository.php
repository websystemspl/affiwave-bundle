<?php

declare(strict_types=1);

namespace AffiWave\Bundle\Repository;

use AffiWave\Bundle\Entity\AffiWaveCoupon;
use Doctrine\ORM\EntityManagerInterface;

class AffiWaveCouponRepository
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function findOneByAffiwaveCouponId(int $id): ?AffiWaveCoupon
    {
        return $this->em->getRepository(AffiWaveCoupon::class)->findOneBy(['affiwaveCouponId' => $id]);
    }

    public function findOneByStripePromotionCodeId(string $id): ?AffiWaveCoupon
    {
        return $this->em->getRepository(AffiWaveCoupon::class)->findOneBy(['stripePromotionCodeId' => $id]);
    }

    /** Is at least one AffiWave promotion code usable in Stripe right now (active, synced, not expired). */
    public function hasActive(): bool
    {
        $count = $this->em->createQueryBuilder()
            ->select('COUNT(c.id)')
            ->from(AffiWaveCoupon::class, 'c')
            ->where('c.active = :active')
            ->andWhere('c.syncError IS NULL')
            ->andWhere('c.expiresAt IS NULL OR c.expiresAt > :now')
            ->setParameter('active', true)
            ->setParameter('now', new \DateTimeImmutable())
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $count > 0;
    }
}
