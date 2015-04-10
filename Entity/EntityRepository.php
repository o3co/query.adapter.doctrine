<?php
namespace O3Co\Query\Adapter\DoctrineExtension\Entity;

use Doctrine\ORM\EntityRepository as BaseEntityRepository;

use Doctrine\ORM\Query\ResultSetMapping;
use O3Co\Query\Query;
use O3Co\Query\CriteriaParser;
use O3Co\Query\Bridge\DoctrineOrm\DoctrineOrmPersister;
use O3Co\Query\Query\Visitor\FieldResolver\MappedFieldResolver;

/**
 * EntityRepository 
 * 
 * @uses BaseEntityRepository
 * @package \O3Co\Query
 * @copyright Copyrights (c) 1o1.co.jp, All Rights Reserved.
 * @author Yoshi<yoshi@1o1.co.jp> 
 * @license MIT
 */
class EntityRepository extends BaseEntityRepository 
{
    /**
     * criteriaParser 
     * 
     * @var \O3Co\Query\CriteriaParser
     * @access private
     */
    private $criteriaParser;

    /**
     * queryPersister 
     * 
     * @var \O3Co\Query\Bridge\DoctrineOrm\DoctrineOrmPersister
     * @access private
     */
    private $queryPersister;

    /**
     * {@inheritdoc}
     *  
     * Instead of using EntityPersister of UnitOfWork, use O3Co CriteriaParser 
     */
    public function findOneBy(array $criteria, array $orderBy = array())
    {
        $criteriaParser = $this->getCriteriaParser();
        if(!$criteriaParser) {
            parent::findOneBy($criteria, $orderBy);
        }

        $query = $this->getCriteriaParser()->parse($criteria, $orderBy, 1, 0);

        return $query->getNativeQuery()->getSingleResult();
    }

    /**
     * findBy 
     * 
     * @param array $criteria 
     * @param array $orderBy 
     * @param mixed $limit 
     * @param mixed $offset 
     * @access public
     * @return void
     */
    public function findBy(array $criteria, array $orderBy = array(), $limit = null, $offset = null)
    {
        $criteriaParser = $this->getCriteriaParser();
        if(!$criteriaParser) {
            parent::findBy($criteria, $orderBy, $limit, $offset);
        }

        // merge conditions into criteria.
        $query = $this->getCriteriaParser()->parse($criteria, $orderBy, $limit, $offset);

        return $query->getNativeQuery()->getResult();
    }

    /**
     * countBy 
     * 
     * @param array $criteria 
     * @access public
     * @return void
     */
    public function countBy(array $criteria)
    {
        $criteriaParser = $this->getCriteriaParser();
        if(!$criteriaParser) {
            if(method_exists(get_parent_class($this), 'conutBy')) {
                return parent::countBy($criteria);
            }
            throw new \Exception('EntityRepository does not support method "countBy". Please overwrite this method.');
        }

        $parsedQuery = $criteriaParser->parse($criteria);

        $query = $parsedQuery->getNativeQuery();

        // Check Composite Key or not
        if($this->getClassMetadata()->isIdentifierComposite) {
            // Select COUNT
            if ( ! $query->getHint(\Doctrine\ORM\Tools\Pagination\CountOutputWalker::HINT_DISTINCT)) {
                $query->setHint(\Doctrine\ORM\Tools\Pagination\CountOutputWalker::HINT_DISTINCT, true);
            }

            $platform = $query->getEntityManager()->getConnection()->getDatabasePlatform(); // law of demeter win

            $rsm = new ResultSetMapping();
            $rsm->addScalarResult($platform->getSQLResultCasing('dctrn_count'), 'count');

            $query->setHint(\Doctrine\ORM\Query::HINT_CUSTOM_OUTPUT_WALKER, 'Doctrine\ORM\Tools\Pagination\CountOutputWalker');
            $query->setResultSetMapping($rsm);

        } else {
            // Set Distinct 
            if ( ! $query->getHint(\Doctrine\ORM\Tools\Pagination\CountWalker::HINT_DISTINCT)) {
                $query->setHint(\Doctrine\ORM\Tools\Pagination\CountWalker::HINT_DISTINCT, true);
            }

            // Select COUNT
            $query->setHint(\Doctrine\ORM\Query::HINT_CUSTOM_TREE_WALKERS, array('Doctrine\ORM\Tools\Pagination\CountWalker'));
        }
        $query->setFirstResult(null)->setMaxResults(null);

        // Convert to CountQuery
        return $query->getSingleScalarResult();
    }

    /**
     * findByQuery 
     * 
     * @param Query $query 
     * @access public
     * @return void
     */
    public function findByQuery(Query $query)
    {
        $query->setPersister($this->getPersister());

        return $query->getNativeQuery()->getResult();
    }
    
    /**
     * getCriteriaParser 
     * 
     * @access public
     * @return void
     */
    public function getCriteriaParser()
    {
        return $this->criteriaParser;
    }
    
    /**
     * setCriteriaParser 
     * 
     * @param \O3Co\Query\CriteriaParser $criteriaParser 
     * @access public
     * @return void
     */
    public function setCriteriaParser(CriteriaParser $criteriaParser)
    {
        $this->criteriaParser = $criteriaParser;
        $this->criteriaParser->setPersister($this->getQueryPersister());
        return $this;
    }

    /**
     * getQueryPersister 
     * 
     * @access public
     * @return void
     */
    public function getQueryPersister()
    {
        if(!$this->queryPersister) {
            $this->queryPersister = new DoctrineOrmPersister($this->_em, $this->_class);
        }
        return $this->queryPersister;
    }
}

