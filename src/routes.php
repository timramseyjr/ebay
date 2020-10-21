<?php

Route::any('/ebay/auth/success','timramseyjr\Ebay\Controllers\EbayController@authSuccess');
Route::any('/ebay/auth/failure','timramseyjr\Ebay\Controllers\EbayController@authFailure');
Route::get('/ebay/auth/redirect','timramseyjr\Ebay\Controllers\EbayController@redirectToEbay');
Route::prefix('api')->group(function () {
    Route::group(['middleware' => ['api']], function () {
        Route::get('/ebay/config','timramseyjr\Ebay\Controllers\EbayController@ebayCredentials');
    });
});