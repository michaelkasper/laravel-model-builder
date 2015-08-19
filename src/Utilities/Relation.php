<?php

namespace Kasper\Laravel\ModelBuilder\Utilities;

/**
 * Class Relation, defines one single Relation entry.
 */
class Relation
{
    protected $type;
    protected $remoteField;
    protected $localField;
    protected $remoteFunction;
    protected $remoteClass;

    /**
     * @return mixed
     */
    public function getType()
    {
        return $this->type;
    }

    /**
     * @return mixed
     */
    public function getRemoteField()
    {
        return $this->remoteField;
    }

    /**
     * @return mixed
     */
    public function getLocalField()
    {
        return $this->localField;
    }

    /**
     * @return string
     */
    public function getRemoteFunction()
    {
        return $this->remoteFunction;
    }

    /**
     * @return mixed
     */
    public function getRemoteClass()
    {
        return $this->remoteClass;
    }

    /**
     * @return string
     */
    public function getJunctionTable()
    {
        return $this->junctionTable;
    }
    protected $junctionTable;

    /**
     * Create a relation object.
     *
     * @param $type
     * @param $remoteField
     * @param $remoteTable
     * @param $localField
     * @param string $prefix
     * @param string $junctionTable
     */
    public function __construct($type, $remoteField, $remoteTable, $localField, $prefix = '', $junctionTable = '')
    {
        $this->type = $type;
        $this->remoteField = $remoteField;
        $this->localField = $localField;
        $this->remoteFunction = StringUtils::underscoresToCamelCase(StringUtils::removePrefix($remoteTable, $prefix));
        $this->remoteClass = StringUtils::prettifyTableName($remoteTable, $prefix);
        $this->junctionTable = StringUtils::removePrefix($junctionTable, $prefix);

        if ($this->type == 'belongsToMany') {
            $this->remoteFunction = StringUtils::safePlural($this->remoteFunction);
        }
    }

    /**
     * @return string
     */
    public function __toString()
    {
        $string = TAB.'public function '.$this->remoteFunction.'()'.LF;
        $string .= TAB.'{'.LF;
        $string .= TAB.TAB.'return $this->'.$this->type.'(';
        $string .= StringUtils::singleQuote($this->remoteClass);

        if ($this->type == 'belongsToMany') {
            $string .= ', '.StringUtils::singleQuote($this->junctionTable);
        }

        //if(!NamingConvention::primaryKey($this->localField)) {
            $string .= ', '.StringUtils::singleQuote($this->localField);
        //}

        //if(!NamingConvention::foreignKey($this->remoteField, $this->remoteTable, $this->remoteField)) {
            $string .= ', '.StringUtils::singleQuote($this->remoteField);
        //}

        $string .= ');'.LF;
        $string .= TAB.'}'.LF.LF;

        return $string;
    }
}
