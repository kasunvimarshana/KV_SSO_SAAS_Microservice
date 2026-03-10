<?php

return [
    'secret' => env('JWT_SECRET', 'default_secret_change_in_production'),
    'ttl'    => env('JWT_TTL', 1440), // minutes
    'algo'   => 'HS256',
];
