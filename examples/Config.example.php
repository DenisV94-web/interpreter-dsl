<?php

/**
 * Примеры конфигураций интерпретатора (нейтральные, без продакшен-данных)
 * 
 * demo_lead — итерационный режим: extra, query, mapping, execute
 *             (skip, method-условие, response-only, mapping-placeholder)
 * demo_card — одиночный режим: static, query, именованный маппинг, compose
 * 
 * Используются в examples/runner.php и в документации (docs/).
 */

return [
    // ========================================================
    // DEMO_LEAD: итерационная обработка заявок
    // ========================================================
    'demo_lead' => [
        'logging' => false,

        'request' => [
            'main' => 'post',
            'array' => 'lead',                       // итерационный режим

            // Кастомный порядок шагов (демо request_logic)
            'request_logic' => ['extra', 'query'],

            'extra' => [
                // 'contact_101' → ['contact', '101']
                'client_type' => [
                    'method' => 'explode',
                    'params' => ['_', 'field:client_string'],
                    'element' => 0,
                ],
                'client_id' => [
                    'method' => 'explode',
                    'params' => ['_', 'field:client_string'],
                    'element' => 1,
                ],
                'date_now' => ['method' => 'date', 'params' => ['d.m.Y H:i:s']],
            ],

            'query' => [
                // Клиент из каталога (только если client_id не пуст)
                'CLIENT' => [
                    'method' => 'getById',
                    'class' => \Examples\DemoService::class,
                    'params' => ['field:client_id'],
                    'conditions' => ['!field:client_id' => 'func:empty'],
                ],
            ],
        ],

        'mapping' => [
            // Цепочка альтернатив: имя контакта или титул компании
            'CLIENT_NAME' => 'field:CLIENT.LAST_NAME|field:CLIENT.TITLE',
            'PHONE' => 'field:CLIENT.PHONE|field:backup_phone',
            // Шаблон {{ }}: склейка ФИО
            'FULL_NAME' => '{{CLIENT.LAST_NAME}} {{CLIENT.NAME}}',
            'CLIENT_TYPE' => 'field:client_type',
            'STATUS' => 'NEW',
        ],

        'execute' => [
            // 1. Пустая входная строка → пропуск итерации
            [
                'check' => 'if',
                'filter' => ['field:client_string' => 'func:empty'],
                'actions' => [['skip' => true]],
            ],
            // 2. Неактивный центр → пропуск (статический метод как условие)
            [
                'check' => 'elseif',
                'method' => 'isUnactive',
                'class' => \Examples\DemoService::class,
                'params' => ['field:dealer_center_id'],
                'actions' => [['skip' => true]],
            ],
            // 3. Заблокированный клиент → ошибка с task_id БЕЗ создания
            //    (response-only действие: без method)
            [
                'check' => 'elseif',
                'filter' => ['field:client_type' => 'blocked'],
                'actions' => [
                    [
                        'response' => [
                            'task_id' => 'field:task_id',
                            'error' => 'Клиент заблокирован: лид не создан',
                        ],
                    ],
                ],
            ],
            // 4. Иначе — создание лида из маппинга
            [
                'check' => 'else',
                'actions' => [
                    [
                        'method' => 'add',
                        'class' => \Examples\DemoService::class,
                        'params' => ['mapping'],       // весь маппинг одним параметром
                        'response' => [
                            'task_id' => 'field:task_id',
                            'lead_id' => 'result',
                            'client_name' => 'field:FULL_NAME',
                            'phone' => 'field:PHONE',
                            'created_at' => 'field:date_now',
                        ],
                    ],
                ],
            ],
        ],

        'transaction' => ['enabled' => true, 'mode' => 'partial'],
        'action_logic' => ['request', 'mapping', 'execute'],
    ],

    // ========================================================
    // DEMO_CARD: одиночный режим со справочниками и compose
    // ========================================================
    'demo_card' => [
        'logging' => false,

        'request' => [
            'main' => 'get',

            'static' => [
                // Сырой массив-справочник
                'business_lines' => ['NEW_CAR' => 'Новый автомобиль', 'SERVICE' => 'Сервис'],
                // Загрузка через статический метод
                'brands' => '\Examples\DemoService::getBrandList',
            ],

            'query' => [
                'current_user' => [
                    'method' => 'getCurrentUser',
                    'class' => \Examples\DemoService::class,
                ],
                // field: ВНУТРИ вложенного массива параметров
                'tasks' => [
                    'method' => 'getList',
                    'class' => \Examples\DemoService::class,
                    'params' => [['user_id' => 'field:current_user.ID']],
                ],
            ],
        ],

        'mapping' => [
            // Именованный списочный маппинг: трансформация каждой строки
            'mapped_tasks' => [
                'source' => 'tasks',
                'mapping' => [
                    'id' => 'field:tasks.ID',
                    'title' => 'field:tasks.TITLE',
                ],
            ],
        ],

        'compose' => [
            'user' => ['id' => 'field:current_user.ID', 'name' => 'field:current_user.NAME'],
            'brands' => 'field:brands',
            'business_lines' => 'field:business_lines',
            'tabs' => [
                'all' => ['tab-name' => 'all', 'tasks' => 'field:mapped_tasks'],
            ],
        ],

        'action_logic' => ['request', 'mapping', 'compose'],
    ],
];
