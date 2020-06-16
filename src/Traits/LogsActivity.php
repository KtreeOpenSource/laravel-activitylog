<?php

namespace Spatie\Activitylog\Traits;

use Illuminate\Support\Collection;
use Spatie\Activitylog\ActivityLogger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\ActivitylogServiceProvider;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use App\Http\Controllers\Controller;

trait LogsActivity
{
    use DetectsChanges;

    protected $enableLoggingModelsEvents = true;

    public static function bootLogsActivity()
    {

        static::eventsToBeRecorded()->each(function ($eventName) {

            return static::$eventName(function (Model $model) use ($eventName) {
                if (! $model->shouldLogEvent($eventName)) {
                    return;
                }

                $description = $model->getDescriptionForEvent($eventName, $model);

                $entityName  =  $model->getEntityForEvent($model);

                $logName = $model->getLogNameToUse($eventName);

                if ($description == '') {
                    return;
                }

                app(ActivityLogger::class)
                    ->useLog($logName)
                  //  ->causedBy((new Controller)->getCauseUser())
                    ->performedOn($model)
                    ->setEntity($entityName)
                    ->withProperties($model->attributeValuesToBeLogged($eventName))
                    ->log($description);
            });
        });
    }

    public function disableLogging()
    {
        $this->enableLoggingModelsEvents = false;

        return $this;
    }

    public function enableLogging()
    {
        $this->enableLoggingModelsEvents = true;

        return $this;
    }

    public function activity(): MorphMany
    {
        return $this->morphMany(ActivitylogServiceProvider::determineActivityModel(), 'subject');
    }

    public function getDescriptionForEvent(string $eventName, $model): string
    {

        return $eventName;
    }

    public function getEntityForEvent($model): string
    {
        return $model;
    }

    public function getLogNameToUse(string $eventName = ''): string
    {
        return config('activitylog.default_log_name');
    }



    /*
     * Get the event names that should be recorded.
     */
    protected static function eventsToBeRecorded(): Collection
    {
        if (isset(static::$recordEvents)) {
            return collect(static::$recordEvents);
        }

        $events = collect([
            'created',
            'updated',
            'deleted',
        ]);

        if (collect(class_uses_recursive(__CLASS__))->contains(SoftDeletes::class)) {
            $events->push('restored');
        }
        return $events;
    }

    public function attributesToBeIgnored(): array
    {
        if (! isset(static::$ignoreChangedAttributes)) {
            return [];
        }

        return static::$ignoreChangedAttributes;
    }

    protected function shouldLogEvent(string $eventName): bool
    {
        if (! $this->enableLoggingModelsEvents) {
            return false;
        }

        if (! in_array($eventName, ['created', 'updated'])) {
            return true;
        }

        if (array_has($this->getDirty(), 'deleted_at')) {
            if ($this->getDirty()['deleted_at'] === null) {
                return false;
            }
        }

        //do not log update event if only ignored attributes are changed
        return (bool) count(array_except($this->getDirty(), $this->attributesToBeIgnored()));
    }
}
