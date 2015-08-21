<?php

namespace Kasper\Laravel\ModelBuilder\Builders;

use \Exception;
use ICanBoogie\Inflector;
use Kasper\Laravel\ModelBuilder\Utilities\Relations;
use Kasper\Laravel\ModelBuilder\Utilities\StringUtils;
use \ReflectionClass;

/**
 * Class Model, a representation of one Laravel model.
 */
class ModelBase extends Builder
{
    private $parentNamespace;

    /**
     * First build the model.
     *
     * @param        $table
     * @param        $baseModel
     * @param        $describes
     * @param        $foreignKeys
     * @param string $namespace
     * @param string $prefix
     */
    public function buildModel($table, $baseModel, $describes, $foreignKeys, $namespace = '', $prefix = '')
    {
        $this->table           = StringUtils::removePrefix($table, $prefix);
        $this->baseModel       = $baseModel;
        $this->class           = "Base" . StringUtils::prettifyTableName($table, $prefix);
        $this->timestampFields = $this->getTimestampFields($this->baseModel);
        $this->foreignKeys     = $this->filterAndSeparateForeignKeys($foreignKeys['all'], $table);

        if (!empty($namespace)) {
            $this->parentNamespace = "$namespace";
            $this->namespace       = "$namespace\Base";
        }

        $foreignKeysByTable = $foreignKeys['ordered'];
        $describe           = $describes[$table];

        // main loop
        foreach ($describe as $field) {
            if ($this->isPrimaryKey($field)) {
                $this->primaryKey   = $field->Field;
                $this->incrementing = $this->isIncrementing($field);
                continue;
            }

            if ($this->isTimestampField($field)) {
                $this->timestamps = true;
                continue;
            }

            if ($this->isDate($field)) {
                $this->dates[] = $field->Field;
            }

            if ($this->isHidden($field)) {
                $this->hidden[] = $field->Field;
                continue;
            }

            if ($this->isForeignKey($table, $field->Field)) {
                continue;
            }

            $this->fillable[]            = $field->Field;
            $this->fields[$field->Field] = $this->mysqlDataTypeToPhpDataType($field->Type);
        }

        // relations
        $this->relations = new Relations(
            $table,
            $this->foreignKeys,
            $describes,
            $foreignKeysByTable,
            $prefix
        );
    }

    /**
     * Secondly, create the model.
     */
    public function createModel()
    {
        $script = $this->getTemplate(self::TEMPLATE_MODEL_BASE);

        $this->injectNamespace($script);
        $this->injectStandardVariables($script);

        $content = "";
        // primary key defaults to "id"
        if ($this->primaryKey !== 'id') {
            $content .= $this->generateProperty('public', 'primaryKey', StringUtils::singleQuote($this->primaryKey));
        }

        // timestamps defaults to true
        if (!$this->timestamps) {
            $content .= $this->generateProperty('public', 'timestamps', var_export($this->timestamps, true));
        }

        // incrementing defaults to true
        if (!$this->incrementing) {
            $content .= $this->generateProperty('public', 'incrementing', var_export($this->incrementing, true));
        }

        $content .= $this->generateProperty('protected', 'fillable', 'array(' . StringUtils::implodeAndQuote(', ', $this->fillable) . ')', true);

        // except for the hidden ones
        if (!empty($this->hidden)) {
            $content .= $this->generateProperty('protected', 'hidden', 'array(' . StringUtils::implodeAndQuote(', ', $this->hidden) . ')');
        }

        $cast = "";
        foreach ($this->fields as $field => $fieldInfo) {
            if ($fieldInfo[0] !== 'string') {
                $cast .= "'{$field}'=>'{$fieldInfo[0]}', ";
            }
        }

        if (!empty($cast)) {
            $content .= $this->generateProperty('protected', 'cast', 'array(' . trim($cast, ',') . ')', true);
        }

        $this->inject($script, 'content', $content);

        $accessors = "";
        if (!empty($this->dates)) {
            $accessors .= $this->generatePartial(self::TEMPLATE_ACCESSOR, [
                'field_pretty_name' => 'Dates',
                'field_content'     => "array(" . StringUtils::implodeAndQuote(', ', $this->dates) . ")",
            ]);
        }
        $this->inject($script, 'accessor_methods', $accessors);

        $inflector = Inflector::get(Inflector::DEFAULT_LOCALE);

        $relations          = ['belongsToMany' => "", 'hasOne' => "", 'belongsTo' => "", 'hasMany' => ""];
        $relationProperties = [];

        foreach ($this->relations->getRelations() as $relation) {
            $template   = self::TEMPLATE_RELATIONSHIP;
            $methodName = $relation->getRemoteFunction();
            $returnType = $relation->getRemoteClass() . "[]";

            switch ($relation->getType()) {
                case "belongsTo":
                case "hasOne":
                    $methodName = $inflector->singularize($methodName);
                    $returnType = $relation->getRemoteClass();

                    break;
                case "belongsToMany":
                    $template = self::TEMPLATE_RELATION_MANY;
                    break;
            }

            $returnType = "\\{$this->parentNamespace}\\$returnType";

            $propertyName = $inflector->underscore($methodName);
            if ($this->isReservedWords($propertyName)) {
                $propertyName .= "_relation";
            }

            $relations[$relation->getType()] .= $this->generatePartial($template, [
                'method_name'    => ucfirst($methodName),
                'property_name'  => $propertyName,
                'type'           => $relation->getType(),
                'related_class'  => $relation->getRemoteClass(),
                'junction_table' => $relation->getJunctionTable(),
                'local_field'    => $relation->getLocalField(),
                'remote_field'   => $relation->getRemoteField(),
                'return_type'    => $returnType,
            ]);

            $relationProperties[$propertyName] = [$returnType, null];
        }
        $this->inject($script, 'relation_methods', implode(LF . LF . LF, $relations));

        $propertiesMaxLength = 0;
        $dataTypeMaxLength   = 0;
        foreach ([
            $this->fields,
            $relationProperties,
        ] as $properties) {
            $maxLength = max(array_map('strlen', array_keys($properties)));
            if ($propertiesMaxLength < $maxLength) {
                $propertiesMaxLength = $maxLength;
            }

            $maxLength = max(array_map(function ($i) {
                return strlen($i[0]);
            }, $properties));

            if ($dataTypeMaxLength < $maxLength) {
                $dataTypeMaxLength = $maxLength;
            }
        }

        $gap = 1;
        if (count($relationProperties) > 0 && count($this->fields) > 0) {
            $gap = 6;
        }

        $fields = "";
        foreach ([
            self::TEMPLATE_FIELD_DECLARATION    => $this->fields,
            self::TEMPLATE_RELATION_DECLARATION => $relationProperties,
        ] as $template => $properties) {
            foreach ($properties as $property => $fieldInfo) {

                list($fieldType, $fieldDescription) = $fieldInfo;
                if (empty($fieldDescription)) {
                    $fieldDescription = "";
                }

                $fields .= $this->generatePartial($template, [
                    'pad'               => str_pad("", $gap),
                    'field_type'        => str_pad($fieldType, $dataTypeMaxLength),
                    'table_field'       => str_pad($property, $propertiesMaxLength),
                    'field_description' => $fieldDescription,
                ]);
            }
        }

        $this->inject($script, 'fields', $fields);

        $this->script = $script;
    }

    /**
     * Detect if we have timestamp field
     * TODO: not sure about this one yet.
     *
     * @param $model
     *
     * @return array
     */
    protected function getTimestampFields($model)
    {
        try {
            $baseModel       = new ReflectionClass($model);
            $timestampFields = [
                'created_at' => $baseModel->getConstant('CREATED_AT'),
                'updated_at' => $baseModel->getConstant('UPDATED_AT'),
                'deleted_at' => $baseModel->getConstant('DELETED_AT'),
            ];
        } catch (Exception $e) {
            echo 'baseModel: ' . $model . ' not found' . LF;
            $timestampFields = [
                'created_at' => 'created_at',
                'updated_at' => 'updated_at',
                'deleted_at' => 'deleted_at',
            ];
        }

        return $timestampFields;
    }

    /**
     * Check if the field is primary key.
     *
     * @param $field
     *
     * @return bool
     */
    protected function isPrimaryKey($field)
    {
        if ($field->Key == 'PRI') {
            return true;
        }

        return false;
    }

    /**
     * Check if the field (primary key) is auto incrementing.
     *
     * @param $field
     *
     * @return bool
     */
    protected function isIncrementing($field)
    {
        if ($field->Extra == 'auto_increment') {
            return true;
        }

        return false;
    }

    /**
     * Check if we have timestamp field.
     *
     * @param $field
     *
     * @return bool
     */
    protected function isTimestampField($field)
    {
        if (array_search($field->Field, $this->timestampFields)) {
            return true;
        }

        return false;
    }

    /**
     * Check if we have a date field.
     *
     * @param $field
     *
     * @return bool
     */
    protected function isDate($field)
    {
        if (StringUtils::strContains(['date', 'time', 'year'], $field->Type)) {
            return true;
        }

        return false;
    }

    /**
     * Check if we have a hidden field.
     *
     * @param $field
     *
     * @return bool
     */
    protected function isHidden($field)
    {
        if (StringUtils::strContains(['hidden', 'secret'], $field->Comment)) {
            return true;
        }

        return false;
    }

    /**
     * Check if we have a foreign key.
     *
     * @param $table
     * @param $field
     *
     * @return bool
     */
    protected function isForeignKey($table, $field)
    {
        foreach ($this->foreignKeys['local'] as $entry) {
            if ($entry->COLUMN_NAME == $field && $entry->TABLE_NAME == $table) {
                return true;
            }
        }

        return false;
    }

    /**
     * Only show the keys where table is mentioned.
     *
     * @param $foreignKeys
     * @param $table
     *
     * @return array
     */
    protected function filterAndSeparateForeignKeys($foreignKeys, $table)
    {
        $results = ['local' => [], 'remote' => []];
        foreach ($foreignKeys as $foreignKey) {
            if ($foreignKey->TABLE_NAME == $table) {
                $results['local'][] = $foreignKey;
            }
            if ($foreignKey->REFERENCED_TABLE_NAME == $table) {
                $results['remote'][] = $foreignKey;
            }
        }

        return $results;
    }
}
