<?php

namespace Kasper\Laravel\ModelBuilder\Builders;

use \Exception;
use Kasper\Laravel\ModelBuilder\ModelGenerator;
use Kasper\Laravel\ModelBuilder\Utilities\Relations;
use Kasper\Laravel\ModelBuilder\Utilities\StringUtils;
use \ReflectionClass;

/**
 * Class Model, a representation of one Laravel model.
 */
class Model extends Builder
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
        $this->table          = StringUtils::removePrefix($table, $prefix);
        $this->baseModel      = $baseModel;
        $this->class          = StringUtils::prettifyTableName($table, $prefix);

        if (!empty($namespace)) {
            $this->namespace = "$namespace";
        }
    }

    /**
     * Secondly, create the model.
     */
    public function createModel()
    {
        $script = $this->getTemplate(self::TEMPLATE_MODEL);

        $this->injectNamespace($script);
        $this->injectStandardVariables($script);
        $this->script = $script;
    }
}
