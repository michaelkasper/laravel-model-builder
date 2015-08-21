<?php

namespace Kasper\Laravel\ModelBuilder\Builders;

use Kasper\Laravel\ModelBuilder\ModelGenerator;
use Kasper\Laravel\ModelBuilder\Utilities\Relations;

class Builder
{
    const TEMPLATE_MODEL = 'model.tmpl';
    const TEMPLATE_MODEL_BASE = 'model_base.tmpl';
    const TEMPLATE_ACCESSOR = 'accessor.tmpl';
    const TEMPLATE_FIELD_DECLARATION = 'field_declaration.tmpl';
    const TEMPLATE_RELATION_DECLARATION = 'relation_declaration.tmpl';
    const TEMPLATE_RELATION_MANY = 'relation_many_to_many.tmpl';
    const TEMPLATE_RELATIONSHIP = 'relationship.tmpl';
    const TEMPLATE_PROPERTY = 'property.tmpl';
    /*
     *
     */
    protected $baseModel = 'Model';
    protected $baseModelClass = '';
    protected $table = '';
    protected $foreignKeys = [];
    // the class and table names
    protected $class = '';
    // auto detected the elements
    protected $timestampFields = [];
    protected $primaryKey = '';
    protected $incrementing = false;
    protected $timestamps = false;
    protected $dates = [];
    protected $hidden = [];
    protected $fillable = [];
    protected $fields = [];
    protected $namespace = null;
    /**
     * @var Relations
     */
    protected $relations;
    protected $templateCache = [];
    protected $script = "";

    public function __construct()
    {
        if (!defined('TAB')) {
            define('TAB', '    '); // Code MUST use 4 spaces for indenting, not tabs.
        }
        if (!defined('LF')) {
            define('LF', "\n");
        }
        if (!defined('CR')) {
            define('CR', "\r");
        }
    }

    /**
     * Thirdly, return the created string.
     *
     * @return string
     */
    public function __toString()
    {
        return str_replace(LF . LF . LF, "", $this->script);
    }

    /**
     * @return null
     */
    public function getNamespace()
    {
        return $this->namespace;
    }

    /**
     * @return string
     */
    public function getClass()
    {
        return $this->class;
    }

    protected function getTemplate($template)
    {
        if (!isset($this->templateCache[$template])) {
            $this->templateCache[$template] = file_get_contents(__DIR__ . "/templates/$template");
        }
        return $this->templateCache[$template];
    }

    protected function rawInject(&$script, $placeholder, $value)
    {
        $script = str_replace($placeholder, $value, $script);
        return $this;
    }

    protected function inject(&$script, $placeholder, $value)
    {
        $this->rawInject($script, "{{" . $placeholder . "}}", $value);
        return $this;
    }

    protected function injectNamespace(&$script)
    {
        if (!$this->namespace) {
            $this->rawInject($script, "namespace {{namespace}};", "");
        } else {
            $this->inject($script, 'namespace', $this->namespace);
        }
        return $this;
    }

    protected function injectStandardVariables(&$script)
    {
        $this->injectVariables($script, [
            'base_class' => $this->baseModel,
            'class_name' => $this->class,
            'table_name' => $this->table
        ]);
        return $this;
    }

    protected function injectVariables(&$script, $data)
    {
        foreach ($data as $placeholder => $value) {
            $this->inject($script, $placeholder, $value);
        }
        return $this;
    }

    protected function generateProperty($visibility, $property, $value, $wrap = false)
    {
        $script = $this->generatePartial(self::TEMPLATE_PROPERTY, [
            'visibility' => $visibility,
            'property'   => $property,
            'value'      => $value,
        ]);

        if ($wrap) {
            $script = wordwrap($script, ModelGenerator::$lineWrap, LF . TAB . TAB);
        }

        return $script;
    }

    protected function generatePartial($template, array $data)
    {
        $script = $this->getTemplate($template);
        $this->injectVariables($script, $data);
        return $script;
    }

    protected function getClassNameFromNamespace($namespace)
    {
        $className = $namespace;
        if (preg_match('@\\\\([\w]+)$@', $namespace, $matches)) {
            $className = $matches[1];
        }
        return $className;
    }

    protected function mysqlDataTypeToPhpDataType($dataType)
    {
        list($mysqlDataType, $mysqlDescription) = explode("(", "$dataType(");
        $mysqlDescription = trim($mysqlDescription, ")");
        $mysqlDataType    = strtolower($mysqlDataType);
        $phpDescription   = "[$dataType] ";

        switch ($mysqlDataType) {
            case "tinyint":
            case "smallint":
            case "mediumint":
            case "int":
            case "bigint":
                $phpDataType = 'int';
                if ($mysqlDescription == '1') {
                    $phpDataType = 'bool';
                } else {
                    $phpDescription = "[$dataType] $mysqlDescription digits long";
                }
                break;
            case 'float':
            case 'decimal':
                $phpDataType = 'float';
                list($int, $decimal) = explode(",", "$mysqlDescription,");
                $phpDescription .= "$int digits long";
                if (!empty($decimal)) {
                    $phpDescription .= " and $decimal decimal digits long";
                }
                break;
            case 'double':
                $phpDataType = 'float';
                break;
            case 'bit':
                $phpDataType = 'int';
                $phpDescription .= "1|0";
                break;
            case 'enum':
            case 'set':
                $phpDataType = 'string';
                break;
            case 'char':
            case 'varchar':
            case 'binary':
            case 'varbinary':
            case 'tinyblob':
            case 'blob':
            case 'mediumblob':
            case 'longblob':
            case 'tinytext':
            case 'text':
            case 'mediumtext':
            case 'longtext':
            case 'date':
            case 'time':
            case 'datetime':
            case 'timestamp':
            case 'year':
            case 'geometry':
            case 'point':
            case 'linestring':
            case 'polygon':
            case 'geometrycollection':
            case 'multilinestring':
            case 'multipoint':
            case 'multipolygon':
                $phpDataType = 'string';
                if (!empty($mysqlDescription)) {
                    if ($mysqlDescription == 1) {
                        $phpDescription .= "$mysqlDescription character";
                    } else {
                        $phpDescription .= "$mysqlDescription characters";
                    }
                }
                break;
            default:
                $phpDataType = 'string';
        }

        return [$phpDataType, $phpDescription];
    }

    protected function isReservedWords($word)
    {
        return in_array(strtolower($word), [
            "abstract",
            "and",
            "array",
            "as",
            "break",
            "case",
            "catch",
            "class",
            "clone",
            "const",
            "continue",
            "declare",
            "default",
            "do",
            "die",
            "echo",
            "else",
            "elseif",
            "empty",
            "enddeclare",
            "endfor",
            "endforeach",
            "endif",
            "endswitch",
            "endwhile",
            "eval",
            "exit",
            "extends",
            "final",
            "for",
            "foreach",
            "function",
            "global",
            "goto",
            "if",
            "implements",
            "include",
            "include_once",
            "interface",
            "isset",
            "instanceof",
            "list",
            "namespace",
            "new",
            "or",
            "print",
            "private",
            "protected",
            "public",
            "require",
            "require_once",
            "return",
            "static",
            "switch",
            "throw",
            "try",
            "unset",
            "use",
            "var",
            "while",
            "xor"
        ]);
    }
}