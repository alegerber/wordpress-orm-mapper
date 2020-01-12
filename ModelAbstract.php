<?php

namespace HePlugin\App\Model;

abstract class ModelAbstract implements \ArrayAccess
{

    /** @var string */
    const TABLE_NAME = '';

    /** @var string */
    const PREFIX = 'hep_';

    /** @var string */
    const DATETIME_FORMAT = 'YYYY-MM-DD hh:mm:ss';


    abstract public function getId();

    /**
     * @param array $conditions
     *
     * @global wpdb $wpdb
     *
     * @return self
     */
    public function findBy(array $conditions): self
    {
        /** @var $wpdb */
        global $wpdb;

        $conditionString = '';

        foreach ($conditions as $columnName => $condition) {
            $conditionString .= $columnName . ' = ' . $condition;
        }
        
        $row = $wpdb->get_results(
            'SELECT * FROM ' . $wpdb->prefix . self::PREFIX . self::TABLE_NAME . ' WHERE ' . $conditionString,
            ARRAY_A
        );

        foreach ($row as $key => $value) {
            if(!isset($key)) {
                throw new \InvalidArgumentException('Property not found');
            }

            switch (true) {
                case $this->isModel($key):
                    $modelName = $this->getModelName($key);

                    /** @var ModelAbstract $model */
                    $model = new $modelName();

                    $value = $model->findBy(['id' => $value]);
                    break;
                case $this->isJson($value):
                    $value[] = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
                    $keys[]  = $key;
                    break;
                case get_class(\DateTime::createFromFormat(self::DATETIME_FORMAT, $value)) === \DateTime::class:
                    $value[] = \DateTime::createFromFormat(self::DATETIME_FORMAT, $value);
                    $keys[]  = $key;
                    break;
                default:
                    $value[] = $value;
                    $keys[]  = $key;
            }

            $this[$key] = $value;
        }

        if (!empty($diff = array_diff(array_keys($this), array_keys($row)))) {
            $this->getCollection($diff);
        }

        return $this;
    }

    /**
     * @return int|false
     */
    public function save()
    {
        /** @var $wpdb */
        global $wpdb;

        $values = [];
        $keys   = [];

        foreach ($this as $key => $value) {
            switch (true) {
                case $this->isModel($value):
                    $value[] = $value->getId();
                    $keys[]  = $key;
                    break;
                case $this->isCollection($value):
                    $value[] = json_encode($value, JSON_THROW_ON_ERROR);
                    $keys[]  = $key;
                    break;
                case is_array($value) && !$this->isCollection($value):
                    $this->insertCollection($key, $value);
                    break;
                case get_class($value) === \DateTime::class:
                    $value[] = $value->format(self::DATETIME_FORMAT);
                    $keys[]  = $key;
                    break;
                default:
                    $value[] = $value;
                    $keys[]  = $key;
            }
        }

        $keys   = '(' . implode(', ', $keys) . ')';
        $values = '('. implode(', ', $values) . ')';

        return $wpdb->query('INSERT INTO ' . $wpdb->prefix . self::PREFIX . self::TABLE_NAME . ' ' . $keys . ' VALUES ' . $values);
    }


    /**
     * @param string $offset
     * @return bool
     */
    public function offsetExists($offset): bool
    {
        $offset = ucfirst($offset);

        return property_exists($this, $offset);
    }


    /**
     * @param string $offset
     * @return mixed
     */
    public function offsetGet($offset)
    {
        $offset = ucfirst($offset);

        $functionName = 'get' . ucfirst($offset);

        return $this->$functionName();
    }


    /**
     * @param string $offset
     * @param mixed $value
     */
    public function offsetSet($offset, $value): void
    {
        $offset = ucfirst($offset);

        $functionName = 'set' . $offset;

        $this->$functionName($value);
    }


    /**
     * @param string $offset
     */
    public function offsetUnset($offset): void
    {
        $offset = ucfirst($offset);

        $functionName = 'set' . $offset;

        $this->$functionName(null);
    }


    /**
     * @param string $offset
     *
     * @return bool
     */
    private function isModel(string $offset): bool
    {
        $offset = ucfirst($offset);

        return file_exists(__DIR__ . $offset .'.php');
    }


    /**
     * @param string $offset
     *
     * @return string
     */
    private function getModelName(string $offset): string
    {
        $offset = ucfirst($offset);

        return __NAMESPACE__ . '/' . $offset;
    }


    private function isJson(string $json): bool
    {
        try {
            json_decode($json, true,512, JSON_THROW_ON_ERROR);

            return true;
        } catch (\JsonException $e) {

            return false;
        }
    }


    /**
     * @param mixed $value
     *
     * @return bool
     */
    private function isCollection($value): bool
    {

        return is_array($value) && $this->isModel($value[0]);
    }


    /**
     * @param string $key
     * @param array $collection
     */
    private function insertCollection(string $key, array $collection): void
    {
        global $wpdb;

        $path      = explode('\\', self::class);
        $className = array_pop($path);

        /** @var ModelAbstract $model */
        foreach ($collection as $model) {
            $keys = [
                $className,
                $key
            ];

            $values = [
                $this->getId(),
                $model->getId()
            ];

            $wpdb->query('INSERT INTO ' . $wpdb->prefix . self::PREFIX . self::TABLE_NAME . '_' . $model::TABLE_NAME . ' ' . $keys . ' VALUES ' . $values);
        }
    }


    /**
     * @param array $diff
     * @return array
     */
    private function getCollection(array $diff): array
    {
        global $wpdb;

        $path      = explode('\\', self::class);
        $className = array_pop($path);

        $conditionString = [$className => $this->getId()];


        foreach ($diff as $key) {
            $modelName = $this->getModelName($key);
            $model     = new $modelName();
            $wpdb->get_results(
                'SELECT * FROM ' . $wpdb->prefix . self::PREFIX . self::TABLE_NAME . '_' . $model::TABLE_NAME . ' WHERE ' . $conditionString,
                ARRAY_A
            );
        }
    }
}