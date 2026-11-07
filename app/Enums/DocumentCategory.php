<?php

namespace App\Enums;

/**
 * Catégories de pièces jointes (DOS-06). Liste proposée, à valider avec le cabinet.
 */
enum DocumentCategory: string
{
    case Procedure = 'procedure';
    case Evidence = 'evidence';
    case Contract = 'contract';
    case Correspondence = 'correspondence';
    case Identity = 'identity';
    case Decision = 'decision';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Procedure => 'Acte de procédure',
            self::Evidence => 'Pièce justificative',
            self::Contract => 'Contrat, convention',
            self::Correspondence => 'Correspondance',
            self::Identity => 'Pièce d\'identité, statuts',
            self::Decision => 'Décision de justice',
            self::Other => 'Autre',
        };
    }
}
