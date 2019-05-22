<?php

namespace Walterdis\Yii2\Behavior\CarbonBehavior;

use Carbon\Carbon;
use yii\base\Event;
use yii\db\ActiveRecord;
use yii\db\ColumnSchema;

/**
 * @author Walter Discher Cechinel <mistrim@gmail.com>
 */
class CarbonBehavior extends \yii\base\Behavior
{

    const EVENT_TO_OUTPUT_FORMAT = 'toOutputFormat';

    /**
     * @var ActiveRecord
     */
    public $owner;

    /**
     *
     * @var Event
     */
    protected $event;

    /**
     * Add model validation error if any attribute is invalid.
     *
     * @var bool
     */
    public $addValidationErrors = true;

    /**
     * Iterate over table schema and to attributes list
     *
     * @author Walter Discher Cechinel <mistrim@gmail.com>
     * @var bool
     */
    public $importSchemaAttributes = true;

    /**
     *
     * @var array
     */
    public $toStringFormats = [
        'datetime' => 'd/m/Y H:i:s',
        'timestamp' => 'd/m/Y H:i:s',
        'date' => 'd/m/Y',
    ];

    /**
     * Available patterns sent by the user to be converted to object
     *
     * @var array
     */
    public $inputPatterns = [
        'd/m/Y',
        'd/m/Y H:i:s',
        'd/m/Y H:i',
        'H:i:s',
        'H:i',
        'Y-m-d H:i:s.u',
        'Y-m-d H:i:s',
        'Y-m-d',
    ];

    /**
     * Formats to convert from user input to database format
     * Ex: 'd/m/Y' => 'Y-m-d' will convert xx/xx/xxxx to xxxx-xx-xx
     *
     * @var array
     */
    public $toDatabaseStringFormats = [
        'date' => 'Y-m-d',
        'datetime' => 'Y-m-d H:i:s',
        'time' => 'H:i:s',
        'timestamp' => 'Y-m-d H:i:s',
    ];

    /**
     * @TODO
     * Force attribute toString to the given format
     * Ex: ['created_at' => 'date'] will force the attribute to print as date
     * regardless the original type
     * @var type
     */
    public $mutateDate = [];

    /**
     * array list of custom attributes to be converted
     * attribute => type (date, datetime...)
     *
     * @var
     */
    public $attributes = [
    ];

    /**
     *
     * @var array
     */
    public $dateTypes = ['date', 'datetime', 'time', 'timestamp'];

    /**
     * List of attributes to receive the current datetime on
     * beforeInsert event
     *
     * @var array
     */
    public $createdAtAttributes = [
        'created_at',
    ];

    /**
     * List of attributes to receive the current datetime on
     * beforeInsert and beforeUpdate event
     *
     * @var array
     */
    public $updatedAtAttributes = [
        'updated_at',
        'update_at',
    ];

    /**
     * @return array
     */
    public function events()
    {
        return [
            ActiveRecord::EVENT_AFTER_FIND => 'convertFromDatabase',
            ActiveRecord::EVENT_AFTER_UPDATE => 'convertFromDatabase',
            ActiveRecord::EVENT_AFTER_INSERT => 'convertFromDatabase',
            ActiveRecord::EVENT_AFTER_VALIDATE => 'convertTo',
            ActiveRecord::EVENT_BEFORE_INSERT => 'convertToDatabase',
            ActiveRecord::EVENT_BEFORE_UPDATE => 'convertToDatabase',
            ActiveRecord::EVENT_BEFORE_VALIDATE => 'convertToDatabase',
            static::EVENT_TO_OUTPUT_FORMAT => 'convertFromDatabase',
        ];
    }

    /**
     * @author Walter Discher Cechinel <mistrim@gmail.com>
     *
     * @param string $attribute
     *
     * @return string
     */
    protected function getValue($attribute)
    {
        if (!$value = $this->owner->getAttribute($attribute)) {
            $value = $this->owner->$attribute ?? null;
        }

        if (!$value) {
            return null;
        }

        if ($value instanceof Carbon) {
            return $value;
        }

        if (is_object($value)) {
            return $value;
        }

        return trim($value);
    }

    /**
     * @author Walter Discher Cechinel <mistrim@gmail.com>
     *
     * @param string $attribute
     * @param string $value
     *
     * @return boolean|void
     */
    public function setAttribute($attribute, $value)
    {
        if (!$this->owner->$attribute) {
            return false;
        }

        $this->owner->$attribute = $value;
    }

    /**
     * @author Walter Discher Cechinel <mistrim@gmail.com>
     *
     * @param Event $event
     */
    public function convertTo($event)
    {
        if ($event->name == 'afterValidate') {
            if ($this->owner->hasErrors()) {
                return $this->convertFromDatabase($event);
            }
        }

        return $this->convertToDatabase($event);
    }

    /**
     * String to Carbon from secure sources (database, after update...)
     *
     * @author Walter Discher Cechinel <mistrim@gmail.com>
     *
     * @param Event $event
     */
    public function convertFromDatabase($event)
    {
        $attributes = $this->prepareAttributes($event);

        foreach ($attributes as $attr => $type) {
            if (!$value = $this->getValue($attr)) {
                continue;
            }

            if (is_numeric($value)) {
                $value = Carbon::createFromTimestamp($value);
            } elseif ($type == 'date') {
                $value = Carbon::createFromFormat('Y-m-d', $value)->startOfDay();
            } elseif ($type == 'time') {
                $value = Carbon::createFromFormat('H:i:s', $value);
            } else {
                $value = $this->convertFromPatterns($value, ['Y-m-d H:i', 'Y-m-d H:i:s']);
            }

            if (!$value instanceof Carbon) {
                continue;
            }

            $this->applyToStringFormat($value, $type);
            $this->setAttribute($attr, $value);
        }
    }

    /**
     *
     * @param Event $event
     */
    public function convertToDatabase($event)
    {
        $attributes = $this->prepareAttributes($event);
        $eventName = $event->name;

        foreach ($attributes as $attr => $type) {
            if ($this->touchedCreateUpdate($attr, $eventName)) {
                continue;
            }

            if (!$value = $this->getValue($attr)) {
                continue;
            }

            if ($value instanceof Carbon) {
                $this->applyToDatabaseFormat($value, $type);
                continue;
            }

            $carbon = $this->convertFromPatterns($value, $this->inputPatterns);
            if (!$carbon instanceof Carbon) {
                if ($this->addValidationErrors) {
                    $this->owner->addError($attr, $attr . ' The given date is invalid.');
                }
                continue;
            }

            $this->applyToDatabaseFormat($carbon, $type);
            $this->setAttribute($attr, $carbon);
        }
    }

    /**
     * @author Walter Discher Cechinel <mistrim@gmail.com>
     *
     * @param string $value
     * @param array $patterns
     *
     * @return Carbon|bool
     */
    protected function convertFromPatterns($value, $patterns)
    {
        foreach ($patterns as $pattern) {
            if ($date = $this->convertFromFormat($pattern, $value)) {
                return $date;
            }
        }

        return false;
    }

    /**
     * @author Walter Discher Cechinel <mistrim@gmail.com>
     *
     * @param string $format
     * @param string $value
     *
     * @return boolean
     */
    protected function convertFromFormat($format, $value)
    {
        try {
            return Carbon::createFromFormat($format, $value);
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * @author Walter Discher Cechinel <mistrim@gmail.com>
     *
     * @param Event $event
     *
     * @return array
     */
    protected function prepareAttributes($event)
    {
        $attributes = [];
        if ($this->importSchemaAttributes) {
            $attributes = $this->attributesFromSchema();
        }

        foreach ($this->attributes as $attribute => $type) {
            $attributes[$attribute] = $type;
        }

        return $attributes;
    }

    /**
     * @author Walter Discher Cechinel <mistrim@gmail.com>
     *
     * @param array $columns
     *
     * @return array
     */
    private function attributesFromSchema()
    {
        $schema = $this->owner->getTableSchema();
        $columns = $schema->columns;

        $attributes = [];

        /* @var $columnData ColumnSchema */
        foreach ($columns as $name => $columnData) {
            $columnType = $columnData->type;

            if (in_array($columnType, $this->dateTypes)) {
                $attributes[$name] = $columnType;
                continue;
            }
        }

        return $attributes;
    }

    /**
     * @author Walter Discher Cechinel <mistrim@gmail.com>
     *
     * @param Carbon $date
     * @param string $type
     */
    protected function applyToDatabaseFormat(Carbon $date, $type)
    {
        $format = $this->toDatabaseStringFormats[$type] ?? null;
        if (!$format) {
            return false;
        }

        $date->settings(['toStringFormat' => $format]);
    }

    /**
     * @author Walter Discher Cechinel <mistrim@gmail.com>
     *
     * @param Carbon $date
     * @param string $type
     */
    protected function applyToStringFormat(Carbon $date, $type)
    {
        $format = $this->toStringFormats[$type] ?? null;
        if (!$format) {
            return false;
        }

        $date->settings(['toStringFormat' => $format]);
    }

    /**
     * @author Walter Discher Cechinel <mistrim@gmail.com>
     *
     * @param string $attr
     * @param string $eventName
     *
     * @return boolean
     */
    protected function touchedCreateUpdate($attr, $eventName)
    {
        if ($eventName != ActiveRecord::EVENT_BEFORE_INSERT && $eventName != ActiveRecord::EVENT_BEFORE_UPDATE) {
            return false;
        }
        $currentDate = Carbon::now();
        if ($eventName == ActiveRecord::EVENT_BEFORE_INSERT) {
            if (in_array($attr, array_merge($this->createdAtAttributes, $this->updatedAtAttributes))) {
                $this->owner->$attr = $currentDate;
                return true;
            }
        }

        if ($eventName == ActiveRecord::EVENT_BEFORE_UPDATE) {
            if (in_array($attr, $this->updatedAtAttributes)) {
                $this->owner->$attr = $currentDate;

                return true;
            }
        }

        return false;
    }

}
