<?php

return [

    /*
    | Double authentification (TOTP) : facultative par défaut, chaque utilisateur
    | l'active depuis son menu. Mettre ADMIN_TWO_FACTOR=true pour l'imposer
    | aux administrateurs (cahier des charges, section 4).
    */
    'admin_two_factor' => (bool) env('ADMIN_TWO_FACTOR', false),

    /*
    | Durée de validité du cache des paramètres du cabinet (secondes).
    */
    'settings_cache_ttl' => 3600,

];
