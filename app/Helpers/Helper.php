<?php

function apiAuth(){
    return request()->header('X-API-KEY') === config('app.api_key');
}
