<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Health-check service document (dipakai uptime monitor / reverse proxy).
// Endpoint ini sengaja tidak memakai health bawaan Laravel supaya bisa
// diakses tanpa dependensi apa pun dan mengembalikan teks "ok" saja.
Route::get('/up', function () {
    return 'ok';
});
