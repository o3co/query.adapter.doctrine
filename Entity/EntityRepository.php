<?php
namespace O3Co\Query\Adapter\DoctrineExtension\Entity;

use Doctrine\ORM\EntityRepository as BaseEntityRepository;

use Doctrine\ORM\Query\ResultSetMapping;
use O3Co\Query\Query;
use O3Co\Query\Parser as InterfaceQueryParser;
use O3Co\Query\CriteriaParser as InterfaceCriteriaParser;
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
    const CRITERIA_BASE_QUERY = 'query';
    /**
     * interfaceCriteriaParser 
     * 
     * @var \O3Co\Query\CriteriaParser
     * @access private
     */
    private $interfaceCriteriaParser;

    /**
     * interfaceQueryParser 
     * 
     * @var O3Co\Query\Parser 
     * @access private
     */
    private $interfaceQueryParser;

    /**
     * queryPersister 
     * 
     * @var \O3Co\Query\Bridge\DoctrineOrm\DoctrineOrmPersister
     * @access private
     */
    private $queryPersister;


    protected function createInterfaceQuery(array $criteria, array $orderBy, $limit, $offset)
    {
        $baseQuery = null;
        if(isset($criteria[self::CRITERIA_BASE_QUERY])) {
            $baseQuery = $criteria[self::CRITERIA_BASE_QUERY];
            unset($criteria[self::CRITERIA_BASE_QUERY]);
        }

        $query = $this->getInterfaceCriteriaParser()->parse($criteria, $orderBy, $limit, $offset);
        
        // Update ConditionalClause with BaseQuery
        if($baseQuery) {
            if($queryParser = $this->getInterfaceQueryParser()) {
                $conditionalClause = $queryParser->parseCondition($baseQuery);

                if($query->getStatement()->hasClause('condition')) {
                    $criteriaCondition = $query->getStatement()->getClause('condition')->getExpression();
                    if($criteriaCondition) {
                        $conditionalClause->add($criteriaCondition->getExpression());
                    }
                }

                $query->setClause(
                        'condition', 
                        $conditionalClause
                    );
            }
        }
        return $query;
    }

    /**
     * {@inheritdoc}
     *  
     * Instead of using EntityPersister of UnitOfWork, use O3Co CriteriaParser 
     */
    public function findOneBy(array $criteria, array $orderBy = array())
    {
        if(!$this->getInterfaceCriteriaParser()) {
            return parent::findOneBy($criteria, $orderBy);
        }

        $query = $this->createInterfaceQuery($criteria, $orderBy, 1, 0);

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
        if(!$this->getInterfaceCriteriaParser()) {
            if(method_exists(get_parent_class($this), 'conutBy')) {
                return parent::countBy($criteria);
            }
            throw new \Exception('EntityRepository does not support method "countBy". Please overwrite this method.');
        }
        $query = $this->createInterfaceQuery($criteria, $orderBy, $limit, $offset);
        $nativeQuery = $query->getNativeQuery();

        // Check Composite Key or not
        if($this->getClassMetadata()->isIdentifierComposite) {
            // Select COUNT
            if ( ! $nativeQuery->getHint(\Doctrine\ORM\Tools\Pagination\CountOutputWalker::HINT_DISTINCT)) {
                $nativeQuery->setHint(\Doctrine\ORM\Tools\Pagination\CountOutputWalker::HINT_DISTINCT, true);
            }

            $platform = $nativeQuery->getEntityManager()->getConnection()->getDatabasePlatform(); // law of demeter win

            $rsm = new ResultSetMapping();
            $rsm->addScalarResult($platform->getSQLResultCasing('dctrn_count'), 'count');

            $nativeQuery->setHint(\Doctrine\ORM\Query::HINT_CUSTOM_OUTPUT_WALKER, 'Doctrine\ORM\Tools\Pagination\CountOutputWalker');
            $nativeQuery->setResultSetMapping($rsm);

        } else {
            // Set Distinct 
            if ( ! $nativeQuery->getHint(\Doctrine\ORM\Tools\Pagination\CountWalker::HINT_DISTINCT)) {
                $nativeQuery->setHint(\Doctrine\ORM\Tools\Pagination\CountWalker::HINT_DISTINCT, true);
            }

            // Select COUNT
            $nativeQuery->setHint(\Doctrine\ORM\Query::HINT_CUSTOM_TREE_WALKERS, array('Doctrine\ORM\Tools\Pagination\CountWalker'));
        }
        $nativeQuery->setFirstResult(null)->setMaxResults(null);

        // Convert to CountQuery
        return $nativeQuery->getSingleScalarResult();
    }

    /**
     * findByQuery 
     * 
     * @param Query $query 
     * @access public
     * @return void
     */
    public function findByQuery($query)
    {
        if($query instanceof Query) {
            $query->setPersister($this->getPersister());
            return $query->getNativeQUERY->getResult();
        } else if(is_string($query)) {
            // parse the query string
            $queryParser = $this->getInterfaceQueryParser();
            if($queryParser) {
                return $queryParser->parse($query)->getNativeQuery()->getResult();
            }
        }

        throw new \InvalidArgumentException('Unsupported query is given.');
    }

    public function setInterfaceParser($parser)
    {
        if(($parser instanceof InterfaceCriteriaParser) && ($parser instanceof InterfaceQueryParser)) {
            $parser->setPersister($this->getQueryPersister());

            $this->interfaceCriteriaParser = $parser;
            $this->interfaceQueryParser = $parser;
        } else {
            throw new \InvalidArgumentException('setInterfaceParser requires parser implements both InterfaceCriteriaParser and InterfaceQueryParser.');
        }

        return $this;
    }
    
    /**
     * getInterfaceCriteriaParser 
     * 
     * @access public
     * @return void
     */
    public function getInterfaceCriteriaParser()
    {
        return $this->interfaceCriteriaParser;
    }
    
    /**
     * setInterfaceCriteriaParser 
     * 
     * @param \O3Co\Query\CriteriaParser $criteriaParser 
     * @access public
     * @return void
     */
    public function setInterfaceCriteriaParser(InterfaceCriteriaParser $criteriaParser)
    {
        $this->interfaceCriteriaParser = $criteriaParser;
        $this->interfaceCriteriaParser->setPersister($this->getQueryPersister());
        return $this;
    }

    /**
     * getInterfaceQueryParser 
     * 
     * @access public
     * @return void
     */
    public function getInterfaceQueryParser()
    {
        return $this->interfaceQueryParser;
    }

    /**
     * setInterfaceQueryParser 
     * 
     * @param InterfaceQueryParser $queryParser 
     * @access public
     * @return void
     */
    public function setInterfaceQueryParser(InterfaceQueryParser $queryParser)
    {
        $this->interfaceQueryParser = $queryParser;
        $this->interfaceQueryParser->setPersister($this->getQueryPersister());
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

