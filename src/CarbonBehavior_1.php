<?php

namespace Walterdis\Yii2\Behavior\CarbonBehavior;

use Carbon\Carbon;
use Walterdis\Yii2\Behavior\CarbonBehavior\Contracts\SearchInterface;
use yii\db\ActiveRecord;

/**
 * @author Walter Discher Cechinel <mistrim@gmail.com>
 */
class CarbonBehavior extends \yii\base\Behavior
{

    /**
     * @var ActiveRecord
     */
    public $owner;

    /**
     *
     * @var \yii\base\Event
     */
    protected $event;

    /**
     * @var array list of attributes to convert to Carbon
     */
    public $attributes = [];

    /**
     * Iterate over table schema and try to convert date type columns
     *
     * @author Walter Discher Cechinel <mistrim@gmail.com>
     * @var bool
     */
    public $autoConvertDateTypes = true;

    /**
     * @var string date format for carbon
     */
    public $dateFormat = 'Y-m-d H:i:s';

    /**
     * @var array
     */
    public $toStringDateFormat = 'd/m/Y H:i:s';

    /**
     * @var array list of formats to try the conversion
     */
    public $dateFormatPatterns = [
        'd/m/Y' => 'date',
        'd/m/Y H:i:s' => 'datetime',
        'd/m/Y H:i' => 'datetime',
        'Y-m-d' => 'date',
        'Y-m-d H:i:s' => 'datetime',
        'Y-m-d H:i' => 'datetime',
    ];

    /**
     *
     * @var array
     */
    const DATABASE_FORMATS = [
        'date',
        'datetime',
        'time',
        'timestamp',
    ];

    /**
     *
     * @var array
     */
    private $columns;

    public function init()
    {
        parent::init();
    }

    /**
     * @return array
     */
    public function events()
    {
        return [
            ActiveRecord::EVENT_AFTER_FIND => 'toCarbon',
            ActiveRecord::EVENT_AFTER_VALIDATE => 'toCarbon',
            ActiveRecord::EVENT_AFTER_UPDATE => 'toCarbon',
            ActiveRecord::EVENT_AFTER_INSERT => 'toCarbon',
            ActiveRecord::EVENT_BEFORE_VALIDATE => 'toCarbon',
            ActiveRecord::EVENT_BEFORE_INSERT => 'toCarbon',
            ActiveRecord::EVENT_BEFORE_UPDATE => 'toCarbon',
        ];
    }

    /**
     * Convert the model's attributes to an Carbon instance.
     *
     * @param $event
     *
     * @return static
     * @throws \yii\base\InvalidConfigException
     */
    public function toCarbon($event)
    {
        $this->event = $event;

        $attributes = array_flip($this->attributes);
        if ($this->autoConvertDateTypes) {
            $attributes = $this->prepareAutoConvertAttributes($this->owner->getTableSchema()->columns);
        }

        foreach ($attributes as $attribute => $type) {
            if (!$value = $this->getValue($attribute, $type)) {
                $this->owner->$attribute = null;
                continue;
            }

            try {
                if (!$date = $this->convert($value, $type)) {
                    continue;
                }

                $this->configureStringFormat($date);

                $this->owner->$attribute = $date;
            } catch (\InvalidArgumentException $e) {
                continue;
            } catch (\Exception $e) {
                continue;
            }
        }
    }

    /**
     * @author Walter Discher Cechinel <mistrim@gmail.com>
     *
     * @param Carbon $date
     */
    private function configureStringFormat(Carbon $date)
    {
        switch ($this->event->name) {
            case ActiveRecord::EVENT_AFTER_VALIDATE:
                if ($this->owner instanceof SearchInterface) {
                    $date->settings(['toStringFormat' => $this->toStringDateFormat]);
                }
                break;

            case ActiveRecord::EVENT_AFTER_FIND:
                $date->settings(['toStringFormat' => $this->toStringDateFormat]);
                break;

            case ActiveRecord::EVENT_AFTER_INSERT:
                $date->settings(['toStringFormat' => $this->toStringDateFormat]);
                break;

            case ActiveRecord::EVENT_AFTER_UPDATE:
                $date->settings(['toStringFormat' => $this->toStringDateFormat]);
                break;

            default:
                $date->settings(['toStringFormat' => $this->dateFormat]);
                break;
        }
    }

    /**
     * @author Walter Discher Cechinel <mistrim@gmail.com>
     *
     * @param string $attribute
     *
     * @return string|null
     */
    private function getValue($attribute, $columnType)
    {
        if (!$value = $this->owner->getAttribute($attribute)) {
            $value = $this->owner->$attribute ?? null;
        }

        if (!$value) {
            return null;
        }

        if ($this->event->name != ActiveRecord::EVENT_BEFORE_UPDATE) {
            return $value;
        }

        $value = trim($value);
        $valueLen = strlen($value);

        $oldValue = $this->owner->getOldAttribute($attribute);
        if ($oldValue && (strlen($oldValue) > 16) && $columnType == 'datetime' && $valueLen < 16) {
            $extract = explode(' ', $oldValue);

            $value = trim($value . ' ' . $extract[1]);
        }

        return $value;
    }

    /**
     * Check table schema for date formats and return a list of
     * date attributes to auto convert to carbon
     *
     * @author Walter Discher Cechinel <mistrim@gmail.com>
     *
     * @param array $columns
     *
     * @return array
     */
    private function prepareAutoConvertAttributes($columns)
    {
        $attributes = array_flip($this->attributes);

        foreach ($columns as $column) {
            if (!in_array($column->type, static::DATABASE_FORMATS)) {
                continue;
            }

            if (isset($attributes[$column->name])) {
                unset($attributes[$column->name]);
            }

            $attributes[$column->name] = $column->type;
        }

        return $attributes;
    }

    /**
     * @author Walter Discher Cechinel <mistrim@gmail.com>
     *
     * @param string $value field value
     * @param string $type  date type
     *
     * @return Carbon
     */
    protected function convert($value, $columnType)
    {
        foreach ($this->dateFormatPatterns as $pattern => $type) {
            try {
                $dateCarbon = Carbon::createFromFormat($pattern, $value);
                if ($type == 'date') {
                    $dateCarbon->startOfDay();
                }

                return $dateCarbon;
            } catch (\InvalidArgumentException $e) {
                continue;
            } catch (\Exception $e) {
                continue;
            }
        }

        return null;
    }

    /**
     * @author Walter Discher Cechinel <mistrim@gmail.com>
     *
     * @param string $name
     *
     * @return \yii\db\ColumnSchema|boolean
     */
    protected function getColumn($name)
    {
        if (!isset($this->columns[$name])) {
            return false;
        }

        return $this->columns[$name];
    }

}
