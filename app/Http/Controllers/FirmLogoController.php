<?php

namespace App\Http\Controllers;

use App\Services\SettingsService;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sert le logo du cabinet depuis le disque privé, aux seuls utilisateurs connectés.
 */
class FirmLogoController extends Controller
{
    public function __invoke(SettingsService $settings): Response
    {
        $path = $settings->get('firm.logo_path');

        abort_if($path === null || ! Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->response($path, null, ['Cache-Control' => 'private, max-age=3600']);
    }
}
