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

    /*
    | Conversations par e-mail : lecture des réponses dans la boîte IMAP du cabinet
    | (accès dans config/imap.php, variables IMAP_*). La boîte est lue sans rien y changer :
    | aucun message n'est supprimé, déplacé ni marqué comme lu.
    */
    'replies' => [
        // Dossiers relevés (séparés par des virgules).
        'folders' => array_values(array_filter(array_map('trim', explode(',', (string) env('IMAP_FOLDERS', 'INBOX'))))),
        // Copie des réponses envoyées depuis l'application ; vide pour ne pas copier.
        'sent_folder' => env('IMAP_SENT_FOLDER', 'Sent'),
        // Première relève : jours d'historique examinés.
        'initial_days' => 14,
        // Taille maximale conservée par pièce jointe (octets).
        'max_attachment_size' => 20 * 1024 * 1024,
    ],

];
