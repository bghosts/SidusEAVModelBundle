<?php
/*
 *  Sidus/EAVModelBundle : EAV Data management in Symfony 3
 *  Copyright (C) 2015-2017 Vincent Chalnot
 *
 *  This program is free software: you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation, either version 3 of the License, or
 *  (at your option) any later version.
 *
 *  This program is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  You should have received a copy of the GNU General Public License
 *  along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

namespace Sidus\EAVModelBundle\Entity;

use Doctrine\Common\Proxy\Proxy;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Mapping\MappingException;
use Doctrine\ORM\NonUniqueResultException;
use Doctrine\ORM\NoResultException;
use Doctrine\ORM\ORMException;
use Doctrine\ORM\Query;
use Doctrine\ORM\Query\Expr\Join;
use Doctrine\ORM\QueryBuilder;
use Sidus\EAVModelBundle\Doctrine\EAVQueryBuilder;
use Sidus\EAVModelBundle\Doctrine\SingleFamilyQueryBuilder;
use Sidus\EAVModelBundle\Model\AttributeInterface;
use Sidus\EAVModelBundle\Model\FamilyInterface;

/**
 * Base repository for Data
 *
 * The $partialLoad option triggers the Query Hint HINT_FORCE_PARTIAL_LOAD
 *
 * @author Vincent Chalnot <vincent@sidus.fr>
 */
class DataRepository extends EntityRepository
{
    /**
     * Find data based on it's family identifier
     *
     * @param FamilyInterface $family
     * @param int|string      $reference
     * @param bool            $idFallback
     * @param bool            $partialLoad
     *
     * @throws NonUniqueResultException
     * @throws \UnexpectedValueException
     * @throws ORMException
     * @throws NoResultException
     * @throws MappingException
     *
     * @return null|DataInterface
     * @throws \LogicException
     */
    public function findByIdentifier(FamilyInterface $family, $reference, $idFallback = false, $partialLoad = false)
    {
        $identifierAttribute = $family->getAttributeAsIdentifier();
        if (!$identifierAttribute) {
            if (!$idFallback) {
                $m = "Cannot find data with no identifier attribute for family: '{$family->getCode()}'";
                throw new \UnexpectedValueException($m);
            }

            return $this->findByPrimaryKey($family, $reference, $partialLoad);
        }

        return $this->findByUniqueAttribute($family, $identifierAttribute, $reference, $partialLoad);
    }

    /**
     * Find a data based on a unique attribute
     *
     * @param FamilyInterface    $family
     * @param AttributeInterface $attribute
     * @param string|int         $reference
     * @param bool               $partialLoad
     *
     * @return Proxy|mixed|null|DataInterface
     * @throws \LogicException
     * @throws \Doctrine\ORM\Mapping\MappingException
     * @throws \Doctrine\ORM\ORMException
     * @throws NonUniqueResultException
     */
    public function findByUniqueAttribute(
        FamilyInterface $family,
        AttributeInterface $attribute,
        $reference,
        $partialLoad = false
    ) {
        if (!$attribute->isUnique()) {
            throw new \LogicException("Cannot find data based on a non-unique attribute '{$attribute->getCode()}'");
        }
        $dataBaseType = $attribute->getType()->getDatabaseType();
        $qb = $this->createQueryBuilder('e');
        $joinCondition = "(identifier.attributeCode = :attributeCode AND identifier.{$dataBaseType} = :reference)";
        $qb
            ->join('e.values', 'identifier', Join::WITH, $joinCondition)
            ->where('e.family = :familyCode')
            ->setParameters(
                [
                    'attributeCode' => $attribute->getCode(),
                    'reference' => $reference,
                    'familyCode' => $family->getCode(),
                ]
            );

        if ($partialLoad) {
            return $this->executeWithPartialLoad($qb);
        }

        // Optimize values querying, @T0D0 check if really a good idea ?
        $qb
            ->addSelect('values')
            ->join('e.values', 'values');

        return $qb->getQuery()->getOneOrNullResult();
    }

    /**
     * Find by the Data entity primary key like a find()
     *
     * @param FamilyInterface $family
     * @param string|int      $reference
     * @param bool            $partialLoad
     *
     * @throws \Doctrine\ORM\ORMException
     *
     * @return Proxy|null|DataInterface
     */
    public function findByPrimaryKey(
        FamilyInterface $family,
        $reference,
        $partialLoad = false
    ) {
        $identifierColumn = $this->getPkColumn($family);

        return $this->findByIdentifierColumn($family, $identifierColumn, $reference, $partialLoad);
    }

    /**
     * Find data based on a identifier column present in the Data entity
     *
     * @param FamilyInterface $family
     * @param string          $identifierColumn
     * @param int|string      $reference
     * @param bool            $partialLoad
     *
     * @throws ORMException
     *
     * @return DataInterface|Proxy|null
     */
    public function findByIdentifierColumn(
        FamilyInterface $family,
        $identifierColumn,
        $reference,
        $partialLoad = false
    ) {
        if (!$partialLoad) {
            return $this->findOneBy(
                [
                    $identifierColumn => $reference,
                    'family' => $family,
                ]
            );
        }

        $qb = $this->createQueryBuilder('e')
            ->where("e.{$identifierColumn} = :reference")
            ->andWhere('e.family = :familyCode')
            ->setParameters(
                [
                    'reference' => $reference,
                    'familyCode' => $family->getCode(),
                ]
            );

        return $this->executeWithPartialLoad($qb);
    }

    /**
     * Return singleton for a given family
     *
     * @param FamilyInterface $family
     *
     * @throws \LogicException
     * @throws \Doctrine\ORM\NonUniqueResultException
     *
     * @return DataInterface
     */
    public function getInstance(FamilyInterface $family)
    {
        if (!$family->isSingleton()) {
            throw new \LogicException("Family {$family->getCode()} is not a singleton");
        }
        $qb = $this->createQueryBuilder('e')
            ->andWhere('e.family = :familyCode')
            ->addSelect('values')
            ->join('e.values', 'values')
            ->setParameters(
                [
                    'familyCode' => $family->getCode(),
                ]
            );

        $instance = $qb->getQuery()->getOneOrNullResult();
        if (!$instance) {
            $dataClass = $family->getDataClass();
            $instance = new $dataClass($family);
        }

        return $instance;
    }

    /**
     * @param string            $alias
     * @param string            $indexBy
     * @param QueryBuilder|null $qb
     *
     * @return QueryBuilder
     */
    public function createOptimizedQueryBuilder($alias, $indexBy = null, QueryBuilder $qb = null)
    {
        if (!$qb) {
            $qb = $this->createQueryBuilder($alias, $indexBy);
        }
        $qb->addSelect('values')
            ->leftJoin($alias.'.values', 'values');

        return $qb;
    }

    /**
     * Returns a EAVQueryBuilder to allow you to build a complex query to search your database
     *
     * @param FamilyInterface $family
     * @param string          $alias
     *
     * @return SingleFamilyQueryBuilder
     */
    public function createFamilyQueryBuilder(FamilyInterface $family, $alias = 'e')
    {
        return new SingleFamilyQueryBuilder($family, $this->createQueryBuilder($alias), $alias);
    }

    /**
     * @param string $alias
     *
     * @return EAVQueryBuilder
     */
    public function createEAVQueryBuilder($alias = 'e')
    {
        return new EAVQueryBuilder($this->createQueryBuilder($alias), $alias);
    }

    /**
     * @param FamilyInterface[] $families
     * @param string            $term
     *
     * @throws \LogicException
     * @throws \UnexpectedValueException
     *
     * @return QueryBuilder
     */
    public function getQbForFamiliesAndLabel(array $families, $term)
    {
        $eavQb = $this->createEAVQueryBuilder();
        $orCondition = [];
        foreach ($families as $family) {
            $attribute = $family->getAttributeAsLabel();
            if (!$attribute) {
                throw new \LogicException("Family {$family->getCode()} does not have an attribute as label");
            }
            if ($attribute->getType()->isRelation() || $attribute->getType()->isEmbedded()) {
                continue; // @todo fixme
            }
            $orCondition[] = $eavQb->attribute($attribute)->like($term);
        }

        return $eavQb->apply($eavQb->getOr($orCondition));
    }

    /**
     * @param FamilyInterface[] $families
     * @param string            $term
     *
     * @throws \LogicException
     * @throws \UnexpectedValueException
     *
     * @return QueryBuilder
     */
    public function getQbForFamiliesAndIdentifier(array $families, $term)
    {
        $eavQb = $this->createEAVQueryBuilder();
        $orCondition = [];
        foreach ($families as $family) {
            $identifierAttribute = $family->getAttributeAsIdentifier();
            if (!$identifierAttribute) {
                throw new \LogicException("Family {$family->getCode()} has no identifier");
            }
            $orCondition[] = $eavQb->attribute($identifierAttribute)->like($term);
        }

        return $eavQb->apply($eavQb->getOr($orCondition));
    }

    /**
     * @param int $id
     *
     * @throws NonUniqueResultException
     *
     * @return DataInterface
     */
    public function loadFullEntity($id)
    {
        $qb = $this->createOptimizedQueryBuilder('e');
        $qb
            ->leftJoin('values.dataValue', 'associations')
            ->addSelect('associations')
            ->leftJoin('associations.values', 'associationValues')
            ->addSelect('associationValues')
            ->andWhere('e.id = :id')
            ->setParameter('id', $id)
        ;

        return $qb->getQuery()->getOneOrNullResult();
    }

    /**
     * @param DataInterface $data
     *
     * @return DataInterface[]
     */
    public function fetchEAVAssociations(DataInterface $data)
    {
        $qb = $this->createOptimizedQueryBuilder('e');
        $qb
            ->join('e.refererValues', 'refererValues', Join::WITH, 'refererValues.data = :id')
            ->setParameter('id', $data->getId());

        return $qb->getQuery()->getResult();
    }

    /**
     * @param FamilyInterface $family
     *
     * @throws MappingException
     *
     * @return string
     */
    protected function getPkColumn(FamilyInterface $family)
    {
        return $this->getEntityManager()
            ->getClassMetadata($family->getDataClass())
            ->getSingleIdentifierFieldName();
    }

    /**
     * @param QueryBuilder $qb
     *
     * @throws NonUniqueResultException
     *
     * @return mixed
     */
    protected function executeWithPartialLoad(QueryBuilder $qb)
    {
        $query = $qb->getQuery();
        $query->setHint(Query::HINT_FORCE_PARTIAL_LOAD, true);

        return $query->getOneOrNullResult();
    }
}
