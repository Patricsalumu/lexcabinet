<?php

namespace App\Enums;

/**
 * Types de lignes (FAC-02, FAC-04).
 * - La réduction ne porte que sur les honoraires (FAC-05).
 * - La TVA ne porte jamais sur les débours (FAC-08). Les frais de greffe et de transport,
 *   avancés pour le client, sont traités comme des débours (à confirmer avec le cabinet).
 */
enum InvoiceLineType: string
{
    case Fee = 'fee';
    case Consultation = 'consultation';
    case Disbursement = 'disbursement';
    case CourtFee = 'court_fee';
    case Transport = 'transport';
    case FileFee = 'file_fee';

    public function label(): string
    {
        return match ($this) {
            self::Fee => 'Honoraires',
            self::Consultation => 'Consultation',
            self::Disbursement => 'Débours',
            self::CourtFee => 'Frais de greffe',
            self::Transport => 'Transport',
            self::FileFee => 'Frais de dossier',
        };
    }

    /** Ligne d'honoraires : concernée par la réduction. */
    public function isFee(): bool
    {
        return in_array($this, [self::Fee, self::Consultation], true);
    }

    public function isTaxable(): bool
    {
        return in_array($this, [self::Fee, self::Consultation, self::FileFee], true);
    }

    /** Types saisissables à la main (lignes complémentaires, FAC-04). */
    public static function manualTypes(): array
    {
        return [self::Disbursement, self::CourtFee, self::Transport, self::FileFee];
    }
}
