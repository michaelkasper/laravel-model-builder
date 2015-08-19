<?php

namespace Kasper\Laravel\ModelBuilder\Builders;

use \Exception;
use ICanBoogie\Inflector;
use Kasper\Laravel\ModelBuilder\ModelGenerator;
use Kasper\Laravel\ModelBuilder\Utilities\Relations;
use Kasper\Laravel\ModelBuilder\Utilities\StringUtils;
use \ReflectionClass;

/**
 * Class Model, a representation of one Laravel model.
 */
class ModelBase extends Builder
{
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
        $this->baseModelClass  = $this->getClassNameFromNamespace($this->baseModel);
        $this->class           = StringUtils::prettifyTableName($table, $prefix);
        $this->timestampFields = $this->getTimestampFields($this->baseModel);
        $this->foreignKeys     = $this->filterAndSeparateForeignKeys($foreignKeys['all'], $table);

        if (!empty($namespace)) {
            $this->namespace = "$namespace\Base";
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
            $this->fields[$field->Field] = $field->Type;
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

        $relations = ['belongsToMany' => "", 'hasOne' => "", 'belongsTo' => "", 'hasMany' => ""];
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

            $relations[$relation->getType()] .= $this->generatePartial($template, [
                'method_name'    => ucfirst($methodName),
                'type'           => $relation->getType(),
                'related_table'  => $relation->getRemoteClass(),
                'junction_table' => $relation->getJunctionTable(),
                'local_field'    => $relation->getLocalField(),
                'remote_field'   => $relation->getRemoteField(),
            ]);
        }
        $this->inject($script, 'relation_methods', implode(LF . LF . LF, $relations));

        $fields = "";
        foreach ($this->fields as $field => $type) {
            $fields .= $this->generatePartial(self::TEMPLATE_FIELD_DECLARATION, [
                'field_type'  => $type,
                'table_field' => $field
            ]);
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
