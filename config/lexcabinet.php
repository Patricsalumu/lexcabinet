<?php

return [

    /*
    | Double authentification (TOTP) obligatoire pour les administrateurs
    | (cahier des charges, section 4). Ne la désactiver qu'en local.
    */
    'admin_two_factor' => (bool) env('ADMIN_TWO_FACTOR', true),

    /*
    | Durée de validité du cache des paramètres du cabinet (secondes).
    */
    'settings_cache_ttl' => 3600,

];
