<?php

namespace App\Http\Resources\AppApi;

/** The notification inbox, newest first. */
class NotificationCollection extends CursorCollection
{
    public $collects = NotificationResource::class;
}
