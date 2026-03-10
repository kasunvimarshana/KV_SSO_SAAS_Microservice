<?php

return [
    'product_service' => [
        'url' => env('PRODUCT_SERVICE_URL', 'http://product-service/api'),
    ],
    'inventory_service' => [
        'url' => env('INVENTORY_SERVICE_URL', 'http://inventory-service/api'),
    ],
    'user_service' => [
        'url' => env('USER_SERVICE_URL', 'http://user-service/api'),
    ],
];
