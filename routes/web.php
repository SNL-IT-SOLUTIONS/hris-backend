<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/

Route::get('/', function () {
    return view('welcome');
});

Route::get('/dashboard', function () {
    return view('dashboard');
});
Route::get('/test-face', function () {
    return view('test-face');
});

use Rats\Zkteco\Lib\ZKTeco;

Route::get('/zk-test', function () {
    $zk = new ZKTeco('192.168.100.201');
    if (!$zk->connect()) {
        return 'Unable to connect to ZKTeco.';
    }
    return response()->json($zk->getAttendance());
});


Route::get('/zk-time-sync', function () {

    $zk = new ZKTeco('192.168.100.201');

    if (!$zk->connect()) {
        return 'Unable to connect.';
    }

    $zk->setTime(date('Y-m-d H:i:s'));

    return 'Device time updated successfully!';
});

Route::get('/zk-get-time', function () {

    $zk = new \Rats\Zkteco\Lib\ZKTeco('192.168.100.201');

    if (!$zk->connect()) {
        return 'Cannot connect';
    }

    return $zk->getTime();
});
