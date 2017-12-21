<?php

namespace ByJG\MicroOrm;

use ByJG\AnyDataset\DbDriverInterface;
use ByJG\MicroOrm\Exception\OrmBeforeInvalidException;
use ByJG\Serializer\BinderObject;

class Repository
{

    /**
     * @var Mapper
     */
    protected $mapper;

    /**
     * @var DbDriverInterface
     */
    protected $dbDriver = null;

    /**
     * @var \Closure
     */
    protected $beforeUpdate = null;

    /**
     * @var \Closure
     */
    protected $beforeInsert = null;

    /**
     * Repository constructor.
     * @param DbDriverInterface $dbDataset
     * @param Mapper $mapper
     */
    public function __construct(DbDriverInterface $dbDataset, Mapper $mapper)
    {
        $this->dbDriver = $dbDataset;
        $this->mapper = $mapper;
        $this->beforeInsert = function ($instance) {
            return $instance;
        };
        $this->beforeUpdate = function ($instance) {
            return $instance;
        };
    }

    /**
     * @return Mapper
     */
    public function getMapper()
    {
        return $this->mapper;
    }

    /**
     * @return DbDriverInterface
     */
    protected function getDbDriver()
    {
        return $this->dbDriver;
    }

    /**
     * @param array|string $pkId
     * @return mixed|null
     */
    public function get($pkId)
    {
        $result = $this->getByFilter($this->mapper->getPrimaryKey() . ' = [[id]]', ['id' => $pkId]);

        if (count($result) === 1) {
            return $result[0];
        }

        return null;
    }

    /**
     * @param array $pkId
     * @return mixed|null
     * @throws \Exception
     */
    public function delete($pkId)
    {
        $params = ['id' => $pkId];
        $updatable = Updatable::getInstance()
            ->table($this->mapper->getTable())
            ->where($this->mapper->getPrimaryKey() . ' = [[id]]', $params);

        return $this->deleteByQuery($updatable);
    }

    /**
     * @param $updatable
     * @return bool
     */
    public function deleteByQuery(Updatable $updatable)
    {
        $params = [];
        $sql = $updatable->buildDelete($params);

        $this->getDbDriver()->execute($sql, $params);

        return true;
    }

    /**
     * @param string $filter
     * @param array $params
     * @param bool $forUpdate
     * @return array
     */
    public function getByFilter($filter, array $params, $forUpdate = false)
    {
        $query = new Query();
        $query->table($this->mapper->getTable())
            ->where($filter, $params);
        
        if ($forUpdate) {
            $query->forUpdate();
        }

        return $this->getByQuery($query);
    }

    /**
     * @param Query $query
     * @param Mapper[] $mapper
     * @return array
     */
    public function getByQuery(Query $query, array $mapper = [])
    {
        $mapper = array_merge([$this->mapper], $mapper);
        $query = $query->build($this->getDbDriver());

        $params = $query['params'];
        $sql = $query['sql'];
        $result = [];
        $iterator = $this->getDbDriver()->getIterator($sql, $params);

        foreach ($iterator as $row) {
            $collection = [];
            foreach ($mapper as $item) {
                $instance = $item->getEntity();
                $data = $row->toArray();

                foreach ((array)$item->getFieldAlias() as $fieldname => $fieldalias) {
                    if (isset($data[$fieldalias])) {
                        $data[$fieldname] = $data[$fieldalias];
                        unset($fieldalias);
                    }
                }
                BinderObject::bindObject($data, $instance);

                foreach ((array)$item->getFieldMap() as $property => $fieldmap) {
                    $selectMask = $fieldmap[Mapper::FIELDMAP_SELECTMASK];
                    $value = "";
                    if (isset($data[$fieldmap[Mapper::FIELDMAP_FIELD]])) {
                        $value = $data[$fieldmap[Mapper::FIELDMAP_FIELD]];
                    }
                    $data[$property] = $selectMask($value, $instance);
                }
                if (count($item->getFieldMap()) > 0) {
                    BinderObject::bindObject($data, $instance);
                }
                $collection[] = $instance;
            }
            $result[] = count($collection) === 1 ? $collection[0] : $collection;
        }

        return $result;
    }

    /**
     * @param mixed $instance
     * @return mixed
     * @throws \ByJG\MicroOrm\Exception\OrmBeforeInvalidException
     * @throws \ByJG\MicroOrm\Exception\OrmInvalidFieldsException
     * @throws \Exception
     */
    public function save($instance)
    {
        // Get all fields
        $array = BinderObject::toArrayFrom($instance, true);
        $array = $this->getMapper()->prepareField($array);

        // Mapping the data
        foreach ((array)$this->getMapper()->getFieldMap() as $property => $fieldmap) {
            $fieldname = $fieldmap[Mapper::FIELDMAP_FIELD];
            $updateMask = $fieldmap[Mapper::FIELDMAP_UPDATEMASK];

            // Get the value from the mapped field name
            $value = $array[$property];
            unset($array[$property]);
            $updateValue = $updateMask($value, $instance);

            // If no value for UpdateMask, remove from the list;
            if ($updateValue === false) {
                continue;
            }
            $array[$fieldname] = $updateValue;
        }

        // Defines if is Insert or Update
        $isInsert =
            empty($array[$this->mapper->getPrimaryKey()])
            || count($this->get($array[$this->mapper->getPrimaryKey()])) === 0
        ;

        // Prepare query to insert
        $updatable = Updatable::getInstance()
            ->table($this->mapper->getTable())
            ->fields(array_keys($array));

        // Execute Before Statements
        if ($isInsert) {
            $array = ($this->beforeInsert)($array);
        } else {
            $array = ($this->beforeUpdate)($array);
        }

        // Check if is OK
        if (empty($array) || !is_array($array)) {
            throw new OrmBeforeInvalidException('Invalid Before Insert Closure');
        }

        // Execute the Insert or Update
        if ($isInsert) {
            $array[$this->mapper->getPrimaryKey()] = $this->insert($updatable, $array);
        } else {
            $this->update($updatable, $array);
        }

        BinderObject::bindObject($array, $instance);

        return $instance;
    }

    /**
     * @param \ByJG\MicroOrm\Updatable $updatable
     * @param array $params
     * @return int
     * @throws \ByJG\MicroOrm\Exception\OrmInvalidFieldsException
     */
    protected function insert(Updatable $updatable, array $params)
    {
        $keyGen = $this->getMapper()->generateKey();
        if (empty($keyGen)) {
            return $this->insertWithAutoinc($updatable, $params);
        } else {
            return $this->insertWithKeyGen($updatable, $params, $keyGen);
        }
    }

    /**
     * @param \ByJG\MicroOrm\Updatable $updatable
     * @param array $params
     * @return int
     * @throws \ByJG\MicroOrm\Exception\OrmInvalidFieldsException
     */
    protected function insertWithAutoinc(Updatable $updatable, array $params)
    {
        $sql = $updatable->buildInsert($params, $this->getDbDriver()->getDbHelper());
        $dbFunctions = $this->getDbDriver()->getDbHelper();
        return $dbFunctions->executeAndGetInsertedId($this->getDbDriver(), $sql, $params);
    }

    /**
     * @param \ByJG\MicroOrm\Updatable $updatable
     * @param array $params
     * @param $keyGen
     * @return mixed
     * @throws \ByJG\MicroOrm\Exception\OrmInvalidFieldsException
     */
    protected function insertWithKeyGen(Updatable $updatable, array $params, $keyGen)
    {
        $params[$this->mapper->getPrimaryKey()] = $keyGen;
        $sql = $updatable->buildInsert($params, $this->getDbDriver()->getDbHelper());
        $this->getDbDriver()->execute($sql, $params);
        return $keyGen;
    }

    /**
     * @param \ByJG\MicroOrm\Updatable $updatable
     * @param array $params
     */
    protected function update(Updatable $updatable, array $params)
    {
        $params = array_merge($params, ['_id' => $params[$this->mapper->getPrimaryKey()]]);
        $updatable->where($this->mapper->getPrimaryKey() . ' = [[_id]] ', ['_id' => $params['_id']]);

        $sql = $updatable->buildUpdate($params, $this->getDbDriver()->getDbHelper());

        $this->getDbDriver()->execute($sql, $params);
    }

    public function setBeforeUpdate(\Closure $closure)
    {
        $this->beforeUpdate = $closure;
    }

    public function setBeforeInsert(\Closure $closure)
    {
        $this->beforeInsert = $closure;
    }
}
