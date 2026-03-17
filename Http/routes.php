<?php

Route::group(['middleware' => ['web', 'auth']], function () {
    Route::get('/hexaweb/activity-log', 'Modules\HexawebBaseTools\Http\Controllers\ActivityLogController@index')
        ->name('hexaweb.activity_log');
});
