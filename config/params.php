<?php

return [
    'jwtSecret' => $_ENV['JWT_SECRET'] ?? 'fallback_dev_secret',
    'jwtAlgorithm' => $_ENV['JWT_ALGORITHM'] ?? 'HS256',
    'jwtExpire' => (int) ($_ENV['JWT_EXPIRE'] ?? 86400),
    'internalServiceKey' => $_ENV['INTERNAL_SERVICE_KEY'] ?? '',
    'nodeServiceUrl' => $_ENV['NODE_SERVICE_URL'] ?? 'http://127.0.0.1:3001',
];