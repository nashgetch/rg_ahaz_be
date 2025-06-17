<?php

return [
    'tiers' => [
        'DEMIGOD' => [
            'label' => 'Emperor',
            'icon' => 'demigod',
            'rank_from' => 1,
            'rank_to' => 1,
            'scope' => 'global'
        ],
        'NIGUS' => [
            'label' => 'Nigus',
            'icon' => 'crown',
            'rank_from' => 1,
            'rank_to' => 1,
            'scope' => 'game'
        ],
        'RAS' => [
            'label' => 'Ras',
            'icon' => 'key',
            'rank_from' => 2,
            'rank_to' => 6,
            'scope' => 'game'
        ],
        'FITAWRARI' => [
            'label' => 'Fitawrari',
            'icon' => 'sword',
            'rank_from' => 7,
            'rank_to' => 21,
            'scope' => 'game'
        ],
    ],
    
    'precedence' => [
        'DEMIGOD',
        'NIGUS', 
        'RAS',
        'FITAWRARI'
    ]
]; 